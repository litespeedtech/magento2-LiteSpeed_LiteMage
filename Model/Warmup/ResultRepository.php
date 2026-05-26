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

class ResultRepository
{
    public const FILTER_EMPTY = '__empty__';

    private const TABLE_RESULT = 'litemage_warm_result';
    private const TABLE_PROFILE = 'litemage_warm_profile';

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

    public function create(array $queueRow, array $result)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_RESULT);

        $connection->insert($table, $this->filterColumns($table, [
            'queue_id' => isset($queueRow['queue_id']) ? (int)$queueRow['queue_id'] : null,
            'url_id' => isset($queueRow['url_id']) ? (int)$queueRow['url_id'] : null,
            'profile_id' => isset($queueRow['profile_id']) ? (int)$queueRow['profile_id'] : null,
            'store_id' => (int)$queueRow['store_id'],
            'url_hash' => $queueRow['url_hash'],
            'url' => $queueRow['url'],
            'source' => $queueRow['source'] ?? null,
            'work_type' => $queueRow['work_type'] ?? QueueWorkType::TYPE_SCHEDULED,
            'source_flags' => $queueRow['source_flags'] ?? null,
            'source_instance_key' => $queueRow['source_instance_key'] ?? null,
            'source_priority' => isset($queueRow['source_priority']) ? (int)$queueRow['source_priority'] : null,
            'url_priority' => isset($queueRow['url_priority']) ? (int)$queueRow['url_priority'] : null,
            'mode' => $queueRow['mode'],
            'status' => $result['status'] ?? QueueStatus::STATUS_FAILED,
            'priority' => isset($queueRow['priority']) ? (int)$queueRow['priority'] : null,
            'http_status' => isset($result['http_status']) ? (int)$result['http_status'] : null,
            'response_time_ms' => isset($result['response_time_ms']) ? (int)$result['response_time_ms'] : null,
            'cache_status' => $result['cache_status'] ?? null,
            'final_url' => $result['final_url'] ?? null,
            'headers_summary' => $result['headers_summary'] ?? null,
            'error' => $result['error'] ?? null,
            'created_at' => $this->dateTime->gmtDate(),
        ]));

        return (int)$connection->lastInsertId($table);
    }

    public function cleanup($olderThanDays)
    {
        $olderThanDays = max(1, (int)$olderThanDays);
        $connection = $this->resource->getConnection();
        return $connection->delete(
            $this->resource->getTableName(self::TABLE_RESULT),
            ['created_at < ?' => $this->dateTime->gmtDate('Y-m-d H:i:s', time() - ($olderThanDays * 86400))]
        );
    }

    public function getRecent($limit = 100, array $filters = [])
    {
        return $this->getPage($limit, 0, $filters);
    }

    public function getPage($limit = 100, $offset = 0, array $filters = [])
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE_RESULT))
            ->order('result_id DESC')
            ->limit(max(1, (int)$limit), max(0, (int)$offset));

        return $connection->fetchAll(
            $this->applyAdminFilters($select, $filters)
        );
    }

    public function getTotalCount(array $filters = [])
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE_RESULT), new \Zend_Db_Expr('COUNT(*)'));

        return (int)$connection->fetchOne($this->applyAdminFilters($select, $filters));
    }

    public function getDistinctFilterValues($column, $limit = 200)
    {
        $allowedColumns = [
            'store_id',
            'mode',
            'work_type',
            'status',
            'source',
            'source_instance_key',
            'priority',
            'http_status',
            'cache_status',
        ];
        if (!in_array((string)$column, $allowedColumns, true)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_RESULT);
        if (!$connection->isTableExists($table)) {
            return [];
        }
        if (!$this->columnExists($table, $column)) {
            return [];
        }

        $rows = $connection->fetchCol(
            $connection->select()
                ->from($table, $column)
                ->where($column . ' IS NOT NULL')
                ->where($column . ' != ?', '')
                ->group($column)
                ->order($column . ' ASC')
                ->limit(max(1, (int)$limit))
        );

        return array_values(array_filter($rows, function ($value) {
            return $value !== null && $value !== '';
        }));
    }

    public function getDistinctLaneFilterValues($limit = 500)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_RESULT);
        if (!$connection->isTableExists($table)) {
            return [];
        }
        if (!$this->columnExists($table, 'source')) {
            return [];
        }
        if (!$this->columnExists($table, 'source_instance_key')) {
            return $this->getDistinctFilterValues('source', $limit);
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['source', 'source_instance_key'])
                ->group(['source', 'source_instance_key'])
                ->order('source ASC')
                ->order('source_instance_key ASC')
                ->limit(max(1, (int)$limit))
        );

        $lanes = [];
        foreach ($rows as $row) {
            $source = trim((string)($row['source'] ?? ''));
            $instance = trim((string)($row['source_instance_key'] ?? ''));
            if (in_array($source, ['purge_entity', 'purge_reverse_index', 'purge_broad'], true)) {
                $lane = $source;
            } elseif ($instance !== '') {
                $lane = $instance;
            } else {
                $lane = $source;
            }
            if ($lane !== '') {
                $lanes[$lane] = $lane;
            }
        }

        ksort($lanes);
        return array_values($lanes);
    }

    public function hasEmptyFilterValue($column)
    {
        if (!in_array((string)$column, ['http_status', 'cache_status'], true)) {
            return false;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_RESULT);
        if (!$connection->isTableExists($table)) {
            return false;
        }

        $select = $connection->select()
            ->from($table, new \Zend_Db_Expr('1'))
            ->limit(1);
        if ($column === 'http_status') {
            $select->where('http_status IS NULL');
        } else {
            $select->where('(cache_status IS NULL OR cache_status = ?)', '');
        }

        return (bool)$connection->fetchOne($select);
    }

    public function getProfileFilterOptions($limit = 200)
    {
        $connection = $this->resource->getConnection();
        $resultTable = $this->resource->getTableName(self::TABLE_RESULT);
        $profileTable = $this->resource->getTableName(self::TABLE_PROFILE);
        if (!$connection->isTableExists($resultTable)) {
            return [];
        }

        $profileIds = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($resultTable, new \Zend_Db_Expr('COALESCE(profile_id, 0)'))
                ->group(new \Zend_Db_Expr('COALESCE(profile_id, 0)'))
                ->order(new \Zend_Db_Expr('COALESCE(profile_id, 0) ASC'))
                ->limit(max(1, (int)$limit))
        ));
        if (!$profileIds) {
            return [];
        }

        $profileRows = [];
        $realProfileIds = array_values(array_filter($profileIds));
        if ($realProfileIds && $connection->isTableExists($profileTable)) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from($profileTable, ['profile_id', 'code', 'label'])
                    ->where('profile_id IN (?)', $realProfileIds)
            ) as $row) {
                $profileRows[(int)$row['profile_id']] = $row;
            }
        }

        $options = [];
        foreach ($profileIds as $id) {
            $row = $profileRows[$id] ?? [];
            $label = trim((string)($row['label'] ?? ('Profile ' . $id)));
            $code = trim((string)($row['code'] ?? ''));
            $options[] = [
                'value' => (string)$id,
                'label' => $id === 0
                    ? 'Guest'
                    : sprintf('%s (%s)', $label, $code !== '' ? $code : $id),
            ];
        }

        return $options;
    }

    private function applyAdminFilters($select, array $filters)
    {
        if (isset($filters['store_id']) && $filters['store_id'] !== '') {
            $select->where('store_id = ?', (int)$filters['store_id']);
        }
        if (isset($filters['profile_id']) && $filters['profile_id'] !== '') {
            $profileId = (int)$filters['profile_id'];
            if ($profileId === 0) {
                $select->where('(profile_id IS NULL OR profile_id = 0)');
            } else {
                $select->where('profile_id = ?', $profileId);
            }
        }
        if (!empty($filters['mode'])) {
            $select->where('mode = ?', (string)$filters['mode']);
        }
        if (!empty($filters['work_type']) && $this->columnExists($this->resource->getTableName(self::TABLE_RESULT), 'work_type')) {
            $select->where('work_type = ?', (string)$filters['work_type']);
        }
        if (!empty($filters['status'])) {
            $select->where('status = ?', (string)$filters['status']);
        }
        if (!empty($filters['source'])) {
            $select->where('source = ?', (string)$filters['source']);
        }
        if (!empty($filters['lane'])) {
            $lane = (string)$filters['lane'];
            if ($this->columnExists($this->resource->getTableName(self::TABLE_RESULT), 'source_instance_key')) {
                $select->where('(source = ? OR source_instance_key = ?)', $lane);
            } else {
                $select->where('source = ?', $lane);
            }
        }
        if (!empty($filters['source_instance_key'])) {
            $select->where('source_instance_key = ?', (string)$filters['source_instance_key']);
        }
        if (isset($filters['priority']) && $filters['priority'] !== '') {
            $select->where('priority = ?', (int)$filters['priority']);
        }
        if (isset($filters['priority_min']) && $filters['priority_min'] !== '') {
            $select->where('priority >= ?', (int)$filters['priority_min']);
        }
        if (isset($filters['priority_max']) && $filters['priority_max'] !== '') {
            $select->where('priority <= ?', (int)$filters['priority_max']);
        }
        if (isset($filters['http_status']) && $filters['http_status'] !== '') {
            if ((string)$filters['http_status'] === self::FILTER_EMPTY) {
                $select->where('http_status IS NULL');
            } else {
                $select->where('http_status = ?', (int)$filters['http_status']);
            }
        }
        if (!empty($filters['cache_status'])) {
            if ((string)$filters['cache_status'] === self::FILTER_EMPTY) {
                $select->where('(cache_status IS NULL OR cache_status = ?)', '');
            } else {
                $select->where('cache_status = ?', (string)$filters['cache_status']);
            }
        }
        if (!empty($filters['url'])) {
            $url = trim((string)$filters['url']);
            if ($url !== '') {
                $select->where('url LIKE ?', '%' . addcslashes($url, '\\%_') . '%');
            }
        }
        if (!empty($filters['error_text'])) {
            $errorText = trim((string)$filters['error_text']);
            if ($errorText !== '') {
                $select->where('error LIKE ?', '%' . addcslashes($errorText, '\\%_') . '%');
            }
        }
        if (!empty($filters['date_from'])) {
            $select->where('created_at >= ?', $this->normalizeDateFilter((string)$filters['date_from'], false));
        }
        if (!empty($filters['date_to'])) {
            $select->where('created_at <= ?', $this->normalizeDateFilter((string)$filters['date_to'], true));
        }

        return $select;
    }

    private function normalizeDateFilter($value, $endOfDay)
    {
        $value = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return $value;
    }

    private function filterColumns($table, array $row)
    {
        foreach (array_keys($row) as $column) {
            if (!$this->columnExists($table, $column)) {
                unset($row[$column]);
            }
        }
        return $row;
    }

    private function columnExists($table, $column)
    {
        $columns = $this->resource->getConnection()->describeTable($table);
        return isset($columns[$column]);
    }
}
