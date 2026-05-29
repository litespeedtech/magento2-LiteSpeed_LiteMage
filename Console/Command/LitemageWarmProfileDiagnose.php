<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\RequestProfileBuilder;
use Litespeed\Litemage\Model\Warmup\UrlNormalizer;
use Litespeed\Litemage\Model\Warmup\VaryProfileResolver;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmProfileDiagnose extends Command
{
    /**
     * @var UrlNormalizer
     */
    private $urlNormalizer;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var RequestProfileBuilder
     */
    private $requestProfileBuilder;

    /**
     * @var Config
     */
    private $config;

    public function __construct(
        UrlNormalizer $urlNormalizer,
        VaryProfileResolver $varyProfileResolver,
        RequestProfileBuilder $requestProfileBuilder,
        Config $config
    ) {
        $this->urlNormalizer = $urlNormalizer;
        $this->varyProfileResolver = $varyProfileResolver;
        $this->requestProfileBuilder = $requestProfileBuilder;
        $this->config = $config;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:profile:diagnose');
        $this->setDescription('Compare a warmup profile vary fingerprint with a sampled shopper request.');
        $this->addArgument('url', InputArgument::REQUIRED, 'URL to diagnose.');
        $this->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Store ID for relative URLs.');
        $this->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Warmup profile ID or code.');
        $this->addOption('shopper-cookie', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Shopper cookie as name=value. Can be repeated.');
        $this->addOption('shopper-header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Shopper header as Name: value. Can be repeated.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $normalized = $this->urlNormalizer->normalize($input->getArgument('url'), $input->getOption('store'));
            $profile = $this->varyProfileResolver->resolve($input->getOption('profile'));
            $warmupRequest = $this->requestProfileBuilder->build('walker', $profile);
            $shopperRequest = $this->buildShopperRequest($input);

            $warmup = $this->probe($normalized['url'], $warmupRequest);
            $shopper = $this->probe($normalized['url'], $shopperRequest);

            $this->writeProbe($output, 'Warmup profile', $warmup);
            $this->writeProbe($output, 'Shopper sample', $shopper);

            $warmupVary = $this->getVaryFingerprint($warmup);
            $shopperVary = $this->getVaryFingerprint($shopper);
            if ($warmupVary === $shopperVary) {
                $output->writeln('<info>Reachable: warmup and shopper vary fingerprints match.</info>');
                return Cli::RETURN_SUCCESS;
            }

            $output->writeln('<error>Not reachable: warmup and shopper vary fingerprints differ.</error>');
            return Cli::RETURN_FAILURE;
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function buildShopperRequest(InputInterface $input)
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 LiteMage-Diagnostic litemage_walker',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
        foreach ((array)$input->getOption('shopper-header') as $header) {
            [$name, $value] = array_pad(explode(':', (string)$header, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                throw new \InvalidArgumentException('Shopper header must use "Name: value" syntax.');
            }
            $headers[$name] = trim($value);
        }
        if (strpos($headers['User-Agent'], 'litemage_walker') === false) {
            $headers['User-Agent'] .= ' litemage_walker';
        }

        $cookies = [];
        foreach ((array)$input->getOption('shopper-cookie') as $cookie) {
            [$name, $value] = array_pad(explode('=', (string)$cookie, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                throw new \InvalidArgumentException('Shopper cookie must use name=value syntax.');
            }
            $cookies[$name] = $value;
        }

        return ['headers' => $headers, 'cookies' => $cookies];
    }

    private function probe($url, array $request)
    {
        $target = $this->buildTargetUrl($url);
        $headers = [];
        $handle = curl_init($target['url']);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->config->getWarmupRequestTimeout());
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$headers) {
            $length = strlen($line);
            $line = trim($line);
            if ($line !== '' && strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $headers[$name][] = trim($value);
            }
            return $length;
        });
        if (!empty($target['resolve'])) {
            curl_setopt($handle, CURLOPT_RESOLVE, $target['resolve']);
        }
        $headerLines = [];
        foreach ($request['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
        if (!empty($request['cookies'])) {
            $cookies = [];
            foreach ($request['cookies'] as $name => $value) {
                $cookies[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
            curl_setopt($handle, CURLOPT_COOKIE, implode('; ', $cookies));
        }
        if ($auth = $this->config->getBasicAuth()) {
            curl_setopt($handle, CURLOPT_USERPWD, $auth);
        }

        curl_exec($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'headers' => $headers,
            'error' => $error,
            'vary' => $this->extractSetCookieValue($headers, '_lscache_vary'),
            'magento_vary' => $this->extractSetCookieValue($headers, 'X-Magento-Vary'),
            'cache_control' => $this->firstHeader($headers, 'cache-control'),
            'litespeed_cache_control' => $this->firstHeader($headers, 'x-litespeed-cache-control'),
        ];
    }

    private function writeProbe(OutputInterface $output, $label, array $probe)
    {
        $output->writeln(sprintf(
            '%s: http=%d vary=%s magento_vary=%s cache_control=%s litespeed_cache_control=%s',
            $label,
            $probe['status'],
            $probe['vary'] ?: '-',
            $probe['magento_vary'] ?: '-',
            $probe['cache_control'] ?: '-',
            $probe['litespeed_cache_control'] ?: '-'
        ));
        if ($probe['error']) {
            $output->writeln('  error=' . $probe['error']);
        }
    }

    private function getVaryFingerprint(array $probe)
    {
        return $probe['vary'] ?: '';
    }

    private function extractSetCookieValue(array $headers, $cookieName)
    {
        foreach ($headers['set-cookie'] ?? [] as $line) {
            foreach (explode(';', $line) as $part) {
                [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($name === $cookieName) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function firstHeader(array $headers, $name)
    {
        return $headers[$name][0] ?? null;
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
}
