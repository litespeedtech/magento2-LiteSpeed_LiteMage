<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

class PurgeEventRepository
{
    private const TABLE_EVENT = 'litemage_warm_purge_event';

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

    public function create(array $data)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_EVENT);
        $connection->insert($table, $this->filterColumns($table, [
            'source' => $data['source'] ?? 'purge',
            'reason' => $data['reason'] ?? null,
            'tags' => isset($data['tags']) ? implode(',', $data['tags']) : null,
            'is_broad' => (int)($data['is_broad'] ?? 0),
            'is_purge_all' => (int)($data['is_purge_all'] ?? 0),
            'entity_count' => (int)($data['entity_count'] ?? 0),
            'queued_count' => (int)($data['queued_count'] ?? 0),
            'entity_queued_count' => (int)($data['entity_queued_count'] ?? 0),
            'reverse_index_queued_count' => (int)($data['reverse_index_queued_count'] ?? 0),
            'restarted_count' => (int)($data['restarted_count'] ?? 0),
            'restart_matched_count' => (int)($data['restart_matched_count'] ?? ($data['restarted_count'] ?? 0)),
            'restart_changed_count' => (int)($data['restart_changed_count'] ?? ($data['restarted_count'] ?? 0)),
            'cleared_delta_count' => (int)($data['cleared_delta_count'] ?? 0),
            'created_at' => $this->dateTime->gmtDate(),
        ]));
        return (int)$connection->lastInsertId($table);
    }

    public function cleanup($olderThanDays)
    {
        $olderThanDays = max(1, (int)$olderThanDays);
        $connection = $this->resource->getConnection();
        return $connection->delete(
            $this->resource->getTableName(self::TABLE_EVENT),
            ['created_at < ?' => $this->dateTime->gmtDate('Y-m-d H:i:s', time() - ($olderThanDays * 86400))]
        );
    }

    public function getRecent($limit = 100)
    {
        return $this->getPage($limit, 0);
    }

    public function getPage($limit = 100, $offset = 0)
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE_EVENT))
                ->order('event_id DESC')
                ->limit(max(1, (int)$limit), max(0, (int)$offset))
        );
    }

    public function getTotalCount()
    {
        $connection = $this->resource->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE_EVENT), new \Zend_Db_Expr('COUNT(*)'))
        );
    }

    private function filterColumns($table, array $row)
    {
        $columns = $this->resource->getConnection()->describeTable($table);
        foreach (array_keys($row) as $column) {
            if (!isset($columns[$column])) {
                unset($row[$column]);
            }
        }
        return $row;
    }
}
