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

class ReverseIndexRepository
{
    private const TABLE_INDEX = 'litemage_warm_tag_url';
    private const TABLE_URL = 'litemage_warm_url';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var UrlNormalizer
     */
    private $urlNormalizer;

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime,
        Config $config,
        UrlNormalizer $urlNormalizer
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
        $this->config = $config;
        $this->urlNormalizer = $urlNormalizer;
    }

    public function recordCacheableRequest($url, $storeId, array $tags)
    {
        if (!$this->config->isWarmupReverseIndexEnabled()) {
            return 0;
        }

        $tags = $this->extractEntityTags($tags);
        if (!$tags) {
            return 0;
        }

        $normalized = $this->urlNormalizer->normalize($url, $storeId);
        $urlId = $this->upsertIndexedUrl($normalized);
        $row = [
            'url_id' => $urlId,
            'store_id' => (int)$normalized['store_id'],
            'url_hash' => $normalized['url_hash'],
            'page_type' => 'cacheable',
        ];

        return $this->recordTagsForUrl($row, $tags);
    }

    private function recordTagsForUrl(array $urlRow, array $tags)
    {
        $tags = array_slice(array_values(array_unique($tags)), 0, $this->config->getWarmupReverseIndexMaxTagsPerUrl());
        if (!$tags) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_INDEX);
        $now = $this->dateTime->gmtDate();
        $expiresAt = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            time() + ($this->config->getWarmupReverseIndexTtlDays() * 86400)
        );
        $inserted = 0;

        foreach ($tags as $tag) {
            $row = [
                'tag' => $tag,
                'url_id' => (int)$urlRow['url_id'],
                'store_id' => (int)$urlRow['store_id'],
                'url_hash' => $urlRow['url_hash'],
                'page_type' => $urlRow['page_type'] ?? null,
                'last_seen_at' => $now,
                'expires_at' => $expiresAt,
                'updated_at' => $now,
            ];
            $connection->insertOnDuplicate(
                $table,
                $row + ['created_at' => $now],
                ['store_id', 'url_hash', 'page_type', 'last_seen_at', 'expires_at', 'updated_at']
            );
            $inserted++;
            $this->pruneTagUrlCap($tag, (int)$urlRow['store_id']);
        }

        $this->pruneUrlTagCap((int)$urlRow['url_id']);
        return $inserted;
    }

    public function getUrlsByTags(array $tags, $limit, $storeId = null)
    {
        $tags = array_values(array_unique(array_filter(array_map('trim', $tags))));
        if (!$tags) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $indexTable = $this->resource->getTableName(self::TABLE_INDEX);
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $select = $connection->select()
            ->from(['idx' => $indexTable], ['tag', 'store_id', 'url_id', 'url_hash', 'page_type'])
            ->joinInner(
                ['url' => $urlTable],
                'url.url_id = idx.url_id',
                ['url', 'source', 'entity_type', 'entity_id', 'priority']
            )
            ->where('idx.tag IN (?)', $tags)
            ->where('idx.expires_at >= ?', $this->dateTime->gmtDate())
            ->where('url.is_active = ?', 1)
            ->where('url.is_blacklisted = ?', 0)
            ->order(['idx.last_seen_at DESC', 'idx.index_id DESC'])
            ->limit(max(1, (int)$limit));
        if ($storeId !== null) {
            $select->where('idx.store_id = ?', (int)$storeId);
        }

        $rows = [];
        $seen = [];
        foreach ($connection->fetchAll($select) as $row) {
            $key = $row['store_id'] . ':' . $row['url_hash'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $row;
        }
        return $rows;
    }

    public function getRecentlySeenUrls($limit, array $storeIds = [])
    {
        $limit = max(1, (int)$limit);
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $connection = $this->resource->getConnection();
        $indexTable = $this->resource->getTableName(self::TABLE_INDEX);
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $select = $connection->select()
            ->from(['idx' => $indexTable], [
                'store_id' => 'idx.store_id',
                'url_id' => 'idx.url_id',
                'url_hash' => 'idx.url_hash',
                'page_type' => 'idx.page_type',
                'last_seen_at' => new \Zend_Db_Expr('MAX(idx.last_seen_at)'),
            ])
            ->joinInner(
                ['url' => $urlTable],
                'url.url_id = idx.url_id',
                ['url', 'source', 'entity_type', 'entity_id', 'priority']
            )
            ->where('idx.expires_at >= ?', $this->dateTime->gmtDate())
            ->where('url.is_active = ?', 1)
            ->where('url.is_blacklisted = ?', 0)
            ->where(
                'url.entity_id IS NULL OR url.entity_id = 0 OR url.entity_type NOT IN (?)',
                ['product', 'category', 'cms-page']
            )
            ->group(['idx.store_id', 'idx.url_id', 'idx.url_hash', 'idx.page_type', 'url.url', 'url.source', 'url.entity_type', 'url.entity_id', 'url.priority'])
            ->order(['last_seen_at DESC', 'idx.url_id DESC'])
            ->limit($limit);
        if ($storeIds) {
            $select->where('idx.store_id IN (?)', $storeIds);
        }

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $row['source_instance_key'] = 'recently_seen';
            $row['source_priority'] = $this->config->getWarmupRecentlySeenSourcePriority();
            $row['url_priority'] = isset($row['priority']) ? (int)$row['priority'] : null;
            $rows[] = $row;
        }

        return $rows;
    }

    public function cleanupExpired()
    {
        $connection = $this->resource->getConnection();
        return $connection->delete(
            $this->resource->getTableName(self::TABLE_INDEX),
            ['expires_at < ?' => $this->dateTime->gmtDate()]
        );
    }

    public function getSummary()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_INDEX);
        $now = $this->dateTime->gmtDate();

        return [
            'rows' => (int)$connection->fetchOne(
                $connection->select()->from($table, new \Zend_Db_Expr('COUNT(*)'))
            ),
            'active_rows' => (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, new \Zend_Db_Expr('COUNT(*)'))
                    ->where('expires_at >= ?', $now)
            ),
            'tags' => (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, new \Zend_Db_Expr('COUNT(DISTINCT tag)'))
                    ->where('expires_at >= ?', $now)
            ),
            'urls' => (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, new \Zend_Db_Expr('COUNT(DISTINCT url_id)'))
                    ->where('expires_at >= ?', $now)
            ),
            'expired_rows' => (int)$connection->fetchOne(
                $connection->select()
                    ->from($table, new \Zend_Db_Expr('COUNT(*)'))
                    ->where('expires_at < ?', $now)
            ),
            'storage_bytes' => $this->getStorageBytes($table),
            'max_tags_per_url' => $this->config->getWarmupReverseIndexMaxTagsPerUrl(),
            'max_urls_per_tag' => $this->config->getWarmupReverseIndexMaxUrlsPerTag(),
            'ttl_days' => $this->config->getWarmupReverseIndexTtlDays(),
        ];
    }

    private function getStorageBytes($tableName)
    {
        $connection = $this->resource->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from(
                    'information_schema.TABLES',
                    new \Zend_Db_Expr('COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)')
                )
                ->where('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME = ?', $tableName)
        );
    }

    private function extractEntityTags(array $tags)
    {
        $entityTags = [];
        foreach ($tags as $tag) {
            $tag = trim((string)$tag);
            if (preg_match('/^P\d+$/', $tag) || preg_match('/^C_\d+$/', $tag)) {
                $entityTags[] = $tag;
            }
        }
        return array_values(array_unique($entityTags));
    }

    private function upsertIndexedUrl(array $normalized)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_URL);
        $now = $this->dateTime->gmtDate();
        $row = [
            'store_id' => (int)$normalized['store_id'],
            'url_hash' => $normalized['url_hash'],
            'url' => $normalized['url'],
            'source' => 'reverse_index',
            'page_type' => 'cacheable',
            'priority' => 0,
            'interval_seconds' => 0,
            'is_active' => 1,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];

        $connection->insertOnDuplicate(
            $table,
            $row + ['created_at' => $now],
            ['url', 'last_seen_at', 'is_active', 'updated_at']
        );

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($table, 'url_id')
                ->where('store_id = ?', (int)$normalized['store_id'])
                ->where('url_hash = ?', $normalized['url_hash'])
        );
    }

    private function pruneUrlTagCap($urlId)
    {
        $this->deleteRowsAfterLimit(
            ['url_id = ?' => (int)$urlId],
            $this->config->getWarmupReverseIndexMaxTagsPerUrl()
        );
    }

    private function pruneTagUrlCap($tag, $storeId)
    {
        $this->deleteRowsAfterLimit(
            ['tag = ?' => $tag, 'store_id = ?' => (int)$storeId],
            $this->config->getWarmupReverseIndexMaxUrlsPerTag()
        );
    }

    private function deleteRowsAfterLimit(array $where, $limit)
    {
        $limit = max(1, (int)$limit);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_INDEX);
        $select = $connection->select()
            ->from($table, 'index_id')
            ->order(['last_seen_at DESC', 'index_id DESC']);
        foreach ($where as $condition => $value) {
            $select->where($condition, $value);
        }

        $ids = array_map('intval', $connection->fetchCol($select));
        if (count($ids) <= $limit) {
            return 0;
        }

        $deleteIds = array_slice($ids, $limit);
        return $connection->delete($table, ['index_id IN (?)' => $deleteIds]);
    }
}
