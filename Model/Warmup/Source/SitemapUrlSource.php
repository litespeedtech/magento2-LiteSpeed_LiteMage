<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup\Source;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class SitemapUrlSource
{
    private const LASTMOD_NORMAL_PRIORITY = 25;
    private const ALLOWED_VAR_PATH = 'var/litemage/warmup/';
    private const MAX_SITEMAP_BYTES = 52428800;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var array
     */
    private $lastStats = [];

    public function __construct(
        Config $config,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
    }

    public function collect(array $storeIds = [])
    {
        $this->resetStats();
        $items = [];
        $storeFilter = array_fill_keys(array_map('intval', $storeIds), true);
        $limit = $this->config->getWarmupQueueLimitPerStore() * max(1, $storeIds ? count($storeIds) : count($this->storeManager->getStores(false)));

        foreach ($this->config->getWarmupSitemapPaths() as $sourceIndex => $row) {
            $sourceLine = $sourceIndex + 1;
            $this->lastStats['source_rows']++;
            $source = $this->parseSourceRow($row, $sourceLine);
            if (!$source) {
                continue;
            }
            foreach ($this->readSitemap($source['path'], $sourceLine, [], $source['store_ids']) as $entry) {
                if (count($items) >= $limit) {
                    return $items;
                }
                $this->lastStats['rows_seen']++;
                $entryItems = [];
                $lastStoreError = null;
                $attemptedStores = 0;
                foreach ($source['store_ids'] as $storeId) {
                    if ($storeFilter && !isset($storeFilter[$storeId])) {
                        continue;
                    }
                    $attemptedStores++;
                    if (!$this->isUrlAllowedForStore($entry['url'], $storeId, $lastStoreError)) {
                        continue;
                    }
                    $entryItems[] = [
                        'url' => $entry['url'],
                        'store_id' => $storeId,
                        'page_type' => 'sitemap',
                        'source_instance_key' => $source['source_instance_key'],
                        'source_priority' => $source['source_priority'],
                        'url_priority' => $this->getPriority($entry['lastmod']),
                        'priority' => $this->getPriority($entry['lastmod']),
                    ];
                }
                if (!$entryItems) {
                    $this->lastStats['skipped']++;
                    if ($attemptedStores > 0) {
                        $this->recordError(sprintf(
                            'Sitemap source row %d URL "%s" does not match selected store domains%s.',
                            (int)$sourceLine,
                            $entry['url'],
                            $lastStoreError ? ': ' . $lastStoreError : ''
                        ));
                    }
                    continue;
                }
                $this->lastStats['generated'] += count($entryItems);
                foreach ($entryItems as $item) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    public function validate(array $storeIds = [])
    {
        $this->collect($storeIds);
        return $this->lastStats;
    }

    public function getLastStats()
    {
        return $this->lastStats ?: $this->emptyStats();
    }

    private function readSitemap($path, $sourceLine = null, array $seen = [], array $storeIds = [])
    {
        $path = trim((string)$path);
        if (isset($seen[$path])) {
            return [];
        }
        $seen[$path] = true;
        if (!$this->isRemoteSourceAllowedForStores($path, $storeIds)) {
            $this->recordError(sprintf(
                'Sitemap source row %d nested sitemap host or port does not match selected store domains: %s',
                (int)$sourceLine,
                $path
            ));
            return [];
        }

        $xml = $this->loadXml($path, $sourceLine);
        if ($xml === '') {
            return [];
        }

        $sitemap = @simplexml_load_string($xml);
        if (!$sitemap) {
            $this->recordError(sprintf('Sitemap source row %d could not parse XML from %s.', (int)$sourceLine, $path));
            return [];
        }

        $urls = [];
        foreach ($sitemap->url as $urlNode) {
            if (isset($urlNode->loc)) {
                $urls[] = [
                    'url' => trim((string)$urlNode->loc),
                    'lastmod' => isset($urlNode->lastmod) ? trim((string)$urlNode->lastmod) : null,
                ];
            }
        }

        foreach ($sitemap->sitemap as $sitemapNode) {
            if (isset($sitemapNode->loc)) {
                foreach ($this->readSitemap(trim((string)$sitemapNode->loc), $sourceLine, $seen, $storeIds) as $url) {
                    $urls[] = $url;
                }
            }
        }

        $deduped = [];
        foreach ($urls as $entry) {
            if (!empty($entry['url'])) {
                $deduped[$entry['url']] = $entry;
            }
        }

        return array_values($deduped);
    }

    private function loadXml($path, $sourceLine = null)
    {
        $path = trim((string)$path);
        if ($path === '') {
            $this->recordError(sprintf('Sitemap source row %d is missing a sitemap path.', (int)$sourceLine));
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $this->loadRemoteXml($path, $sourceLine);
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($relativePath, self::ALLOWED_VAR_PATH) !== 0) {
            $this->recordError(sprintf(
                'Sitemap source row %d local sitemap must be under %s: %s',
                (int)$sourceLine,
                self::ALLOWED_VAR_PATH,
                $path
            ));
            return '';
        }
        $file = $this->resolveLocalPath($relativePath);
        if (!$file || !is_file($file) || !is_readable($file)) {
            $this->recordError(sprintf('Sitemap source row %d local sitemap is not readable: %s', (int)$sourceLine, $path));
            return '';
        }
        if ($this->isFileTooLarge($file, self::MAX_SITEMAP_BYTES)) {
            $this->recordError(sprintf(
                'Sitemap source row %d local sitemap exceeds the %d MB file size limit: %s',
                (int)$sourceLine,
                (int)(self::MAX_SITEMAP_BYTES / 1048576),
                $path
            ));
            return '';
        }
        $data = @file_get_contents($file, false, null, 0, self::MAX_SITEMAP_BYTES + 1);
        if ($data === false) {
            $this->recordError(sprintf('Sitemap source row %d could not read local sitemap: %s', (int)$sourceLine, $path));
        } elseif ($this->isDataTooLarge($data, self::MAX_SITEMAP_BYTES)) {
            $this->recordError(sprintf(
                'Sitemap source row %d local sitemap exceeds the %d MB file size limit: %s',
                (int)$sourceLine,
                (int)(self::MAX_SITEMAP_BYTES / 1048576),
                $path
            ));
            return '';
        } else {
            $this->lastStats['files']++;
        }
        return $data === false ? '' : $data;
    }

    private function loadRemoteXml($path, $sourceLine)
    {
        $timeout = min(60, max(1, (int)$this->config->getWarmupRequestTimeout()));
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $data = @file_get_contents($path, false, $context, 0, self::MAX_SITEMAP_BYTES + 1);
        if ($data === false) {
            $this->recordError(sprintf('Sitemap source row %d could not read remote sitemap: %s', (int)$sourceLine, $path));
            return '';
        }
        if ($this->isDataTooLarge($data, self::MAX_SITEMAP_BYTES)) {
            $this->recordError(sprintf(
                'Sitemap source row %d remote sitemap exceeds the %d MB file size limit: %s',
                (int)$sourceLine,
                (int)(self::MAX_SITEMAP_BYTES / 1048576),
                $path
            ));
            return '';
        }

        $this->lastStats['files']++;
        return $data;
    }

    private function resolveLocalPath($relativePath)
    {
        $file = rtrim($this->directoryList->getRoot(), '/') . '/' . $relativePath;
        $realFile = realpath($file);
        $allowedDir = realpath(rtrim($this->directoryList->getPath(DirectoryList::VAR_DIR), '/') . '/litemage/warmup');
        if (!$realFile || !$allowedDir) {
            return null;
        }
        if (strpos($realFile . '/', rtrim($allowedDir, '/') . '/') !== 0) {
            return null;
        }

        return $realFile;
    }

    private function parseSourceRow($row, $sourceLine)
    {
        $columns = str_getcsv(trim((string)$row), ',', '"', '\\');
        $path = trim((string)($columns[0] ?? ''));
        if ($path === '') {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf('Sitemap source row %d is missing a sitemap path.', (int)$sourceLine));
            return null;
        }

        $storeIds = $this->parseStoreIds($columns[1] ?? '');
        if (!$storeIds) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Sitemap source row %d must include store IDs in the second column, for example 1|2|3.',
                (int)$sourceLine
            ));
            return null;
        }
        if (!$this->isRemoteSourceAllowedForStores($path, $storeIds)) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Sitemap source row %d remote sitemap host does not match selected store domains: %s',
                (int)$sourceLine,
                $path
            ));
            return null;
        }
        try {
            $sourcePriority = isset($columns[2]) && trim((string)$columns[2]) !== ''
                ? $this->parsePriority($columns[2], 'Sitemap source priority')
                : $this->config->getWarmupSitemapSourcePriority();
        } catch (LocalizedException $e) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Sitemap source row %d has invalid priority: %s',
                (int)$sourceLine,
                $e->getMessage()
            ));
            return null;
        }

        return [
            'path' => $path,
            'source_instance_key' => $this->buildSourceInstanceKey($path, $storeIds),
            'store_ids' => $storeIds,
            'source_priority' => $sourcePriority,
        ];
    }

    private function isUrlAllowedForStore($url, $storeId, &$error = null)
    {
        if (strpos((string)$url, '://') === false && strpos((string)$url, '//') !== 0) {
            return true;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            $error = 'URL host could not be parsed';
            return false;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $baseParts = parse_url($store->getBaseUrl(UrlInterface::URL_TYPE_LINK));
        } catch (\Exception $e) {
            $error = sprintf('store %d could not be loaded', (int)$storeId);
            return false;
        }

        $host = (string)$parts['host'];
        $baseHost = is_array($baseParts) ? ($baseParts['host'] ?? '') : '';
        if (!$baseHost || strcasecmp($host, $baseHost) !== 0 || !$this->isAllowedPort($parts, is_array($baseParts) ? $baseParts : [])) {
            $error = sprintf(
                'host or port %s did not match store %d host %s',
                $this->formatHostPort($parts),
                (int)$storeId,
                $this->formatHostPort(is_array($baseParts) ? $baseParts : [])
            );
            return false;
        }

        return true;
    }

    private function parseStoreIds($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }

        $ids = [];
        foreach (explode('|', $value) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                return [];
            }
            $id = (int)$part;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function getPriority($lastmod)
    {
        if (!$lastmod) {
            return 0;
        }

        $timestamp = strtotime($lastmod);
        if (!$timestamp) {
            return 0;
        }

        return (time() - $timestamp) <= 172800 ? 0 : self::LASTMOD_NORMAL_PRIORITY;
    }

    private function parsePriority($value, $label)
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d+$/', $value)) {
            throw new LocalizedException(__(
                '%1 must be a whole number from %2 to %3.',
                $label,
                Config::WARMUP_PRIORITY_MIN,
                Config::WARMUP_PRIORITY_MAX
            ));
        }

        $priority = (int)$value;
        if ($priority < Config::WARMUP_PRIORITY_MIN || $priority > Config::WARMUP_PRIORITY_MAX) {
            throw new LocalizedException(__(
                '%1 must be from %2 to %3.',
                $label,
                Config::WARMUP_PRIORITY_MIN,
                Config::WARMUP_PRIORITY_MAX
            ));
        }

        return $priority;
    }

    private function buildSourceInstanceKey($path, array $storeIds)
    {
        return trim((string)$path) . '::stores=' . implode('|', array_map('intval', $storeIds));
    }

    private function isRemoteSourceAllowedForStores($path, array $storeIds)
    {
        if (!preg_match('#^https?://#i', (string)$path)) {
            return true;
        }

        $sourceParts = parse_url((string)$path);
        if (!is_array($sourceParts) || empty($sourceParts['host'])) {
            return false;
        }
        $sourceHost = (string)$sourceParts['host'];

        foreach ($storeIds as $storeId) {
            try {
                $store = $this->storeManager->getStore((int)$storeId);
                $baseParts = parse_url($store->getBaseUrl(UrlInterface::URL_TYPE_LINK));
            } catch (\Exception $e) {
                continue;
            }
            $baseHost = is_array($baseParts) ? ($baseParts['host'] ?? '') : '';
            if ($baseHost
                && strcasecmp($sourceHost, $baseHost) === 0
                && $this->isAllowedPort($sourceParts, is_array($baseParts) ? $baseParts : [])
            ) {
                return true;
            }
        }

        return false;
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

    private function formatHostPort(array $parts)
    {
        $host = (string)($parts['host'] ?? '<none>');
        return isset($parts['port']) ? $host . ':' . (int)$parts['port'] : $host;
    }

    private function isFileTooLarge($file, $maxBytes)
    {
        $size = @filesize($file);
        return $size !== false && (int)$size > (int)$maxBytes;
    }

    private function isDataTooLarge($data, $maxBytes)
    {
        return strlen((string)$data) > (int)$maxBytes;
    }

    private function resetStats()
    {
        $this->lastStats = $this->emptyStats();
    }

    private function emptyStats()
    {
        return [
            'source' => 'sitemap',
            'source_rows' => 0,
            'files' => 0,
            'rows_seen' => 0,
            'generated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    private function recordError($message)
    {
        $this->lastStats['errors'][] = (string)$message;
    }
}
