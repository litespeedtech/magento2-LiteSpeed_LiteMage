<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

class UrlSourceRepository
{
    private const TABLE_SOURCE = 'litemage_warm_url_source';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    public function __construct(ResourceConnection $resource, DateTime $dateTime)
    {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
    }

    public function upsert($urlId, array $urlData, $sourceCode, array $sourceData = [])
    {
        $urlId = (int)$urlId;
        if ($urlId <= 0) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_SOURCE);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        $sourceCode = $this->normalizeCode($sourceCode);
        $sourceInstanceKey = $this->normalizeInstanceKey($sourceData['source_instance_key'] ?? $sourceCode);
        $sourcePriority = $this->normalizePriority($sourceData['source_priority'] ?? 100, 100);
        $urlPriority = $this->normalizeNullablePriority($sourceData['url_priority'] ?? null);
        $effectivePriority = $this->normalizePriority(
            $sourceData['effective_priority'] ?? $this->calculateEffectivePriority($sourcePriority, $urlPriority),
            $sourcePriority
        );
        $now = $this->dateTime->gmtDate();
        $row = [
            'url_id' => $urlId,
            'store_id' => (int)$urlData['store_id'],
            'url_hash' => (string)$urlData['url_hash'],
            'source_code' => $sourceCode,
            'source_instance_hash' => hash('sha256', $sourceInstanceKey),
            'source_instance_key' => $sourceInstanceKey,
            'source_priority' => $sourcePriority,
            'url_priority' => $urlPriority,
            'effective_priority' => $effectivePriority,
            'interval_seconds' => max(0, (int)($sourceData['interval_seconds'] ?? $urlData['interval_seconds'] ?? 0)),
            'is_active' => 1,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];

        $connection->insertOnDuplicate(
            $table,
            $row + ['created_at' => $now],
            [
                'store_id',
                'url_hash',
                'source_instance_key',
                'source_priority',
                'url_priority',
                'effective_priority',
                'interval_seconds',
                'is_active',
                'last_seen_at',
                'updated_at',
            ]
        );

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($table, 'source_id')
                ->where('url_id = ?', $urlId)
                ->where('source_code = ?', $sourceCode)
                ->where('source_instance_hash = ?', $row['source_instance_hash'])
                ->limit(1)
        );
    }

    public function calculateEffectivePriority($sourcePriority, $urlPriority = null)
    {
        $sourcePriority = $this->normalizePriority($sourcePriority, 100);
        $urlPriority = $this->normalizeNullablePriority($urlPriority);
        if ($urlPriority === null) {
            return $sourcePriority;
        }

        return min(Config::WARMUP_PRIORITY_MAX, max(Config::WARMUP_PRIORITY_MIN, $sourcePriority + $urlPriority));
    }

    private function normalizeCode($sourceCode)
    {
        $sourceCode = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$sourceCode));
        return substr($sourceCode ?: 'manual', 0, 32);
    }

    private function normalizeInstanceKey($sourceInstanceKey)
    {
        $sourceInstanceKey = trim((string)$sourceInstanceKey);
        return $sourceInstanceKey === '' ? 'default' : $sourceInstanceKey;
    }

    private function normalizePriority($priority, $default)
    {
        $priority = (int)$priority;
        if ($priority < Config::WARMUP_PRIORITY_MIN || $priority > Config::WARMUP_PRIORITY_MAX) {
            return (int)$default;
        }

        return $priority;
    }

    private function normalizeNullablePriority($priority)
    {
        if ($priority === null || $priority === '') {
            return null;
        }

        return $this->normalizePriority($priority, null);
    }
}
