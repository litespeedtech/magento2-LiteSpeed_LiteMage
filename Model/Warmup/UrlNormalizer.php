<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class UrlNormalizer
{
    private const DISALLOWED_PATH_PREFIXES = [
        'admin',
        'checkout',
        'customer',
        'cart',
        'wishlist',
        'catalogsearch',
        'review/customer',
    ];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    public function normalize($url, $storeId = null)
    {
        $url = trim((string)$url);
        if ($url === '') {
            throw new LocalizedException(__('Warmup URL cannot be empty.'));
        }

        if ($this->isRelativeUrl($url)) {
            if ($storeId === null) {
                throw new LocalizedException(__('Relative warmup URLs require an explicit store ID.'));
            }
            $store = $this->storeManager->getStore($storeId);
            $url = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/') . '/' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new LocalizedException(__('Invalid warmup URL "%1".', $url));
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new LocalizedException(__('Warmup URL "%1" must use HTTP or HTTPS.', $url));
        }

        $host = strtolower($parts['host']);
        $path = $this->normalizePath($parts['path'] ?? '/');
        $this->assertAllowedPath($path, $url);
        $query = $this->normalizeQuery($parts['query'] ?? '', $url);
        $storeId = $this->resolveStoreId($parts, $storeId);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $normalized = $scheme . '://' . $host . $port . $path . ($query === '' ? '' : '?' . $query);

        return [
            'url' => $normalized,
            'url_hash' => hash('sha256', $normalized),
            'store_id' => (int)$storeId,
            'host' => $host,
            'path' => $path,
        ];
    }

    private function isRelativeUrl($url)
    {
        return (strpos($url, '://') === false && strpos($url, '//') !== 0);
    }

    private function normalizePath($path)
    {
        $path = '/' . ltrim((string)$path, '/');
        $path = preg_replace('#/+#', '/', $path);
        return $path ?: '/';
    }

    private function assertAllowedPath($path, $originalUrl)
    {
        $decoded = rawurldecode((string)$path);
        if (preg_match('#(^|/)\.{1,2}(/|$)#', $decoded)) {
            throw new LocalizedException(__('Warmup URL "%1" uses a disallowed path.', $originalUrl));
        }
        $trimmed = strtolower(trim($decoded, '/'));
        foreach (self::DISALLOWED_PATH_PREFIXES as $prefix) {
            if ($trimmed === $prefix || strpos($trimmed, $prefix . '/') === 0) {
                throw new LocalizedException(__('Warmup URL "%1" uses a disallowed path.', $originalUrl));
            }
        }
    }

    private function normalizeQuery($query, $originalUrl)
    {
        $query = trim((string)$query);
        if ($query === '') {
            return '';
        }

        $allowedParams = $this->config->getWarmupAllowedQueryParams();
        if (!$allowedParams) {
            throw new LocalizedException(__(
                'Warmup URL "%1" contains query parameters. Configure allowed public query parameters or remove the query string.',
                $originalUrl
            ));
        }

        $allowed = array_fill_keys($allowedParams, true);
        parse_str($query, $params);
        if (!$params) {
            return '';
        }

        $normalized = [];
        $rejected = [];
        foreach ($params as $name => $value) {
            $name = (string)$name;
            if (!isset($allowed[$name])) {
                $rejected[] = $name;
                continue;
            }
            if (is_array($value)) {
                throw new LocalizedException(__(
                    'Warmup URL "%1" contains array query parameter "%2", which is not supported.',
                    $originalUrl,
                    $name
                ));
            }
            $normalized[$name] = (string)$value;
        }

        if ($rejected) {
            throw new LocalizedException(__(
                'Warmup URL "%1" contains query parameter(s) not allowed for warming: %2.',
                $originalUrl,
                implode(', ', $rejected)
            ));
        }

        ksort($normalized);
        return http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
    }

    private function resolveStoreId(array $parts, $storeId)
    {
        if ($storeId !== null && $storeId !== '') {
            $store = $this->storeManager->getStore($storeId);
            if (!$this->isStoreEndpointMatch($parts, $store)) {
                throw new LocalizedException(__(
                    'Warmup URL host or port does not match store %1.',
                    (int)$store->getId()
                ));
            }
            return (int)$store->getId();
        }

        foreach ($this->storeManager->getStores(false) as $store) {
            if ($this->isStoreEndpointMatch($parts, $store)) {
                return (int)$store->getId();
            }
        }

        throw new LocalizedException(__('Warmup URL host "%1" does not match a configured store.', strtolower($parts['host'] ?? '')));
    }

    private function isStoreEndpointMatch(array $parts, $store)
    {
        $host = strtolower((string)($parts['host'] ?? ''));
        $baseParts = parse_url($store->getBaseUrl(UrlInterface::URL_TYPE_LINK));
        $baseHost = is_array($baseParts) ? strtolower((string)($baseParts['host'] ?? '')) : '';
        if ($host === '' || $baseHost === '' || $host !== $baseHost) {
            return false;
        }

        return $this->isAllowedPort($parts, is_array($baseParts) ? $baseParts : []);
    }

    private function isAllowedPort(array $parts, array $baseParts)
    {
        $urlPort = isset($parts['port']) ? (int)$parts['port'] : null;
        $basePort = isset($baseParts['port']) ? (int)$baseParts['port'] : null;
        if ($basePort !== null) {
            return $urlPort === $basePort;
        }
        if ($urlPort === null) {
            return true;
        }

        return in_array($urlPort, [80, 443], true);
    }
}
