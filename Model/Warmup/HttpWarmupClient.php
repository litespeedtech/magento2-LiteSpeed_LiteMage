<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;

class HttpWarmupClient
{
    private const MAX_REDIRECTS = 3;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var RequestProfileBuilder
     */
    private $requestProfileBuilder;

    /**
     * @var CustomerGroupContextSigner
     */
    private $signer;

    /**
     * @var string[]
     */
    private $cookieFiles = [];

    public function __construct(
        CurlFactory $curlFactory,
        Config $config,
        StoreManagerInterface $storeManager,
        RequestProfileBuilder $requestProfileBuilder,
        CustomerGroupContextSigner $signer
    ) {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->requestProfileBuilder = $requestProfileBuilder;
        $this->signer = $signer;
    }

    public function __destruct()
    {
        foreach ($this->cookieFiles as $cookieFile) {
            if (is_string($cookieFile) && $cookieFile !== '' && file_exists($cookieFile)) {
                @unlink($cookieFile);
            }
        }
    }

    public function warm(array $queueRow, array $profile = [])
    {
        $originalUrl = $queueRow['url'];
        $request = $this->buildRequest($originalUrl, $queueRow['mode'], $profile, $queueRow['store_id'] ?? null);
        $currentUrl = $originalUrl;
        $redirects = [];
        $started = microtime(true);

        try {
            for ($redirectCount = 0; $redirectCount <= self::MAX_REDIRECTS; $redirectCount++) {
                $response = $this->request($currentUrl, $request);
                $headers = $response['headers'];
                $httpStatus = $response['status'];

                if (!$this->isRedirect($httpStatus)) {
                    $status = $this->classifyStatus($httpStatus);
                    return $this->buildResult(
                        $status,
                        $httpStatus,
                        $started,
                        $headers,
                        $currentUrl,
                        $redirects,
                        ($status === QueueStatus::STATUS_FAILED || $status === QueueStatus::STATUS_SKIPPED)
                            ? $this->buildStatusMessage($httpStatus, $headers)
                            : null
                    );
                }

                $location = $this->getHeader($headers, 'Location');
                if (!$location) {
                    return $this->buildResult(
                        QueueStatus::STATUS_SKIPPED,
                        $httpStatus,
                        $started,
                        $headers,
                        $currentUrl,
                        $redirects,
                        sprintf('Unexpected HTTP %d redirect without Location header.', $httpStatus)
                    );
                }

                $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
                if (!$this->isSameSiteUrl($originalUrl, $nextUrl)) {
                    return $this->buildResult(
                        QueueStatus::STATUS_SKIPPED,
                        $httpStatus,
                        $started,
                        $headers,
                        $currentUrl,
                        $redirects,
                        sprintf('Skipped cross-site redirect to %s.', $nextUrl)
                    );
                }

                $redirects[] = sprintf('%d %s', $httpStatus, $nextUrl);
                if ($redirectCount === self::MAX_REDIRECTS) {
                    return $this->buildResult(
                        QueueStatus::STATUS_SKIPPED,
                        $httpStatus,
                        $started,
                        $headers,
                        $currentUrl,
                        $redirects,
                        sprintf('Redirect limit of %d exceeded.', self::MAX_REDIRECTS)
                    );
                }
                $currentUrl = $nextUrl;
            }
        } catch (\Exception $e) {
            return [
                'status' => QueueStatus::STATUS_FAILED,
                'http_status' => null,
                'response_time_ms' => (int)round((microtime(true) - $started) * 1000),
                'cache_status' => null,
                'final_url' => $currentUrl,
                'headers_summary' => null,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'status' => QueueStatus::STATUS_FAILED,
            'http_status' => null,
            'response_time_ms' => (int)round((microtime(true) - $started) * 1000),
            'cache_status' => null,
            'final_url' => $currentUrl,
            'headers_summary' => null,
            'error' => 'Warmup request ended unexpectedly.',
        ];
    }

    public function warmBatch(array $queueRows, $concurrency, array $profile = [])
    {
        $concurrency = max(1, (int)$concurrency);
        if ($concurrency === 1 || count($queueRows) <= 1) {
            $results = [];
            foreach ($queueRows as $row) {
                $results[$row['queue_id']] = $this->warm($row, $row['_profile'] ?? $profile);
            }
            return $results;
        }

        $pending = array_values($queueRows);
        $multiHandle = curl_multi_init();
        $active = [];
        $results = [];

        try {
            while ($pending || $active) {
                while ($pending && count($active) < $concurrency) {
                    $row = array_shift($pending);
                    $state = $this->createBatchState($row, $profile);
                    $this->addBatchHandle($multiHandle, $active, $state);
                }

                do {
                    $status = curl_multi_exec($multiHandle, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                while ($info = curl_multi_info_read($multiHandle)) {
                    $handle = $info['handle'];
                    $key = $this->getCurlHandleKey($handle);
                    if (!isset($active[$key])) {
                        curl_multi_remove_handle($multiHandle, $handle);
                        curl_close($handle);
                        continue;
                    }

                    $state = $active[$key];
                    unset($active[$key]);
                    curl_multi_remove_handle($multiHandle, $handle);
                    $result = $this->finalizeBatchHandle($handle, $state, $info['result']);
                    curl_close($handle);

                    if (!empty($result['redirect_state'])) {
                        $this->addBatchHandle($multiHandle, $active, $result['redirect_state']);
                    } else {
                        $results[$state['row']['queue_id']] = $result;
                    }
                }

                if ($running && $active) {
                    curl_multi_select($multiHandle, 1.0);
                }
            }
        } catch (\Exception $e) {
            foreach ($active as $state) {
                curl_multi_remove_handle($multiHandle, $state['handle']);
                curl_close($state['handle']);
                $results[$state['row']['queue_id']] = [
                    'status' => QueueStatus::STATUS_FAILED,
                    'http_status' => null,
                    'response_time_ms' => (int)round((microtime(true) - $state['started']) * 1000),
                    'cache_status' => null,
                    'final_url' => $state['current_url'],
                    'headers_summary' => null,
                    'error' => $e->getMessage(),
                ];
            }
        } finally {
            curl_multi_close($multiHandle);
        }

        return $results;
    }

    public function buildCurlCommand($url, $mode, array $profile = [], $storeId = null, $includeSensitive = false)
    {
        if (!$includeSensitive && !empty($profile['customer_id']) && !empty($profile['customer_session'])) {
            throw new \RuntimeException(
                'cURL commands for representative customer session profiles are not shown in admin because they contain a signed login token.'
            );
        }

        $request = $this->requestProfileBuilder->build($mode, $profile);
        $this->applyStoreCookie($request, $storeId);
        $target = $this->buildTargetUrl($url);
        $parts = parse_url($url);
        $cookieFile = null;
        $command = [
            'curl',
            '-i',
            '--max-time',
            (string)$this->config->getWarmupRequestTimeout(),
            '--proto',
            '=http,https',
        ];

        foreach ($target['resolve'] as $resolve) {
            $command[] = '--resolve';
            $command[] = $resolve;
        }
        foreach ($request['headers'] as $name => $value) {
            $command[] = '-H';
            $command[] = $name . ': ' . $value;
        }
        foreach ($request['cookies'] as $name => $value) {
            $command[] = '--cookie';
            $command[] = $name . '=' . $value;
        }
        if (!empty($profile['customer_id']) && !empty($profile['customer_session'])) {
            $cookieFile = sprintf(
                '/tmp/litemage-warm-customer-%d-store-%d.cookies',
                (int)$profile['customer_id'],
                $storeId === null || $storeId === '' ? 0 : (int)$storeId
            );
            $command[] = '--cookie';
            $command[] = $cookieFile;
            $command[] = '--cookie-jar';
            $command[] = $cookieFile;
        }
        if ($auth = $this->config->getBasicAuth()) {
            $command[] = '-u';
            $command[] = $includeSensitive ? $auth : $this->redactBasicAuth($auth);
        }
        if (!empty($parts['host'])) {
            $command[] = '--';
        }
        $command[] = $target['url'];

        $command = implode(' ', array_map([$this, 'escapeShellArg'], $command));
        if ($cookieFile === null) {
            return $command;
        }

        return $this->buildLoginCurlCommand($url, (int)$profile['customer_id'], $request, $cookieFile, $includeSensitive)
            . ' && ' . $command;
    }

    private function request($url, array $request)
    {
        $headers = [];
        $handle = $this->createCurlHandle($url, $request, $headers);
        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException($error);
        }
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'headers' => $headers,
            'status' => $status,
        ];
    }

    private function createBatchState(array $queueRow, array $profile)
    {
        return [
            'row' => $queueRow,
            'request' => $this->buildRequest(
                $queueRow['url'],
                $queueRow['mode'],
                $queueRow['_profile'] ?? $profile,
                $queueRow['store_id'] ?? null
            ),
            'original_url' => $queueRow['url'],
            'current_url' => $queueRow['url'],
            'redirects' => [],
            'redirect_count' => 0,
            'headers' => [],
            'started' => microtime(true),
            'handle' => null,
        ];
    }

    private function addBatchHandle($multiHandle, array &$active, array $state)
    {
        $headers = [];
        $handle = $this->createCurlHandle($state['current_url'], $state['request'], $headers);
        $state['headers'] = &$headers;
        $state['handle'] = $handle;
        $active[$this->getCurlHandleKey($handle)] = $state;
        curl_multi_add_handle($multiHandle, $handle);
    }

    private function getCurlHandleKey($handle)
    {
        return is_object($handle) ? spl_object_id($handle) : (int)$handle;
    }

    private function createCurlHandle($url, array $request, array &$headers)
    {
        $target = $this->buildTargetUrl($url);
        $handle = curl_init($target['url']);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->config->getWarmupRequestTimeout());
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_MAXREDIRS, 0);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($handle, $body) {
            return strlen($body);
        });
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$headers) {
            $length = strlen($line);
            $line = trim($line);
            if ($line !== '' && strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
            return $length;
        });
        if (!empty($target['resolve'])) {
            curl_setopt($handle, CURLOPT_RESOLVE, $target['resolve']);
        }
        if (!empty($request['cookie_file'])) {
            curl_setopt($handle, CURLOPT_COOKIEFILE, $request['cookie_file']);
            curl_setopt($handle, CURLOPT_COOKIEJAR, $request['cookie_file']);
        }
        if (!empty($request['headers'])) {
            $headerLines = [];
            foreach ($request['headers'] as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
        }
        if (!empty($request['cookies'])) {
            curl_setopt($handle, CURLOPT_COOKIE, $this->formatCookieHeader($request['cookies']));
        }
        if ($auth = $this->config->getBasicAuth()) {
            curl_setopt($handle, CURLOPT_USERPWD, $auth);
        }

        return $handle;
    }

    private function finalizeBatchHandle($handle, array $state, $curlResult)
    {
        if ($curlResult !== CURLE_OK) {
            return [
                'status' => QueueStatus::STATUS_FAILED,
                'http_status' => null,
                'response_time_ms' => (int)round((microtime(true) - $state['started']) * 1000),
                'cache_status' => null,
                'final_url' => $state['current_url'],
                'headers_summary' => null,
                'error' => curl_error($handle),
            ];
        }

        $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headers = $state['headers'];
        if (!$this->isRedirect($httpStatus)) {
            $status = $this->classifyStatus($httpStatus);
            return $this->buildResult(
                $status,
                $httpStatus,
                $state['started'],
                $headers,
                $state['current_url'],
                $state['redirects'],
                ($status === QueueStatus::STATUS_FAILED || $status === QueueStatus::STATUS_SKIPPED)
                    ? $this->buildStatusMessage($httpStatus, $headers)
                    : null
            );
        }

        $location = $this->getHeader($headers, 'Location');
        if (!$location) {
            return $this->buildResult(
                QueueStatus::STATUS_SKIPPED,
                $httpStatus,
                $state['started'],
                $headers,
                $state['current_url'],
                $state['redirects'],
                sprintf('Unexpected HTTP %d redirect without Location header.', $httpStatus)
            );
        }

        $nextUrl = $this->resolveRedirectUrl($state['current_url'], $location);
        if (!$this->isSameSiteUrl($state['original_url'], $nextUrl)) {
            return $this->buildResult(
                QueueStatus::STATUS_SKIPPED,
                $httpStatus,
                $state['started'],
                $headers,
                $state['current_url'],
                $state['redirects'],
                sprintf('Skipped cross-site redirect to %s.', $nextUrl)
            );
        }

        $state['redirects'][] = sprintf('%d %s', $httpStatus, $nextUrl);
        if ($state['redirect_count'] >= self::MAX_REDIRECTS) {
            return $this->buildResult(
                QueueStatus::STATUS_SKIPPED,
                $httpStatus,
                $state['started'],
                $headers,
                $state['current_url'],
                $state['redirects'],
                sprintf('Redirect limit of %d exceeded.', self::MAX_REDIRECTS)
            );
        }

        $state['current_url'] = $nextUrl;
        $state['redirect_count']++;
        $state['headers'] = [];
        $state['redirect_state'] = $state;
        return $state;
    }

    private function buildTargetUrl($url)
    {
        $serverIp = $this->config->getServerIp();
        if (!$serverIp) {
            return ['url' => $url, 'resolve' => []];
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ['url' => $url, 'resolve' => []];
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($parts['scheme'] === 'https' ? 443 : 80);

        return [
            'url' => $url,
            'resolve' => [$parts['host'] . ':' . $port . ':' . $serverIp],
        ];
    }

    private function buildRequest($url, $mode, array $profile, $storeId = null)
    {
        $request = $this->requestProfileBuilder->build($mode, $profile);
        $this->applyStoreCookie($request, $storeId);
        if (!empty($profile['customer_id']) && !empty($profile['customer_session'])) {
            $request['cookie_file'] = $this->getCustomerCookieFile($url, $mode, $profile, $request, $storeId);
        }
        return $request;
    }

    private function applyStoreCookie(array &$request, $storeId = null)
    {
        if ($storeId === null || $storeId === '') {
            return;
        }

        $store = $this->storeManager->getStore((int)$storeId);
        if (!isset($request['cookies']['store'])) {
            $request['cookies']['store'] = $store->getCode();
        }
    }

    private function buildLoginCurlCommand($url, $customerId, array $request, $cookieFile, $includeSensitive)
    {
        $target = $this->buildTargetUrl($this->buildWarmupLoginUrl($url, $customerId));
        $command = [
            'curl',
            '-i',
            '--max-time',
            (string)$this->config->getWarmupRequestTimeout(),
            '--proto',
            '=http,https',
        ];
        foreach ($target['resolve'] as $resolve) {
            $command[] = '--resolve';
            $command[] = $resolve;
        }
        foreach ($request['headers'] as $name => $value) {
            $command[] = '-H';
            $command[] = $name . ': ' . $value;
        }
        foreach ($request['cookies'] as $name => $value) {
            $command[] = '--cookie';
            $command[] = $name . '=' . $value;
        }
        $command[] = '--cookie-jar';
        $command[] = $cookieFile;
        if ($auth = $this->config->getBasicAuth()) {
            $command[] = '-u';
            $command[] = $includeSensitive ? $auth : $this->redactBasicAuth($auth);
        }
        $command[] = '--';
        $command[] = $target['url'];

        return implode(' ', array_map([$this, 'escapeShellArg'], $command));
    }

    private function getCustomerCookieFile($url, $mode, array $profile, array $request, $storeId = null)
    {
        $parts = parse_url($url);
        $hostKey = is_array($parts) ? (($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ':' . ($parts['port'] ?? '')) : '';
        $storeKey = $storeId === null || $storeId === '' ? 0 : (int)$storeId;
        $key = sha1($hostKey . '|' . $mode . '|' . $storeKey . '|' . (int)$profile['customer_id']);
        if (!empty($this->cookieFiles[$key]) && file_exists($this->cookieFiles[$key])) {
            return $this->cookieFiles[$key];
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'litemage_warm_cookie_');
        if (!$cookieFile) {
            throw new \RuntimeException('Unable to create warmup customer cookie jar.');
        }

        $this->loginRepresentativeCustomer($url, (int)$profile['customer_id'], $request, $cookieFile);
        $this->cookieFiles[$key] = $cookieFile;
        return $cookieFile;
    }

    private function loginRepresentativeCustomer($url, $customerId, array $request, $cookieFile)
    {
        $loginUrl = $this->buildWarmupLoginUrl($url, $customerId);
        $target = $this->buildTargetUrl($loginUrl);
        $handle = curl_init($target['url']);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->config->getWarmupRequestTimeout());
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        if (!empty($target['resolve'])) {
            curl_setopt($handle, CURLOPT_RESOLVE, $target['resolve']);
        }
        if (!empty($request['headers'])) {
            $headerLines = [];
            foreach ($request['headers'] as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
        }
        if (!empty($request['cookies'])) {
            curl_setopt($handle, CURLOPT_COOKIE, $this->formatCookieHeader($request['cookies']));
        }
        if ($auth = $this->config->getBasicAuth()) {
            curl_setopt($handle, CURLOPT_USERPWD, $auth);
        }

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException('Warmup customer login failed: ' . $error);
        }

        $httpStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($httpStatus !== 200) {
            throw new \RuntimeException(sprintf('Warmup customer login for customer ID %d returned HTTP %d.', $customerId, $httpStatus));
        }
    }

    private function buildWarmupLoginUrl($url, $customerId)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Representative customer warmup requires an absolute URL.');
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . (int)$parts['port'];
        }

        return $base . '/litemage/warmup/login?' . http_build_query($this->signer->buildLoginParams($customerId));
    }

    private function formatCookieHeader(array $cookies)
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        return implode('; ', $parts);
    }

    private function escapeShellArg($value)
    {
        return preg_match('/^[A-Za-z0-9_\\.\\-\\/:=,]+$/', (string)$value) ? (string)$value : escapeshellarg((string)$value);
    }

    private function redactBasicAuth($auth)
    {
        [$user] = array_pad(explode(':', (string)$auth, 2), 2, '');
        return $user . ':********';
    }

    private function classifyStatus($httpStatus)
    {
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return QueueStatus::STATUS_WARMED;
        }
        if (($httpStatus >= 300 && $httpStatus < 400) || (int)$httpStatus === 404) {
            return QueueStatus::STATUS_SKIPPED;
        }
        return QueueStatus::STATUS_FAILED;
    }

    private function isRedirect($httpStatus)
    {
        return $httpStatus >= 300 && $httpStatus < 400;
    }

    private function buildResult($status, $httpStatus, $started, array $headers, $finalUrl, array $redirects, $error)
    {
        $cacheStatus = $this->detectCacheStatus($httpStatus, $headers);
        return [
            'status' => $status,
            'http_status' => $httpStatus,
            'response_time_ms' => (int)round((microtime(true) - $started) * 1000),
            'cache_status' => $cacheStatus,
            'final_url' => $finalUrl,
            'headers_summary' => $this->summarizeHeaders($headers, $redirects),
            'error' => $error,
        ];
    }

    private function detectCacheStatus($httpStatus, array $headers)
    {
        foreach (['X-LiteSpeed-Cache', 'X-LSADC-Cache'] as $headerName) {
            $cacheStatus = $this->getHeader($headers, $headerName);
            if ($cacheStatus !== null && trim((string)$cacheStatus) !== '') {
                return $cacheStatus;
            }
        }

        if ((int)$httpStatus === 201) {
            return 'miss';
        }

        return null;
    }

    private function buildStatusMessage($httpStatus, array $headers)
    {
        $location = $this->getHeader($headers, 'Location');
        if ($location) {
            return sprintf('Unexpected HTTP %d redirect to %s.', $httpStatus, $location);
        }
        return sprintf('Unexpected HTTP status %d.', $httpStatus);
    }

    private function summarizeHeaders(array $headers, array $redirects = [])
    {
        $summary = [];
        foreach ([
            'X-LiteSpeed-Cache',
            'X-LSADC-Cache',
            'X-LiteSpeed-Cache-Control',
            'X-LiteSpeed-Tag',
            'X-LiteSpeed-Vary',
            'Location',
        ] as $name) {
            $value = $this->getHeader($headers, $name);
            if ($value !== null) {
                $summary[$name] = $value;
            }
        }
        if ($redirects) {
            $summary['Redirect-Chain'] = implode(' -> ', $redirects);
        }
        return $summary ? json_encode($summary) : null;
    }

    private function resolveRedirectUrl($currentUrl, $location)
    {
        $location = trim((string)$location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $current = parse_url($currentUrl);
        if (!is_array($current) || empty($current['scheme']) || empty($current['host'])) {
            return $location;
        }

        if (strpos($location, '//') === 0) {
            return $current['scheme'] . ':' . $location;
        }

        $base = $current['scheme'] . '://' . $current['host'];
        if (isset($current['port'])) {
            $base .= ':' . (int)$current['port'];
        }
        if (strpos($location, '/') === 0) {
            return $base . $location;
        }

        $path = $current['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path);
        return $base . $this->normalizePath($dir . $location);
    }

    private function normalizePath($path)
    {
        $query = '';
        if (($queryPos = strpos($path, '?')) !== false) {
            $query = substr($path, $queryPos);
            $path = substr($path, 0, $queryPos);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . $query;
    }

    private function isSameSiteUrl($originalUrl, $nextUrl)
    {
        $original = parse_url($originalUrl);
        $next = parse_url($nextUrl);
        if (!is_array($original) || !is_array($next) || empty($original['host']) || empty($next['host'])) {
            return false;
        }

        return strcasecmp($original['host'], $next['host']) === 0;
    }

    private function getHeader(array $headers, $name)
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return is_array($value) ? implode('; ', $value) : (string)$value;
            }
        }
        return null;
    }
}
