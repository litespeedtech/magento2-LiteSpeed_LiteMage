<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup\Source;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class TextFileUrlSource
{
    private const ALLOWED_VAR_PATHS = [
        'litemage/warmup',
    ];
    private const MAX_TEXT_FILE_BYTES = 52428800;

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
        ?StoreManagerInterface $storeManager = null
    )
    {
        $this->config = $config;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
    }

    public function collect()
    {
        $this->resetStats();
        $items = [];
        foreach ($this->config->getWarmupTextFilePaths() as $sourceIndex => $sourceRow) {
            $sourceLine = $sourceIndex + 1;
            $this->lastStats['source_rows']++;
            $source = $this->parseSourceRow($sourceRow, $sourceLine);
            if (!$source) {
                continue;
            }
            foreach ($this->readRows($source['path'], $sourceLine) as $rowIndex => $row) {
                $rowLine = $rowIndex + 1;
                foreach ($this->parseRow($row, $source, $rowLine) as $item) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    public function validate()
    {
        $this->collect();
        return [
            'files' => $this->lastStats['files'],
            'rows' => $this->lastStats['rows_seen'],
            'valid' => $this->lastStats['generated'],
            'generated' => $this->lastStats['generated'],
            'skipped' => $this->lastStats['skipped'],
            'errors' => $this->lastStats['errors'],
            'source_rows' => $this->lastStats['source_rows'],
        ];
    }

    public function getLastStats()
    {
        return $this->lastStats ?: $this->emptyStats();
    }

    private function readRows($path, $sourceLine = null)
    {
        $file = $this->resolvePath($path);
        if (!$file || !is_file($file) || !is_readable($file)) {
            $this->recordError(sprintf(
                'Text/CSV source row %d is not readable or is outside var/litemage/warmup/: %s',
                (int)$sourceLine,
                $path
            ));
            return [];
        }
        if ($this->isFileTooLarge($file, self::MAX_TEXT_FILE_BYTES)) {
            $this->recordError(sprintf(
                'Text/CSV source row %d exceeds the %d MB file size limit: %s',
                (int)$sourceLine,
                (int)(self::MAX_TEXT_FILE_BYTES / 1048576),
                $path
            ));
            return [];
        }

        $this->lastStats['files']++;
        $rows = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $rows ?: [];
    }

    private function resolvePath($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return null;
        }
        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($relativePath, 'var/litemage/warmup/') !== 0) {
            return null;
        }

        $file = rtrim($this->directoryList->getRoot(), '/') . '/' . $relativePath;
        $realFile = realpath($file);
        if (!$realFile || !$this->isAllowedPath($realFile)) {
            return null;
        }

        return $realFile;
    }

    private function isAllowedPath($realFile)
    {
        $varDir = rtrim($this->directoryList->getPath(DirectoryList::VAR_DIR), '/');
        foreach (self::ALLOWED_VAR_PATHS as $relativePath) {
            $allowedDir = realpath($varDir . '/' . $relativePath);
            if ($allowedDir && strpos($realFile . '/', rtrim($allowedDir, '/') . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function parseSourceRow($row, $sourceLine)
    {
        $columns = str_getcsv(trim((string)$row), ',', '"', '\\');
        $path = trim((string)($columns[0] ?? ''));
        if ($path === '') {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf('Text/CSV source row %d is missing a file path.', (int)$sourceLine));
            return null;
        }

        $storeIds = $this->parseStoreIds($columns[1] ?? '');
        if (!$storeIds) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Text/CSV source row %d must include store IDs in the second column, for example 1|2|3.',
                (int)$sourceLine
            ));
            return null;
        }
        $priorityColumn = $columns[2] ?? '';
        try {
            $sourcePriority = trim((string)$priorityColumn) !== ''
                ? $this->parsePriority($priorityColumn, 'Text/CSV source priority')
                : $this->config->getWarmupTextFileSourcePriority();
        } catch (LocalizedException $e) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Text/CSV source row %d has invalid priority: %s',
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

    private function parseRow($row, array $source, $rowLine = null)
    {
        $row = trim((string)$row);
        $this->lastStats['rows_seen']++;
        if ($row === '' || strpos($row, '#') === 0) {
            $this->lastStats['skipped']++;
            return [];
        }

        $columns = str_getcsv($row, ',', '"', '\\');
        $url = trim((string)($columns[0] ?? ''));
        if ($url === '') {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Text/CSV file %s line %d is missing a URL.',
                $source['path'],
                (int)$rowLine
            ));
            return [];
        }

        $storeIds = $source['store_ids'] ?? [];
        if (!$storeIds) {
            $this->lastStats['skipped']++;
            return [];
        }
        $priorityColumn = $columns[1] ?? '';
        try {
            $priority = trim((string)$priorityColumn) !== ''
                ? $this->parsePriority($priorityColumn, 'Text/CSV URL priority')
                : null;
        } catch (LocalizedException $e) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Text/CSV file %s line %d has invalid priority: %s',
                $source['path'],
                (int)$rowLine,
                $e->getMessage()
            ));
            return [];
        }

        $items = [];
        $lastStoreError = null;
        foreach ($storeIds as $storeId) {
            if ($storeId !== null && !$this->isUrlAllowedForStore($url, $storeId, $lastStoreError)) {
                continue;
            }
            $items[] = [
                'url' => $url,
                'store_id' => $storeId,
                'page_type' => 'text_file',
                'source_instance_key' => $source['source_instance_key'],
                'source_priority' => $source['source_priority'],
                'url_priority' => $priority,
                'priority' => $priority,
            ];
        }
        if (!$items) {
            $this->lastStats['skipped']++;
            $this->recordError(sprintf(
                'Text/CSV file %s line %d URL "%s" does not match selected store domains%s.',
                $source['path'],
                (int)$rowLine,
                $url,
                $lastStoreError ? ': ' . $lastStoreError : ''
            ));
        } else {
            $this->lastStats['generated'] += count($items);
        }

        return $items;
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

    private function isUrlAllowedForStore($url, $storeId, &$error = null)
    {
        if (strpos((string)$url, '://') === false && strpos((string)$url, '//') !== 0) {
            return true;
        }

        $parts = parse_url((string)$url);
        if (!is_array($parts) || empty($parts['host'])) {
            $error = 'URL host could not be parsed';
            return false;
        }

        try {
            $store = $this->storeManager->getStore((int)$storeId);
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

    private function resetStats()
    {
        $this->lastStats = $this->emptyStats();
    }

    private function emptyStats()
    {
        return [
            'source' => 'text_file',
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
