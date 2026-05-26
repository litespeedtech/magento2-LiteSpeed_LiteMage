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

class ProgressRepository
{
    private const TABLE_QUEUE = 'litemage_warm_queue';
    private const TABLE_PROFILE = 'litemage_warm_profile';
    private const TABLE_LOCK = 'litemage_warm_lane_lock';
    private const TABLE_URL_SOURCE = 'litemage_warm_url_source';
    private const DELTA_SOURCES = ['purge_entity', 'purge_reverse_index', 'purge'];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var array
     */
    private $tableColumns = [];

    public function __construct(ResourceConnection $resource, DateTime $dateTime)
    {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
    }

    public function getOverallSummary()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($table)) {
            return $this->emptySummary();
        }

        $select = $connection->select()
            ->from($table, $this->statusColumns() + [
                'total' => new \Zend_Db_Expr('COUNT(*)'),
                'urgent' => new \Zend_Db_Expr('SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END)'),
                'max_priority' => new \Zend_Db_Expr(
                    "COALESCE(MIN(CASE WHEN status IN ('"
                    . QueueStatus::STATUS_PENDING . "','" . QueueStatus::STATUS_RUNNING
                    . "') THEN priority ELSE NULL END), MIN(priority), 100)"
                ),
                'oldest_pending_at' => new \Zend_Db_Expr(
                    "MIN(CASE WHEN status = '" . QueueStatus::STATUS_PENDING . "' THEN next_run_at ELSE NULL END)"
                ),
                'latest_warmed_at' => new \Zend_Db_Expr('MAX(last_warmed_at)'),
            ]);
        $this->applyProgressScope($select, $table);

        $row = $connection->fetchRow($select) ?: [];

        return $this->normalizeSummary($row);
    }

    public function getLaneProgress($limit = 200)
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $profileTable = $this->resource->getTableName(self::TABLE_PROFILE);
        if (!$connection->isTableExists($queueTable)) {
            return [];
        }

        $profileId = new \Zend_Db_Expr('COALESCE(q.profile_id, 0)');
        $sourceInstanceKey = new \Zend_Db_Expr('q.source_instance_key');
        $sourceInstanceJoin = null;
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if ($connection->isTableExists($sourceTable)) {
            $sourceInstanceJoin = $connection->select()
                ->from($sourceTable, [
                    'url_id',
                    'source_code',
                    'source_instance_key' => new \Zend_Db_Expr(
                        'MIN(CASE WHEN source_instance_key IS NOT NULL AND source_instance_key <> source_code THEN source_instance_key ELSE NULL END)'
                    ),
                ])
                ->where('is_active = ?', 1)
                ->group(['url_id', 'source_code']);
            $sourceInstanceKey = new \Zend_Db_Expr(
                "CASE WHEN q.source_instance_key IS NULL OR q.source_instance_key = '' OR q.source_instance_key = q.source "
                . "THEN COALESCE(us.source_instance_key, q.source_instance_key, q.source) ELSE q.source_instance_key END"
            );
        }
        $workType = $this->columnExists($queueTable, 'work_type')
            ? 'q.work_type'
            : new \Zend_Db_Expr("'" . QueueWorkType::TYPE_SCHEDULED . "'");
        $select = $connection->select()
            ->from(['q' => $queueTable], [
                'store_id' => 'q.store_id',
                'work_type' => $workType,
                'source' => 'q.source',
                'source_instance_key' => $sourceInstanceKey,
                'source_priority' => new \Zend_Db_Expr('COALESCE(MIN(q.source_priority), 100)'),
                'profile_id' => $profileId,
                'profile_code' => new \Zend_Db_Expr("COALESCE(p.code, 'guest')"),
                'profile_label' => new \Zend_Db_Expr("COALESCE(p.label, 'Guest')"),
                'mode' => 'q.mode',
            ] + $this->statusColumns('q') + [
                'total' => new \Zend_Db_Expr('COUNT(*)'),
                'urgent' => new \Zend_Db_Expr('SUM(CASE WHEN q.is_urgent = 1 THEN 1 ELSE 0 END)'),
                'max_priority' => new \Zend_Db_Expr(
                    "COALESCE(MIN(CASE WHEN q.status IN ('"
                    . QueueStatus::STATUS_PENDING . "','" . QueueStatus::STATUS_RUNNING
                    . "') THEN q.priority ELSE NULL END), MIN(q.priority), 100)"
                ),
                'oldest_pending_at' => new \Zend_Db_Expr(
                    "MIN(CASE WHEN q.status = '" . QueueStatus::STATUS_PENDING . "' THEN q.next_run_at ELSE NULL END)"
                ),
                'cycle_started_at' => new \Zend_Db_Expr('MIN(q.scheduled_at)'),
                'latest_warmed_at' => new \Zend_Db_Expr('MAX(q.last_warmed_at)'),
                'latest_updated_at' => new \Zend_Db_Expr('MAX(q.updated_at)'),
            ])
            ->joinLeft(['p' => $profileTable], 'p.profile_id = q.profile_id', []);

        if ($sourceInstanceJoin !== null) {
            $select->joinLeft(
                ['us' => $sourceInstanceJoin],
                'us.url_id = q.url_id AND us.source_code = q.source',
                []
            );
        }

        $this->applyProgressScope($select, $queueTable, 'q');

        $select
            ->group(['q.source', $workType, $sourceInstanceKey, 'q.store_id', $profileId, 'q.mode', 'p.code', 'p.label'])
            ->order([
                'source_priority ASC',
                'max_priority ASC',
                'q.source ASC',
                'source_instance_key ASC',
                'urgent DESC',
                'running DESC',
                'pending DESC',
                'failed DESC',
                'q.store_id ASC',
                'profile_id ASC',
                'profile_label ASC',
                'q.mode ASC',
            ])
            ->limit(max(1, (int)$limit));

        $lanes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $lanes[] = $this->normalizeLane($row);
        }
        return $lanes;
    }

    public function getCoveredLaneProgress($limit = 200)
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $profileTable = $this->resource->getTableName(self::TABLE_PROFILE);
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if (!$connection->isTableExists($queueTable) || !$connection->isTableExists($sourceTable)) {
            return [];
        }

        $profileId = new \Zend_Db_Expr('COALESCE(q.profile_id, 0)');
        $ownerInstanceKey = new \Zend_Db_Expr(
            "CASE WHEN q.source_instance_key IS NULL OR q.source_instance_key = '' OR q.source_instance_key = q.source "
            . "THEN COALESCE(owner.source_instance_key, q.source_instance_key, q.source) ELSE q.source_instance_key END"
        );
        $ownerInstanceJoin = $connection->select()
            ->from($sourceTable, [
                'url_id',
                'source_code',
                'source_instance_key' => new \Zend_Db_Expr(
                    'MIN(CASE WHEN source_instance_key IS NOT NULL AND source_instance_key <> source_code THEN source_instance_key ELSE NULL END)'
                ),
            ])
            ->where('is_active = ?', 1)
            ->group(['url_id', 'source_code']);

        $workType = $this->columnExists($queueTable, 'work_type')
            ? 'q.work_type'
            : new \Zend_Db_Expr("'" . QueueWorkType::TYPE_SCHEDULED . "'");
        $select = $connection->select()
            ->from(['q' => $queueTable], [
                'store_id' => 'q.store_id',
                'work_type' => $workType,
                'source' => 'us.source_code',
                'source_instance_key' => 'us.source_instance_key',
                'source_priority' => new \Zend_Db_Expr('COALESCE(MIN(us.source_priority), 100)'),
                'profile_id' => $profileId,
                'profile_code' => new \Zend_Db_Expr("COALESCE(p.code, 'guest')"),
                'profile_label' => new \Zend_Db_Expr("COALESCE(p.label, 'Guest')"),
                'mode' => 'q.mode',
                'covered' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->joinInner(
                ['us' => $sourceTable],
                'us.url_id = q.url_id AND us.is_active = 1',
                []
            )
            ->joinLeft(
                ['owner' => $ownerInstanceJoin],
                'owner.url_id = q.url_id AND owner.source_code = q.source',
                []
            )
            ->joinLeft(['p' => $profileTable], 'p.profile_id = q.profile_id', [])
            ->where('us.source_code != q.source OR us.source_instance_key != ' . $ownerInstanceKey)
            ->group(['us.source_code', $workType, 'us.source_instance_key', 'q.store_id', $profileId, 'q.mode', 'p.code', 'p.label'])
            ->order([
                'source_priority ASC',
                'us.source_code ASC',
                'us.source_instance_key ASC',
                'q.store_id ASC',
                'profile_id ASC',
                'profile_label ASC',
                'q.mode ASC',
            ])
            ->limit(max(1, (int)$limit));
        $this->applyProgressScope($select, $queueTable, 'q');

        $lanes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $lanes[] = $this->normalizeCoveredLane($row);
        }
        return $lanes;
    }

    public function getWorkStateSummary()
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $lockTable = $this->resource->getTableName(self::TABLE_LOCK);
        $summary = [
            'due_pending' => 0,
            'active_locks' => 0,
            'expired_locks' => 0,
            'oldest_lock_at' => null,
        ];

        if ($connection->isTableExists($queueTable)) {
            $summary['due_pending'] = (int)$connection->fetchOne(
                $connection->select()
                    ->from($queueTable, new \Zend_Db_Expr('COUNT(*)'))
                    ->where('status = ?', QueueStatus::STATUS_PENDING)
                    ->where('(next_run_at IS NULL OR next_run_at <= ?)', $this->dateTime->gmtDate())
            );
        }

        if ($connection->isTableExists($lockTable)) {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($lockTable, [
                        'active_locks' => new \Zend_Db_Expr(
                            'SUM(CASE WHEN expires_at >= UTC_TIMESTAMP() THEN 1 ELSE 0 END)'
                        ),
                        'expired_locks' => new \Zend_Db_Expr(
                            'SUM(CASE WHEN expires_at < UTC_TIMESTAMP() THEN 1 ELSE 0 END)'
                        ),
                        'oldest_lock_at' => new \Zend_Db_Expr('MIN(locked_at)'),
                    ])
            ) ?: [];
            $summary['active_locks'] = (int)($row['active_locks'] ?? 0);
            $summary['expired_locks'] = (int)($row['expired_locks'] ?? 0);
            $summary['oldest_lock_at'] = $row['oldest_lock_at'] ?? null;
        }

        return $summary;
    }

    private function statusColumns($alias = null)
    {
        $field = $alias ? $alias . '.status' : 'status';
        return [
            'pending' => new \Zend_Db_Expr(
                "SUM(CASE WHEN {$field} = '" . QueueStatus::STATUS_PENDING . "' THEN 1 ELSE 0 END)"
            ),
            'running' => new \Zend_Db_Expr(
                "SUM(CASE WHEN {$field} = '" . QueueStatus::STATUS_RUNNING . "' THEN 1 ELSE 0 END)"
            ),
            'warmed' => new \Zend_Db_Expr(
                "SUM(CASE WHEN {$field} = '" . QueueStatus::STATUS_WARMED . "' THEN 1 ELSE 0 END)"
            ),
            'skipped' => new \Zend_Db_Expr(
                "SUM(CASE WHEN {$field} = '" . QueueStatus::STATUS_SKIPPED . "' THEN 1 ELSE 0 END)"
            ),
            'failed' => new \Zend_Db_Expr(
                "SUM(CASE WHEN {$field} = '" . QueueStatus::STATUS_FAILED . "' THEN 1 ELSE 0 END)"
            ),
            'blacklisted' => new \Zend_Db_Expr(
                "SUM(CASE WHEN {$field} = '" . QueueStatus::STATUS_BLACKLISTED . "' THEN 1 ELSE 0 END)"
            ),
        ];
    }

    private function normalizeLane(array $row)
    {
        $summary = $this->normalizeSummary($row);
        return $summary + [
            'store_id' => (int)($row['store_id'] ?? 0),
            'work_type' => (string)($row['work_type'] ?? QueueWorkType::TYPE_SCHEDULED),
            'source' => (string)($row['source'] ?? ''),
            'source_instance_key' => (string)($row['source_instance_key'] ?? ''),
            'source_priority' => (int)($row['source_priority'] ?? 100),
            'profile_id' => (int)($row['profile_id'] ?? 0),
            'profile_code' => (string)($row['profile_code'] ?? 'guest'),
            'profile_label' => (string)($row['profile_label'] ?? 'Guest'),
            'mode' => (string)($row['mode'] ?? ''),
            'cycle_started_at' => $row['cycle_started_at'] ?? null,
            'latest_updated_at' => $row['latest_updated_at'] ?? null,
            'covered' => 0,
        ];
    }

    private function normalizeCoveredLane(array $row)
    {
        return array_replace($this->emptySummary(), [
            'store_id' => (int)($row['store_id'] ?? 0),
            'work_type' => (string)($row['work_type'] ?? QueueWorkType::TYPE_SCHEDULED),
            'source' => (string)($row['source'] ?? ''),
            'source_instance_key' => (string)($row['source_instance_key'] ?? ''),
            'source_priority' => (int)($row['source_priority'] ?? 100),
            'profile_id' => (int)($row['profile_id'] ?? 0),
            'profile_code' => (string)($row['profile_code'] ?? 'guest'),
            'profile_label' => (string)($row['profile_label'] ?? 'Guest'),
            'mode' => (string)($row['mode'] ?? ''),
            'cycle_started_at' => null,
            'latest_updated_at' => null,
            'covered' => (int)($row['covered'] ?? 0),
        ]);
    }

    private function applyProgressScope($select, $table, $alias = null)
    {
        if (!$this->columnExists($table, 'work_type')) {
            return;
        }

        $workTypeField = $alias ? $alias . '.work_type' : 'work_type';
        $sourceField = $alias ? $alias . '.source' : 'source';
        $statusField = $alias ? $alias . '.status' : 'status';
        $connection = $this->resource->getConnection();
        $deltaSourceWhere = $connection->quoteInto($sourceField . ' IN (?)', self::DELTA_SOURCES);
        $activeDeltaWhere = $connection->quoteInto(
            $statusField . ' IN (?)',
            [QueueStatus::STATUS_PENDING, QueueStatus::STATUS_RUNNING]
        );
        $select->where(
            sprintf(
                '((%s != %s AND NOT (%s)) OR %s)',
                $workTypeField,
                $connection->quote(QueueWorkType::TYPE_DELTA),
                $deltaSourceWhere,
                $activeDeltaWhere
            )
        );
    }

    private function columnExists($table, $column)
    {
        if (!isset($this->tableColumns[$table])) {
            $this->tableColumns[$table] = $this->resource->getConnection()->describeTable($table);
        }
        return isset($this->tableColumns[$table][$column]);
    }

    private function normalizeSummary(array $row)
    {
        $summary = $this->emptySummary();
        foreach (array_keys($summary) as $key) {
            if (array_key_exists($key, $row)) {
                $summary[$key] = is_numeric($row[$key]) ? (int)$row[$key] : $row[$key];
            }
        }
        $summary['completed'] = $summary['warmed'] + $summary['skipped'] + $summary['failed'] + $summary['blacklisted'];
        $summary['completion_percent'] = $summary['total'] > 0
            ? round(($summary['completed'] / $summary['total']) * 100, 1)
            : 0.0;

        return $summary;
    }

    private function emptySummary()
    {
        return [
            'total' => 0,
            'pending' => 0,
            'running' => 0,
            'warmed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'blacklisted' => 0,
            'urgent' => 0,
            'max_priority' => 0,
            'oldest_pending_at' => null,
            'cycle_started_at' => null,
            'latest_warmed_at' => null,
            'completed' => 0,
            'completion_percent' => 0.0,
            'covered' => 0,
        ];
    }
}
