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
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

class QueueRepository
{
    private const TABLE_URL = 'litemage_warm_url';
    private const TABLE_URL_SOURCE = 'litemage_warm_url_source';
    private const TABLE_QUEUE = 'litemage_warm_queue';
    private const TABLE_PROFILE = 'litemage_warm_profile';
    private const SCHEDULED_SOURCES = ['manual', 'sitemap', 'url_rewrite', 'text_file', 'recently_seen'];
    private const DELTA_SOURCES = ['purge_entity', 'purge_reverse_index', 'purge'];

    /**
     * @var array
     */
    private $tableColumns = [];

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
     * @var CrawlerMode
     */
    private $crawlerMode;

    /**
     * @var UrlSourceRepository
     */
    private $urlSourceRepository;

    /**
     * @var QueueVariantConfig
     */
    private $queueVariantConfig;

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime,
        Config $config,
        CrawlerMode $crawlerMode,
        UrlSourceRepository $urlSourceRepository,
        ?QueueVariantConfig $queueVariantConfig = null
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
        $this->config = $config;
        $this->crawlerMode = $crawlerMode;
        $this->urlSourceRepository = $urlSourceRepository;
        $this->queueVariantConfig = $queueVariantConfig
            ?: ObjectManager::getInstance()->get(QueueVariantConfig::class);
    }

    public function upsertUrl(array $urlData)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_URL);
        $now = $this->dateTime->gmtDate();
        $source = $urlData['source'] ?? 'manual';
        $preserveSourceOwnership = !empty($urlData['preserve_source_ownership']);
        $existingFields = ['source', 'priority', 'interval_seconds'];
        if ($this->columnExists($table, 'source_flags')) {
            $existingFields[] = 'source_flags';
        }
        $existing = $connection->fetchRow(
            $connection->select()
                ->from($table, $existingFields)
                ->where('store_id = ?', (int)$urlData['store_id'])
                ->where('url_hash = ?', $urlData['url_hash'])
                ->limit(1)
        );

        $row = [
            'store_id' => (int)$urlData['store_id'],
            'url_hash' => $urlData['url_hash'],
            'url' => $urlData['url'],
            'source' => ($preserveSourceOwnership && !empty($existing['source'])) ? $existing['source'] : $source,
            'page_type' => $urlData['page_type'] ?? null,
            'entity_type' => $urlData['entity_type'] ?? null,
            'entity_id' => isset($urlData['entity_id']) ? (int)$urlData['entity_id'] : null,
            'priority' => ($preserveSourceOwnership && isset($existing['priority']))
                ? (int)$existing['priority']
                : $this->bestPriority(
                    isset($urlData['priority']) ? (int)$urlData['priority'] : 100,
                    isset($existing['priority']) ? (int)$existing['priority'] : null
                ),
            'interval_seconds' => ($preserveSourceOwnership && isset($existing['interval_seconds']))
                ? (int)$existing['interval_seconds']
                : (isset($urlData['interval_seconds']) ? (int)$urlData['interval_seconds'] : 0),
            'is_active' => isset($urlData['is_active']) ? (int)$urlData['is_active'] : 1,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
        if ($this->columnExists($table, 'source_flags')) {
            $row['source_flags'] = $preserveSourceOwnership && isset($existing['source_flags'])
                ? (string)$existing['source_flags']
                : $this->mergeSourceFlags($existing['source_flags'] ?? '', $source);
        }

        $updateColumns = [
            'url',
            'source',
            'page_type',
            'entity_type',
            'entity_id',
            'priority',
            'interval_seconds',
            'is_active',
            'last_seen_at',
            'updated_at',
        ];
        if ($this->columnExists($table, 'source_flags')) {
            $updateColumns[] = 'source_flags';
        }
        $connection->insertOnDuplicate(
            $table,
            $row + ['created_at' => $now],
            $updateColumns
        );

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($table, 'url_id')
                ->where('store_id = ?', (int)$urlData['store_id'])
                ->where('url_hash = ?', $urlData['url_hash'])
        );
    }

    public function enqueue(array $urlData, $mode, $source = null, $profileId = null, $priority = 0)
    {
        if ($this->isBlacklisted($urlData)) {
            return 0;
        }

        $mode = $this->crawlerMode->normalize($mode);
        $source = $source ?: ($urlData['source'] ?? 'manual');
        $workType = $this->normalizeWorkType($urlData['work_type'] ?? null, $source);
        $isDelta = $workType === QueueWorkType::TYPE_DELTA;
        $sourceData = $this->buildSourceData($urlData, $source, $priority);
        $priority = $sourceData['effective_priority'];
        $urlId = $this->upsertUrl(array_replace($urlData, [
            'source' => $source,
            'priority' => $priority,
            'preserve_source_ownership' => $isDelta ? 1 : 0,
        ]));
        if (!$isDelta) {
            $this->urlSourceRepository->upsert($urlId, $urlData, $source, $sourceData);
        }
        $profileId = $this->normalizeProfileId($profileId);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $now = $this->dateTime->gmtDate();
        $existingId = $this->findWorkQueueId($urlId, $urlData, $mode, $profileId, $workType);
        $urgent = $isDelta || $this->isUrgentSource($source) || !empty($urlData['is_urgent']);

        if ($existingId) {
            $existing = $connection->fetchRow(
                $connection->select()
                    ->from($table, $this->existingColumns($table, [
                        'priority',
                        'source_priority',
                        'url_priority',
                        'source',
                        'work_type',
                        'source_flags',
                        'source_instance_key',
                        'status',
                        'attempts',
                        'is_urgent',
                        'scheduled_at',
                        'next_run_at',
                        'locked_at',
                        'lock_owner',
                        'last_error',
                        'purge_count',
                    ]))
                    ->where('queue_id = ?', $existingId)
            );
            $reactivate = $this->shouldReactivateExistingWork($existing ?: [], $urlData, $urgent);
            $status = $this->getRefreshedStatus($existing ?: [], $reactivate);
            $selectedSourceData = $isDelta
                ? $this->selectDeltaSourceData($existing ?: [], $source, $sourceData)
                : $this->selectQueueSourceData($existing ?: [], $source, $sourceData);
            $selectedSource = $selectedSourceData['source'];
            $selectedPriority = $selectedSourceData['effective_priority'];
            $connection->update(
                $table,
                $this->filterColumns($table, [
                    'url_id' => $urlId,
                    'url' => $urlData['url'],
                    'source' => $selectedSource,
                    'work_type' => $workType,
                    'source_flags' => $this->mergeSourceFlags($existing['source_flags'] ?? '', $source),
                    'source_instance_key' => $selectedSourceData['source_instance_key'],
                    'source_priority' => $selectedSourceData['source_priority'],
                    'url_priority' => $selectedSourceData['url_priority'],
                    'status' => $status,
                    'priority' => (int)$selectedPriority,
                    'is_urgent' => ($urgent || !empty($existing['is_urgent'])) ? 1 : 0,
                    'attempts' => $reactivate ? 0 : (int)($existing['attempts'] ?? 0),
                    'max_attempts' => $this->config->getWarmupMaxAttempts(),
                    'scheduled_at' => $reactivate ? $now : ($existing['scheduled_at'] ?? null),
                    'next_run_at' => $reactivate ? $now : ($existing['next_run_at'] ?? null),
                    'locked_at' => $status === QueueStatus::STATUS_RUNNING ? ($existing['locked_at'] ?? null) : null,
                    'lock_owner' => $status === QueueStatus::STATUS_RUNNING ? ($existing['lock_owner'] ?? null) : null,
                    'last_error' => $reactivate ? null : ($existing['last_error'] ?? null),
                    'last_purge_at' => $isDelta ? $now : null,
                    'purge_count' => $isDelta ? ((int)($existing['purge_count'] ?? 0) + 1) : 0,
                    'updated_at' => $now,
                ]),
                ['queue_id = ?' => $existingId]
            );
            return (int)$existingId;
        }

        $connection->insert($table, $this->filterColumns($table, [
            'url_id' => $urlId,
            'profile_id' => $profileId,
            'store_id' => (int)$urlData['store_id'],
            'url_hash' => $urlData['url_hash'],
            'url' => $urlData['url'],
            'source' => $source,
            'work_type' => $workType,
            'source_flags' => $isDelta ? $source : null,
            'source_instance_key' => $sourceData['source_instance_key'],
            'source_priority' => $sourceData['source_priority'],
            'url_priority' => $sourceData['url_priority'],
            'mode' => $mode,
            'status' => QueueStatus::STATUS_PENDING,
            'priority' => (int)$priority,
            'is_urgent' => $urgent ? 1 : 0,
            'attempts' => 0,
            'max_attempts' => $this->config->getWarmupMaxAttempts(),
            'scheduled_at' => $now,
            'next_run_at' => $now,
            'last_purge_at' => $isDelta ? $now : null,
            'purge_count' => $isDelta ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return (int)$connection->lastInsertId($table);
    }

    private function shouldReactivateExistingWork(array $existing, array $urlData, $urgent)
    {
        if ($urgent || !empty($urlData['force_pending'])) {
            return true;
        }

        $status = $existing['status'] ?? QueueStatus::STATUS_PENDING;
        if (in_array($status, [QueueStatus::STATUS_PENDING, QueueStatus::STATUS_RUNNING], true)) {
            return false;
        }

        $intervalSeconds = isset($urlData['interval_seconds']) ? (int)$urlData['interval_seconds'] : 0;
        if ($intervalSeconds <= 0) {
            return false;
        }
        if (empty($existing['next_run_at'])) {
            return true;
        }

        $nextRunAt = strtotime((string)$existing['next_run_at']);
        return $nextRunAt !== false && $nextRunAt <= time();
    }

    private function buildSourceData(array $urlData, $source, $priority)
    {
        $sourcePriority = isset($urlData['source_priority'])
            ? (int)$urlData['source_priority']
            : $this->config->getWarmupSourcePriority($source);
        $urlPriority = array_key_exists('url_priority', $urlData)
            ? $urlData['url_priority']
            : ($priority === null ? null : (int)$priority);
        $sourceData = [
            'source_instance_key' => $urlData['source_instance_key'] ?? $source,
            'source_priority' => $sourcePriority,
            'url_priority' => $urlPriority,
            'interval_seconds' => $urlData['interval_seconds'] ?? 0,
        ];
        $sourceData['effective_priority'] = isset($urlData['effective_priority'])
            ? (int)$urlData['effective_priority']
            : $this->urlSourceRepository->calculateEffectivePriority($sourcePriority, $urlPriority);

        return $sourceData;
    }

    private function selectQueueSourceData(array $existing, $source, array $sourceData)
    {
        $existingSource = (string)($existing['source'] ?? '');
        $existingInstance = (string)($existing['source_instance_key'] ?? $existingSource);
        $currentInstance = (string)($sourceData['source_instance_key'] ?? $source);
        $currentPriority = (int)$sourceData['effective_priority'];
        $existingPriority = isset($existing['priority']) ? (int)$existing['priority'] : null;

        if ($existingSource === (string)$source && $existingInstance === $currentInstance) {
            return ['source' => (string)$source] + $sourceData;
        }

        if ($existingPriority === null || $currentPriority < $existingPriority) {
            return ['source' => (string)$source] + $sourceData;
        }

        return [
            'source' => $existingSource ?: (string)$source,
            'source_instance_key' => $existingInstance ?: $currentInstance,
            'source_priority' => isset($existing['source_priority'])
                ? (int)$existing['source_priority']
                : (int)$sourceData['source_priority'],
            'url_priority' => array_key_exists('url_priority', $existing)
                ? ($existing['url_priority'] === null ? null : (int)$existing['url_priority'])
                : $sourceData['url_priority'],
            'interval_seconds' => $sourceData['interval_seconds'] ?? 0,
            'effective_priority' => $existingPriority,
        ];
    }

    private function selectDeltaSourceData(array $existing, $source, array $sourceData)
    {
        $existingSource = (string)($existing['source'] ?? '');
        if ($existingSource === '' || $this->deltaSourceRank($source) < $this->deltaSourceRank($existingSource)) {
            return ['source' => (string)$source] + $sourceData;
        }

        return [
            'source' => $existingSource,
            'source_instance_key' => (string)($existing['source_instance_key'] ?? ($sourceData['source_instance_key'] ?? $source)),
            'source_priority' => isset($existing['source_priority'])
                ? (int)$existing['source_priority']
                : (int)$sourceData['source_priority'],
            'url_priority' => array_key_exists('url_priority', $existing)
                ? ($existing['url_priority'] === null ? null : (int)$existing['url_priority'])
                : $sourceData['url_priority'],
            'interval_seconds' => 0,
            'effective_priority' => $this->bestPriority(
                (int)$sourceData['effective_priority'],
                isset($existing['priority']) ? (int)$existing['priority'] : null
            ),
        ];
    }

    private function deltaSourceRank($source)
    {
        switch ((string)$source) {
            case 'purge_entity':
                return 10;
            case 'purge_reverse_index':
                return 20;
            default:
                return 100;
        }
    }

    private function getRefreshedStatus(array $existing, $reactivate)
    {
        $status = $existing['status'] ?? QueueStatus::STATUS_PENDING;
        if ($status === QueueStatus::STATUS_RUNNING) {
            return QueueStatus::STATUS_RUNNING;
        }

        return $reactivate ? QueueStatus::STATUS_PENDING : $status;
    }

    private function findWorkQueueId($urlId, array $urlData, $mode, $profileId, $workType = QueueWorkType::TYPE_SCHEDULED)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $select = $connection->select()
            ->from($table, 'queue_id')
            ->where('mode = ?', $mode)
            ->order('queue_id DESC')
            ->limit(1);
        if ($this->columnExists($table, 'store_id') && $this->columnExists($table, 'url_hash')) {
            $select->where('store_id = ?', (int)$urlData['store_id'])
                ->where('url_hash = ?', $urlData['url_hash']);
        } elseif ((int)$urlId > 0) {
            $select->where('url_id = ?', (int)$urlId);
        }
        if ($this->columnExists($table, 'work_type')) {
            $select->where('work_type = ?', $this->normalizeWorkType($workType, $urlData['source'] ?? null));
        }
        $this->addProfileWhere($select, $this->normalizeProfileId($profileId));
        return (int)$connection->fetchOne($select);
    }

    private function buildDueSelect($table, $now, array $filters = [])
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($table)
            ->where(sprintf(
                '((status = %s AND (next_run_at IS NULL OR next_run_at <= %s))'
                    . ' OR (status = %s AND next_run_at IS NOT NULL AND next_run_at <= %s))',
                $connection->quote(QueueStatus::STATUS_PENDING),
                $connection->quote($now),
                $connection->quote(QueueStatus::STATUS_WARMED),
                $connection->quote($now)
            ))
            ->order($this->dueOrder($table));
        if (!empty($filters['mode'])) {
            $select->where('mode = ?', $filters['mode']);
        }
        if (array_key_exists('profile_id', $filters) && $filters['profile_id'] !== null) {
            $this->addProfileWhere($select, $this->normalizeProfileId($filters['profile_id']));
        }
        if (array_key_exists('store_id', $filters) && $filters['store_id'] !== null) {
            $select->where('store_id = ?', (int)$filters['store_id']);
        }
        if (!empty($filters['exclude_lanes']) && is_array($filters['exclude_lanes'])) {
            $this->addExcludedLanesWhere($select, $filters['exclude_lanes']);
        }
        return $select;
    }

    public function claimDue($limit, $lockOwner, array $filters = [])
    {
        $limit = max(1, (int)$limit);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $now = $this->dateTime->gmtDate();
        $lockOwner = substr((string)$lockOwner, 0, 64);
        $this->releaseStaleLocks();

        $connection->beginTransaction();
        try {
            $lane = $connection->fetchRow(
                $this->forUpdate($this->buildDueSelect($table, $now, $filters)->limit(1))
            );
            if (!$lane) {
                $connection->commit();
                return [];
            }

            $select = $this->buildDueSelect($table, $now, $filters)
                ->where('mode = ?', $lane['mode'])
                ->where('store_id = ?', (int)$lane['store_id'])
                ->order($this->dueOrder($table))
                ->limit($limit);
            $this->addProfileWhere($select, $this->normalizeProfileId($lane['profile_id'] ?? null));
            $rows = $connection->fetchAll($this->forUpdate($select));
            if (!$rows) {
                $connection->commit();
                return [];
            }

            $ids = array_map(function ($row) {
                return (int)$row['queue_id'];
            }, $rows);
            $connection->update(
                $table,
                $this->filterColumns($table, [
                    'status' => QueueStatus::STATUS_RUNNING,
                    'locked_at' => $now,
                    'lock_owner' => $lockOwner,
                    'updated_at' => $now,
                ]),
                [
                    'queue_id IN (?)' => $ids,
                    'status IN (?)' => [QueueStatus::STATUS_PENDING, QueueStatus::STATUS_WARMED],
                ]
            );

            $claimedRows = $connection->fetchAll(
                $connection->select()
                    ->from($table)
                    ->where('queue_id IN (?)', $ids)
                    ->where('status = ?', QueueStatus::STATUS_RUNNING)
                    ->where('lock_owner = ?', $lockOwner)
                    ->order(['priority ASC', 'queue_id ASC'])
            );
            $connection->commit();
            return $claimedRows;
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function markSuccess($queueId, $resultId, $cacheStatus = null, $claimedWarmupRound = null)
    {
        $now = $this->dateTime->gmtDate();
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['q' => $queueTable], $this->existingColumns($queueTable, [
                    'url_id',
                    'source',
                    'work_type',
                    'source_instance_key',
                    'warmup_round',
                ]))
                ->joinLeft(['u' => $urlTable], 'u.url_id = q.url_id', ['interval_seconds'])
                ->where('q.queue_id = ?', (int)$queueId)
        );
        if ($this->resetIfStaleScheduledRound($queueId, $claimedWarmupRound, $resultId, $row ?: [])) {
            return false;
        }
        $nextRunAt = null;
        if ($row && (int)($row['interval_seconds'] ?? 0) > 0) {
            $nextRunAt = $this->dateTime->gmtDate('Y-m-d H:i:s', time() + (int)$row['interval_seconds']);
        }

        $this->updateStatus($queueId, $nextRunAt === null ? QueueStatus::STATUS_WARMED : QueueStatus::STATUS_PENDING, [
            'last_result_id' => (int)$resultId,
            'last_warmed_at' => $now,
            'last_cache_status' => $cacheStatus,
            'next_run_at' => $nextRunAt,
            'attempts' => 0,
            'is_urgent' => 0,
            'last_error' => null,
        ]);
        if ($row && !empty($row['url_id'])) {
            $connection->update(
                $urlTable,
                $this->filterColumns($urlTable, ['last_warmed_at' => $now, 'updated_at' => $now]),
                ['url_id = ?' => (int)$row['url_id']]
            );
        }
        if ($row && ($row['work_type'] ?? QueueWorkType::TYPE_SCHEDULED) === QueueWorkType::TYPE_DELTA) {
            $connection->delete($queueTable, ['queue_id = ?' => (int)$queueId]);
        }
        return true;
    }

    public function markSkipped($queueId, $resultId, $reason, $claimedWarmupRound = null)
    {
        $row = $this->getQueueRoundRow($queueId, ['work_type', 'warmup_round']);
        if ($this->resetIfStaleScheduledRound($queueId, $claimedWarmupRound, $resultId, $row)) {
            return false;
        }
        $workType = $this->normalizeWorkType($row['work_type'] ?? null, null);
        $this->updateStatus($queueId, QueueStatus::STATUS_SKIPPED, [
            'last_result_id' => (int)$resultId,
            'is_urgent' => 0,
            'last_error' => $reason,
        ]);
        if ($workType === QueueWorkType::TYPE_DELTA) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(self::TABLE_QUEUE),
                ['queue_id = ?' => (int)$queueId]
            );
        }
        return true;
    }

    public function markGone($queueId, $resultId, $reason, $claimedWarmupRound = null)
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $now = $this->dateTime->gmtDate();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($queueTable, $this->existingColumns($queueTable, [
                    'url_id',
                    'store_id',
                    'url_hash',
                    'work_type',
                    'warmup_round',
                ]))
                ->where('queue_id = ?', (int)$queueId)
        );
        if ($this->resetIfStaleScheduledRound($queueId, $claimedWarmupRound, $resultId, $row ?: [])) {
            return false;
        }

        if (!$row) {
            $connection->update(
                $queueTable,
                $this->filterColumns($queueTable, [
                    'status' => QueueStatus::STATUS_SKIPPED,
                    'next_run_at' => null,
                    'locked_at' => null,
                    'lock_owner' => null,
                    'last_result_id' => (int)$resultId,
                    'is_urgent' => 0,
                    'last_error' => $reason,
                    'updated_at' => $now,
                ]),
                ['queue_id = ?' => (int)$queueId]
            );
            return true;
        }

        $connection->update(
            $queueTable,
            $this->filterColumns($queueTable, [
                'status' => QueueStatus::STATUS_SKIPPED,
                'next_run_at' => null,
                'locked_at' => null,
                'lock_owner' => null,
                'last_result_id' => (int)$resultId,
                'is_urgent' => 0,
                'last_error' => $reason,
                'updated_at' => $now,
            ]),
            [
                'store_id = ?' => (int)$row['store_id'],
                'url_hash = ?' => $row['url_hash'],
                'status IN (?)' => [
                    QueueStatus::STATUS_PENDING,
                    QueueStatus::STATUS_RUNNING,
                    QueueStatus::STATUS_FAILED,
                ],
            ]
        );

        $where = [];
        if (!empty($row['url_id'])) {
            $where['url_id = ?'] = (int)$row['url_id'];
        } else {
            $where['store_id = ?'] = (int)$row['store_id'];
            $where['url_hash = ?'] = $row['url_hash'];
        }

        $connection->update(
            $urlTable,
            $this->filterColumns($urlTable, [
                'is_active' => 0,
                'updated_at' => $now,
            ]),
            $where
        );
        if (($row['work_type'] ?? QueueWorkType::TYPE_SCHEDULED) === QueueWorkType::TYPE_DELTA) {
            $connection->delete($queueTable, ['queue_id = ?' => (int)$queueId]);
        }
        return true;
    }

    public function markFailure($queueId, $resultId, $error, $retryAt = null, $claimedWarmupRound = null)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, $this->existingColumns($table, [
                    'attempts',
                    'max_attempts',
                    'work_type',
                    'warmup_round',
                ]))
                ->where('queue_id = ?', (int)$queueId)
        );
        if ($this->resetIfStaleScheduledRound($queueId, $claimedWarmupRound, $resultId, $row ?: [])) {
            return false;
        }
        $attempts = isset($row['attempts']) ? ((int)$row['attempts'] + 1) : 1;
        $maxAttempts = isset($row['max_attempts']) ? (int)$row['max_attempts'] : $this->config->getWarmupMaxAttempts();
        $status = ($attempts >= $maxAttempts) ? QueueStatus::STATUS_FAILED : QueueStatus::STATUS_PENDING;
        $retryAt = $retryAt ?: $this->dateTime->gmtDate('Y-m-d H:i:s', time() + min(3600, 60 * $attempts));

        $connection->update(
            $table,
            $this->filterColumns($table, [
                'status' => $status,
                'attempts' => $attempts,
                'is_urgent' => ($status === QueueStatus::STATUS_PENDING) ? new \Zend_Db_Expr('is_urgent') : 0,
                'next_run_at' => ($status === QueueStatus::STATUS_PENDING) ? $retryAt : null,
                'locked_at' => null,
                'lock_owner' => null,
                'last_result_id' => (int)$resultId,
                'last_error' => $error,
                'updated_at' => $this->dateTime->gmtDate(),
            ]),
            ['queue_id = ?' => (int)$queueId]
        );
        if ($status === QueueStatus::STATUS_FAILED
            && ($row['work_type'] ?? QueueWorkType::TYPE_SCHEDULED) === QueueWorkType::TYPE_DELTA
        ) {
            $connection->delete($table, ['queue_id = ?' => (int)$queueId]);
        }
        return true;
    }

    public function release(array $queueIds)
    {
        $queueIds = array_filter(array_map('intval', $queueIds));
        if (!$queueIds) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE_QUEUE),
            $this->filterColumns($this->resource->getTableName(self::TABLE_QUEUE), [
                'status' => QueueStatus::STATUS_PENDING,
                'locked_at' => null,
                'lock_owner' => null,
                'updated_at' => $this->dateTime->gmtDate(),
            ]),
            ['queue_id IN (?)' => $queueIds]
        );
    }

    public function releaseStaleLocks($olderThanSeconds = null)
    {
        $olderThanSeconds = $olderThanSeconds ?: max(300, $this->config->getWarmupMaxRuntime() * 2);
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE_QUEUE),
            $this->filterColumns($this->resource->getTableName(self::TABLE_QUEUE), [
                'status' => QueueStatus::STATUS_PENDING,
                'locked_at' => null,
                'lock_owner' => null,
                'updated_at' => $this->dateTime->gmtDate(),
            ]),
            [
                'status = ?' => QueueStatus::STATUS_RUNNING,
                'locked_at < ?' => $this->dateTime->gmtDate('Y-m-d H:i:s', time() - (int)$olderThanSeconds),
            ]
        );
    }

    public function cleanupCompleted($olderThanDays)
    {
        return 0;
    }

    public function clearDeltaWork($includeRunning = false)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($table) || !$this->columnExists($table, 'work_type')) {
            return 0;
        }

        $where = [
            '('
            . $connection->quoteInto('work_type = ?', QueueWorkType::TYPE_DELTA)
            . ' OR '
            . $connection->quoteInto('source IN (?)', self::DELTA_SOURCES)
            . ')',
        ];
        if (!$includeRunning) {
            $where[] = $connection->quoteInto('status != ?', QueueStatus::STATUS_RUNNING);
        }

        return (int)$connection->delete($table, $where);
    }

    public function restartScheduledWork(array $storeIds = [])
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($queueTable)) {
            return 0;
        }

        $connection->delete($queueTable, ['source = ?' => 'purge_broad']);

        $baseWhere = [
            $connection->quoteInto('status != ?', QueueStatus::STATUS_BLACKLISTED),
        ];
        if ($this->columnExists($queueTable, 'work_type')) {
            $baseWhere[] = $connection->quoteInto('work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($storeIds) {
            $baseWhere[] = $connection->quoteInto('store_id IN (?)', $storeIds);
        }
        if ($this->columnExists($queueTable, 'url_id')) {
            $urlTable = $this->resource->getTableName(self::TABLE_URL);
            if ($connection->isTableExists($urlTable)) {
                $baseWhere[] = 'url_id IN (SELECT url_id FROM ' . $connection->quoteIdentifier($urlTable)
                    . ' WHERE is_active = 1 AND is_blacklisted = 0)';
            }
        }

        $now = $this->dateTime->gmtDate();
        $matchedCount = $this->countQueueRows($baseWhere);
        $changedWhere = $baseWhere;
        $changedWhere[] = $connection->quoteInto('status != ?', QueueStatus::STATUS_RUNNING);
        $changedWhere[] = '(' . implode(' OR ', [
            $connection->quoteInto('status != ?', QueueStatus::STATUS_PENDING),
            'attempts != 0',
            'locked_at IS NOT NULL',
            'lock_owner IS NOT NULL',
            'last_error IS NOT NULL',
            'is_urgent != 0',
            'next_run_at IS NULL',
            $connection->quoteInto('next_run_at > ?', $now),
        ]) . ')';
        $changedCount = $this->countQueueRows($changedWhere);
        if ($this->columnExists($queueTable, 'warmup_round')) {
            $connection->update(
                $queueTable,
                $this->filterColumns($queueTable, [
                    'warmup_round' => new \Zend_Db_Expr('warmup_round + 1'),
                    'updated_at' => $now,
                ]),
                $baseWhere
            );
        }

        $restartWhere = $baseWhere;
        $restartWhere[] = $connection->quoteInto('status != ?', QueueStatus::STATUS_RUNNING);
        $connection->update(
            $queueTable,
            $this->filterColumns($queueTable, [
                'status' => QueueStatus::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => $this->config->getWarmupMaxAttempts(),
                'scheduled_at' => $now,
                'next_run_at' => $now,
                'locked_at' => null,
                'lock_owner' => null,
                'last_error' => null,
                'is_urgent' => 0,
                'updated_at' => $now,
            ]),
            $restartWhere
        );

        return [
            'matched' => $matchedCount,
            'changed' => $changedCount,
        ];
    }

    public function truncate($failedOnly = false)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if ($failedOnly) {
            return $connection->delete($table, ['status = ?' => QueueStatus::STATUS_FAILED]);
        }

        return $connection->delete($table);
    }

    public function retryFailed()
    {
        $connection = $this->resource->getConnection();
        return $connection->update(
            $this->resource->getTableName(self::TABLE_QUEUE),
            $this->filterColumns($this->resource->getTableName(self::TABLE_QUEUE), [
                'status' => QueueStatus::STATUS_PENDING,
                'attempts' => 0,
                'next_run_at' => $this->dateTime->gmtDate(),
                'locked_at' => null,
                'lock_owner' => null,
                'last_error' => null,
                'updated_at' => $this->dateTime->gmtDate(),
            ]),
            ['status = ?' => QueueStatus::STATUS_FAILED]
        );
    }

    public function enqueueKnownUrls($mode, $source, $limitPerStore, $priorityBoost = 0, array $profileIds = [null])
    {
        $limitPerStore = (int)$limitPerStore;
        if ($limitPerStore <= 0) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $storeIds = $connection->fetchCol(
            $connection->select()
                ->from($urlTable, 'store_id')
                ->where('is_active = ?', 1)
                ->group('store_id')
        );

        $queued = 0;
        foreach ($storeIds as $storeId) {
            $rows = $this->getKnownUrlRowsByPriority((int)$storeId, $limitPerStore);
            foreach ($rows as $row) {
                foreach ($profileIds as $profileId) {
                    $this->enqueue(
                        $row + ['is_urgent' => $this->isUrgentSource($source) ? 1 : 0],
                        $mode,
                        $source,
                        $profileId,
                        (int)$row['priority'] + (int)$priorityBoost
                    );
                    $queued++;
                }
            }
        }

        return $queued;
    }

    private function getKnownUrlRowsByPriority($storeId, $limitPerStore)
    {
        $connection = $this->resource->getConnection();
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);

        if (!$connection->isTableExists($sourceTable)) {
            return $connection->fetchAll(
                $connection->select()
                    ->from($urlTable)
                    ->where('store_id = ?', (int)$storeId)
                    ->where('is_active = ?', 1)
                    ->where('is_blacklisted = ?', 0)
                    ->order(['priority ASC', 'last_warmed_at ASC', 'url_id ASC'])
                    ->limit($limitPerStore)
            );
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['u' => $urlTable])
                ->joinInner(
                    ['s' => $sourceTable],
                    's.url_id = u.url_id AND s.is_active = 1',
                    [
                        'source_code' => 's.source_code',
                        'source_instance_key' => 's.source_instance_key',
                        'source_priority' => 's.source_priority',
                        'url_priority' => 's.url_priority',
                        'priority' => 's.effective_priority',
                    ]
                )
                ->where('u.store_id = ?', (int)$storeId)
                ->where('u.is_active = ?', 1)
                ->where('u.is_blacklisted = ?', 0)
                ->order([
                    's.source_priority ASC',
                    's.effective_priority ASC',
                    'u.last_warmed_at ASC',
                    'u.url_id ASC',
                ])
                ->limit(max($limitPerStore, $limitPerStore * 10))
        );

        $deduped = [];
        foreach ($rows as $row) {
            $urlId = (int)$row['url_id'];
            if (isset($deduped[$urlId])) {
                continue;
            }
            $deduped[$urlId] = $row;
            if (count($deduped) >= $limitPerStore) {
                break;
            }
        }

        return array_values($deduped);
    }

    public function isBlacklisted(array $urlData)
    {
        $connection = $this->resource->getConnection();
        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE_URL), 'url_id')
                ->where('store_id = ?', (int)$urlData['store_id'])
                ->where('url_hash = ?', $urlData['url_hash'])
                ->where('is_blacklisted = ?', 1)
                ->limit(1)
        );
    }

    public function setBlacklisted(array $urlData, $blacklisted)
    {
        $connection = $this->resource->getConnection();
        $urlTable = $this->resource->getTableName(self::TABLE_URL);
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $now = $this->dateTime->gmtDate();
        $row = [
            'store_id' => (int)$urlData['store_id'],
            'url_hash' => $urlData['url_hash'],
            'url' => $urlData['url'],
            'source' => $urlData['source'] ?? 'manual',
            'page_type' => $urlData['page_type'] ?? 'manual',
            'priority' => isset($urlData['priority']) ? (int)$urlData['priority'] : 0,
            'interval_seconds' => isset($urlData['interval_seconds']) ? (int)$urlData['interval_seconds'] : 0,
            'is_active' => $blacklisted ? 0 : 1,
            'is_blacklisted' => $blacklisted ? 1 : 0,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
        if ($this->columnExists($urlTable, 'source_flags')) {
            $row['source_flags'] = $this->mergeSourceFlags('', $urlData['source'] ?? 'manual');
        }
        $updateColumns = ['url', 'is_active', 'is_blacklisted', 'updated_at'];
        if ($this->columnExists($urlTable, 'source_flags')) {
            $updateColumns[] = 'source_flags';
        }
        $connection->insertOnDuplicate(
            $urlTable,
            $row + ['created_at' => $now],
            $updateColumns
        );

        $queueStatus = $blacklisted ? QueueStatus::STATUS_BLACKLISTED : QueueStatus::STATUS_PENDING;
        $connection->update(
            $queueTable,
            $this->filterColumns($queueTable, [
                'status' => $queueStatus,
                'locked_at' => null,
                'lock_owner' => null,
                'last_error' => $blacklisted ? 'URL blacklisted.' : null,
                'updated_at' => $now,
            ]),
            [
                'store_id = ?' => (int)$urlData['store_id'],
                'url_hash = ?' => $urlData['url_hash'],
                'status IN (?)' => [
                    QueueStatus::STATUS_PENDING,
                    QueueStatus::STATUS_RUNNING,
                    QueueStatus::STATUS_FAILED,
                    QueueStatus::STATUS_BLACKLISTED,
                ],
            ]
        );
    }

    public function getStatusSummary($storeId = null, $source = null, $failedOnly = false)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $select = $connection->select()
            ->from($table, ['status', 'count' => new \Zend_Db_Expr('COUNT(*)')])
            ->group('status')
            ->order('status ASC');
        if ($storeId !== null) {
            $select->where('store_id = ?', (int)$storeId);
        }
        if ($source !== null && $source !== '') {
            $select->where('source = ?', $source);
        }
        if ($failedOnly) {
            $select->where('status = ?', QueueStatus::STATUS_FAILED);
        }
        if ($this->columnExists($table, 'work_type')) {
            $select->where('work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }
        $summary = [];
        foreach ($connection->fetchAll($select) as $row) {
            $summary[$row['status']] = (int)$row['count'];
        }
        return $summary;
    }

    public function getWorkActionCounts()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($table)) {
            return [
                'total' => 0,
                'pending' => 0,
                'due_pending' => 0,
                'running' => 0,
                'stale_running' => 0,
                'failed' => 0,
            ];
        }

        $staleSeconds = max(300, $this->config->getWarmupMaxRuntime() * 2);
        $staleBefore = $this->dateTime->gmtDate('Y-m-d H:i:s', time() - (int)$staleSeconds);
        $now = $this->dateTime->gmtDate();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, [
                    'total' => new \Zend_Db_Expr('COUNT(*)'),
                    'pending' => new \Zend_Db_Expr(
                        "SUM(CASE WHEN status = '" . QueueStatus::STATUS_PENDING . "' THEN 1 ELSE 0 END)"
                    ),
                    'due_pending' => new \Zend_Db_Expr(
                        "SUM(CASE WHEN status = '" . QueueStatus::STATUS_PENDING . "'"
                        . $connection->quoteInto(' AND (next_run_at IS NULL OR next_run_at <= ?)', $now)
                        . ' THEN 1 ELSE 0 END)'
                    ),
                    'running' => new \Zend_Db_Expr(
                        "SUM(CASE WHEN status = '" . QueueStatus::STATUS_RUNNING . "' THEN 1 ELSE 0 END)"
                    ),
                    'stale_running' => new \Zend_Db_Expr(
                        "SUM(CASE WHEN status = '" . QueueStatus::STATUS_RUNNING . "'"
                        . $connection->quoteInto(' AND locked_at < ?', $staleBefore)
                        . ' THEN 1 ELSE 0 END)'
                    ),
                    'failed' => new \Zend_Db_Expr(
                        "SUM(CASE WHEN status = '" . QueueStatus::STATUS_FAILED . "' THEN 1 ELSE 0 END)"
                    ),
                ])
        ) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
            'due_pending' => (int)($row['due_pending'] ?? 0),
            'running' => (int)($row['running'] ?? 0),
            'stale_running' => (int)($row['stale_running'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
        ];
    }

    public function getRecent($limit = 100, array $filters = [])
    {
        return $this->getPage($limit, 0, $filters);
    }

    public function getPage($limit = 100, $offset = 0, array $filters = [])
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $select = $connection->select()
            ->from($table)
            ->order($this->adminQueueOrder($table))
            ->limit(max(1, (int)$limit), max(0, (int)$offset));
        $this->applyQueueGridScope($select);

        return $this->hydrateSourceMemberships($this->hydrateDisplaySourceInstances($connection->fetchAll(
            $this->applyAdminFilters($select, $filters)
        )));
    }

    public function getSourceQueueFilterOptions($limit = 200)
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($queueTable)) {
            return [];
        }

        $sourceInstanceKey = new \Zend_Db_Expr('COALESCE(q.source_instance_key, q.source)');
        $sourceInstanceJoin = $this->getSourceInstanceJoin();
        if ($sourceInstanceJoin !== null) {
            $sourceInstanceKey = $this->getResolvedSourceInstanceExpr();
        }

        $select = $connection->select()
            ->from(['q' => $queueTable], [
                'source' => 'q.source',
                'source_instance_key' => $sourceInstanceKey,
                'rows' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->where('q.source IS NOT NULL')
            ->where('q.source != ?', '')
            ->group(['q.source', $sourceInstanceKey])
            ->order(['q.source ASC', 'source_instance_key ASC'])
            ->limit(max(1, (int)$limit));
        if ($this->columnExists($queueTable, 'work_type')) {
            $select->where('q.work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }

        if ($sourceInstanceJoin !== null) {
            $select->joinLeft(
                ['us' => $sourceInstanceJoin],
                'us.url_id = q.url_id AND us.source_code = q.source',
                []
            );
        }

        $options = [];
        foreach ($connection->fetchAll($select) as $row) {
            $source = (string)($row['source'] ?? '');
            $instance = (string)($row['source_instance_key'] ?? '');
            if ($source === '') {
                continue;
            }
            $options[$source . '|' . ($instance !== '' ? $instance : $source)] = [
                'source' => $source,
                'source_instance_key' => $instance !== '' ? $instance : $source,
                'rows' => (int)($row['rows'] ?? 0),
            ];
        }

        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if ($connection->isTableExists($sourceTable)) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from($sourceTable, [
                        'source' => 'source_code',
                        'source_instance_key',
                        'rows' => new \Zend_Db_Expr('COUNT(*)'),
                    ])
                    ->where('is_active = ?', 1)
                    ->group(['source_code', 'source_instance_key'])
                    ->order(['source_code ASC', 'source_instance_key ASC'])
                    ->limit(max(1, (int)$limit))
            ) as $row) {
                $source = (string)($row['source'] ?? '');
                $instance = (string)($row['source_instance_key'] ?? '');
                if ($source === '') {
                    continue;
                }
                $key = $source . '|' . ($instance !== '' ? $instance : $source);
                if (!isset($options[$key])) {
                    $options[$key] = [
                        'source' => $source,
                        'source_instance_key' => $instance !== '' ? $instance : $source,
                        'rows' => (int)($row['rows'] ?? 0),
                    ];
                }
            }
        }

        return array_values($options);
    }

    public function getTotalCount(array $filters = [])
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE_QUEUE), new \Zend_Db_Expr('COUNT(*)'));
        $this->applyQueueGridScope($select);

        return (int)$connection->fetchOne(
            $this->applyAdminFilters($select, $filters)
        );
    }

    public function getDistinctFilterValues($column, $limit = 200)
    {
        $allowedColumns = [
            'store_id',
            'mode',
            'status',
            'source',
            'source_instance_key',
            'priority',
        ];
        if (!in_array((string)$column, $allowedColumns, true)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($table) || !$this->columnExists($table, $column)) {
            return [];
        }

        $select = $connection->select()
                ->from($table, $column)
                ->where($column . ' IS NOT NULL')
                ->where($column . ' != ?', '')
                ->group($column)
                ->order($column . ' ASC')
                ->limit(max(1, (int)$limit));
        if ($this->columnExists($table, 'work_type')) {
            $select->where('work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }

        $rows = $connection->fetchCol($select);

        return array_values(array_filter($rows, function ($value) {
            return $value !== null && $value !== '';
        }));
    }

    public function getSourceCounts()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($table) || !$this->columnExists($table, 'source')) {
            return [];
        }

        $counts = [];
        $select = $connection->select()
                ->from($table, [
                    'source',
                    'rows' => new \Zend_Db_Expr('COUNT(*)'),
                ])
                ->group('source')
                ->order('source ASC');
        if ($this->columnExists($table, 'work_type')) {
            $select->where('work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }

        foreach ($connection->fetchAll($select) as $row) {
            $source = (string)($row['source'] ?? '');
            if ($source !== '') {
                $counts[$source] = (int)($row['rows'] ?? 0);
            }
        }

        return $counts;
    }

    public function getQueueInstanceSummaries()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$connection->isTableExists($table)) {
            return [];
        }

        $summaries = [];
        $select = $connection->select()
                ->from($table, [
                    'source',
                    'source_instance_key' => new \Zend_Db_Expr('COALESCE(source_instance_key, source)'),
                    'rows' => new \Zend_Db_Expr('COUNT(*)'),
                    'profiles' => new \Zend_Db_Expr('COUNT(DISTINCT COALESCE(profile_id, 0))'),
                ])
                ->group(['source', new \Zend_Db_Expr('COALESCE(source_instance_key, source)')]);
        if ($this->columnExists($table, 'work_type')) {
            $select->where('work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }

        foreach ($connection->fetchAll($select) as $row) {
            $source = (string)($row['source'] ?? '');
            $instance = (string)($row['source_instance_key'] ?? '');
            if ($source === '') {
                continue;
            }
            $summaries[$source . '|' . $instance] = [
                'rows' => (int)($row['rows'] ?? 0),
                'profiles' => (int)($row['profiles'] ?? 0),
            ];
        }

        return $summaries;
    }

    public function getDisabledScheduledSourceCounts(array $enabledSources)
    {
        $enabled = array_fill_keys(array_map('strval', $enabledSources), true);
        $counts = [];
        foreach ($this->getSourceCounts() as $source => $rows) {
            if (in_array($source, self::SCHEDULED_SOURCES, true) && !isset($enabled[$source])) {
                $counts[$source] = (int)$rows;
            }
        }

        $connection = $this->resource->getConnection();
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if ($connection->isTableExists($sourceTable)) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from($sourceTable, [
                        'source_code',
                        'rows' => new \Zend_Db_Expr('COUNT(*)'),
                    ])
                    ->where('is_active = ?', 1)
                    ->group('source_code')
            ) as $row) {
                $source = (string)($row['source_code'] ?? '');
                if (in_array($source, self::SCHEDULED_SOURCES, true) && !isset($enabled[$source])) {
                    $counts[$source] = ($counts[$source] ?? 0) + (int)($row['rows'] ?? 0);
                }
            }
        }

        return $counts;
    }

    public function deleteDisabledScheduledSourceWork(array $enabledSources, array $storeIds = [])
    {
        $enabled = array_fill_keys(array_map('strval', $enabledSources), true);
        $disabledSources = array_values(array_filter(self::SCHEDULED_SOURCES, function ($source) use ($enabled) {
            return !isset($enabled[$source]);
        }));
        if (!$disabledSources) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        $queueWhere = [$connection->quoteInto('source IN (?)', $disabledSources)];
        $sourceWhere = [
            $connection->quoteInto('source_code IN (?)', $disabledSources),
            $connection->quoteInto('is_active = ?', 1),
        ];
        if ($storeIds) {
            $queueWhere[] = $connection->quoteInto('store_id IN (?)', $storeIds);
            $sourceWhere[] = $connection->quoteInto('store_id IN (?)', $storeIds);
        }

        $deleted = (int)$connection->delete($this->resource->getTableName(self::TABLE_QUEUE), $queueWhere);
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if ($connection->isTableExists($sourceTable)) {
            $deleted += (int)$connection->update(
                $sourceTable,
                ['is_active' => 0, 'updated_at' => $this->dateTime->gmtDate()],
                $sourceWhere
            );
        }

        return $deleted;
    }

    public function deleteStaleSourceInstanceWork($source, array $activeInstanceKeys, array $storeIds = [])
    {
        $source = (string)$source;
        if (!in_array($source, self::SCHEDULED_SOURCES, true) || !$activeInstanceKeys) {
            return 0;
        }

        $activeInstanceKeys = array_values(array_unique(array_filter(array_map('strval', $activeInstanceKeys), function ($value) {
            return $value !== '';
        })));
        if (!$activeInstanceKeys) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $where = [
            $connection->quoteInto('source = ?', $source),
            $connection->quoteInto('COALESCE(source_instance_key, source) NOT IN (?)', $activeInstanceKeys),
        ];

        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($storeIds) {
            $where[] = $connection->quoteInto('store_id IN (?)', $storeIds);
        }

        $deleted = (int)$connection->delete($table, $where);
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if ($connection->isTableExists($sourceTable)) {
            $sourceWhere = [
                $connection->quoteInto('source_code = ?', $source),
                $connection->quoteInto('source_instance_key NOT IN (?)', $activeInstanceKeys),
                $connection->quoteInto('is_active = ?', 1),
            ];
            if ($storeIds) {
                $sourceWhere[] = $connection->quoteInto('store_id IN (?)', $storeIds);
            }
            $deleted += (int)$connection->update(
                $sourceTable,
                ['is_active' => 0, 'updated_at' => $this->dateTime->gmtDate()],
                $sourceWhere
            );
        }

        return $deleted;
    }

    public function deleteSourceInstanceWork($source, $sourceInstanceKey, array $storeIds = [])
    {
        return $this->deleteSourceInstanceWhere($source, $sourceInstanceKey, [], $storeIds, true);
    }

    public function deleteStaleSourceInstanceVariantWork(
        $source,
        $sourceInstanceKey,
        array $activeProfileIds,
        array $storeIds = []
    ) {
        return $this->deleteSourceInstanceWhere($source, $sourceInstanceKey, $activeProfileIds, $storeIds, false);
    }

    public function getProfileFilterOptions($limit = 200)
    {
        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $profileTable = $this->resource->getTableName(self::TABLE_PROFILE);
        if (!$connection->isTableExists($queueTable)) {
            return [];
        }

        $profileIds = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($queueTable, new \Zend_Db_Expr('COALESCE(profile_id, 0)'))
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

    private function deleteSourceInstanceWhere(
        $source,
        $sourceInstanceKey,
        array $activeProfileIds,
        array $storeIds,
        $deleteAll
    ) {
        $source = (string)$source;
        if (!in_array($source, self::SCHEDULED_SOURCES, true)) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        $where = [
            $connection->quoteInto('source = ?', $source),
            $connection->quoteInto('COALESCE(source_instance_key, source) = ?', (string)$sourceInstanceKey),
        ];

        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($storeIds) {
            $where[] = $connection->quoteInto('store_id IN (?)', $storeIds);
        }

        if (!$deleteAll) {
            $activeProfileIds = array_values(array_unique(array_map('intval', $activeProfileIds)));
            if (!$activeProfileIds) {
                return 0;
            }
            $where[] = $connection->quoteInto('COALESCE(profile_id, 0) NOT IN (?)', $activeProfileIds);
        }

        $deleted = (int)$connection->delete($table, $where);
        if ($deleteAll) {
            $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
            if ($connection->isTableExists($sourceTable)) {
                $sourceWhere = [
                    $connection->quoteInto('source_code = ?', $source),
                    $connection->quoteInto('source_instance_key = ?', (string)$sourceInstanceKey),
                    $connection->quoteInto('is_active = ?', 1),
                ];
                if ($storeIds) {
                    $sourceWhere[] = $connection->quoteInto('store_id IN (?)', $storeIds);
                }
                $deleted += (int)$connection->update(
                    $sourceTable,
                    ['is_active' => 0, 'updated_at' => $this->dateTime->gmtDate()],
                    $sourceWhere
                );
            }
        }

        return $deleted;
    }

    public function getByIds(array $queueIds)
    {
        $queueIds = array_values(array_unique(array_filter(array_map('intval', $queueIds))));
        if (!$queueIds) {
            return [];
        }

        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE_QUEUE))
                ->where('queue_id IN (?)', $queueIds)
                ->order('queue_id DESC')
        );
    }

    private function updateStatus($queueId, $status, array $extra = [])
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::TABLE_QUEUE),
            $this->filterColumns($this->resource->getTableName(self::TABLE_QUEUE), $extra + [
                'status' => $status,
                'locked_at' => null,
                'lock_owner' => null,
                'updated_at' => $this->dateTime->gmtDate(),
            ]),
            ['queue_id = ?' => (int)$queueId]
        );
    }

    private function resetIfStaleScheduledRound($queueId, $claimedWarmupRound, $resultId = null, array $row = [])
    {
        if ($claimedWarmupRound === null) {
            return false;
        }

        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$this->columnExists($table, 'warmup_round')) {
            return false;
        }

        if (!$row || !array_key_exists('warmup_round', $row)) {
            $row = $this->getQueueRoundRow($queueId, ['work_type', 'warmup_round']);
        }
        if (!$row) {
            return false;
        }

        $workType = $this->normalizeWorkType($row['work_type'] ?? null, null);
        if ($workType !== QueueWorkType::TYPE_SCHEDULED) {
            return false;
        }
        if ((int)$row['warmup_round'] === (int)$claimedWarmupRound) {
            return false;
        }

        $now = $this->dateTime->gmtDate();
        $this->resource->getConnection()->update(
            $table,
            $this->filterColumns($table, [
                'status' => QueueStatus::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => $this->config->getWarmupMaxAttempts(),
                'scheduled_at' => $now,
                'next_run_at' => $now,
                'locked_at' => null,
                'lock_owner' => null,
                'last_result_id' => $resultId === null ? null : (int)$resultId,
                'last_error' => 'Purge All restarted this scheduled row while it was running.',
                'is_urgent' => 0,
                'updated_at' => $now,
            ]),
            ['queue_id = ?' => (int)$queueId]
        );

        return true;
    }

    private function getQueueRoundRow($queueId, array $columns)
    {
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        return $this->resource->getConnection()->fetchRow(
            $this->resource->getConnection()->select()
                ->from($table, $this->existingColumns($table, $columns))
                ->where('queue_id = ?', (int)$queueId)
                ->limit(1)
        ) ?: [];
    }

    private function countQueueRows(array $where)
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE_QUEUE), new \Zend_Db_Expr('COUNT(*)'));
        foreach ($where as $condition) {
            $select->where($condition);
        }

        return (int)$connection->fetchOne($select);
    }

    private function getQueueWorkType($queueId)
    {
        $table = $this->resource->getTableName(self::TABLE_QUEUE);
        if (!$this->columnExists($table, 'work_type')) {
            return QueueWorkType::TYPE_SCHEDULED;
        }

        $workType = $this->resource->getConnection()->fetchOne(
            $this->resource->getConnection()->select()
                ->from($table, 'work_type')
                ->where('queue_id = ?', (int)$queueId)
                ->limit(1)
        );

        return $this->normalizeWorkType($workType, null);
    }

    private function applyQueueGridScope($select)
    {
        if ($this->columnExists($this->resource->getTableName(self::TABLE_QUEUE), 'work_type')) {
            $select->where('work_type = ?', QueueWorkType::TYPE_SCHEDULED);
        }
        $select->where(
            'NOT (status IN (?) AND (next_run_at IS NULL OR next_run_at = ""))',
            [QueueStatus::STATUS_WARMED, QueueStatus::STATUS_SKIPPED]
        );
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
        if (!empty($filters['status'])) {
            if ((string)$filters['status'] === QueueStatus::STATUS_PENDING) {
                $connection = $this->resource->getConnection();
                $select->where(sprintf(
                    '(status = %s OR (status = %s AND next_run_at IS NOT NULL))',
                    $connection->quote(QueueStatus::STATUS_PENDING),
                    $connection->quote(QueueStatus::STATUS_WARMED)
                ));
            } else {
                $select->where('status = ?', (string)$filters['status']);
            }
        }
        if (!empty($filters['source_queue'])) {
            [$source, $sourceInstanceKey] = $this->parseSourceQueueFilter((string)$filters['source_queue']);
            $this->addSourceQueueFilter($select, $source, $sourceInstanceKey);
        }
        if (isset($filters['url']) && trim((string)$filters['url']) !== '') {
            $url = trim((string)$filters['url']);
            if (!empty($filters['url_exact'])) {
                $select->where('url = ?', $url);
            } else {
                $select->where('url LIKE ?', '%' . addcslashes($url, '\\%_') . '%');
            }
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

        return $select;
    }

    private function parseSourceQueueFilter($value)
    {
        $parts = explode('|', (string)$value, 2);
        return [
            trim((string)($parts[0] ?? '')),
            trim((string)($parts[1] ?? '')),
        ];
    }

    private function addSourceInstanceFilter($select, $sourceInstanceKey)
    {
        $sourceInstanceKey = (string)$sourceInstanceKey;
        $connection = $this->resource->getConnection();
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);

        $conditions = [
            $connection->quoteInto('source_instance_key = ?', $sourceInstanceKey),
        ];

        if ($connection->isTableExists($sourceTable)) {
            $conditions[] = $connection->quoteInto(
                'EXISTS (SELECT 1 FROM ' . $sourceTable . ' us_filter'
                . ' WHERE us_filter.url_id = ' . $this->resource->getTableName(self::TABLE_QUEUE) . '.url_id'
                . ' AND us_filter.source_code = ' . $this->resource->getTableName(self::TABLE_QUEUE) . '.source'
                . ' AND us_filter.is_active = 1'
                . ' AND us_filter.source_instance_key = ?)',
                $sourceInstanceKey
            );
        }

        $select->where('(' . implode(' OR ', $conditions) . ')');
    }

    private function addSourceQueueFilter($select, $source, $sourceInstanceKey)
    {
        $source = trim((string)$source);
        $sourceInstanceKey = trim((string)$sourceInstanceKey);
        if ($source === '') {
            return;
        }

        $connection = $this->resource->getConnection();
        $queueTable = $this->resource->getTableName(self::TABLE_QUEUE);
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        $conditions = [];

        if ($sourceInstanceKey === '') {
            $conditions[] = $connection->quoteInto('source = ?', $source);
            if ($connection->isTableExists($sourceTable)) {
                $conditions[] = $connection->quoteInto(
                    'EXISTS (SELECT 1 FROM ' . $sourceTable . ' us_filter'
                    . ' WHERE us_filter.url_id = ' . $queueTable . '.url_id'
                    . ' AND us_filter.source_code = ?'
                    . ' AND us_filter.is_active = 1)',
                    $source
                );
            }
            $select->where('(' . implode(' OR ', $conditions) . ')');
            return;
        }

        $effectiveCondition = $connection->quoteInto('source = ?', $source)
            . ' AND ('
            . $connection->quoteInto('COALESCE(source_instance_key, source) = ?', $sourceInstanceKey);
        if ($connection->isTableExists($sourceTable)) {
            $effectiveCondition .= ' OR ' . $connection->quoteInto(
                'EXISTS (SELECT 1 FROM ' . $sourceTable . ' us_owner'
                . ' WHERE us_owner.url_id = ' . $queueTable . '.url_id'
                . ' AND us_owner.source_code = ' . $queueTable . '.source'
                . ' AND us_owner.is_active = 1'
                . ' AND us_owner.source_instance_key = ?)',
                $sourceInstanceKey
            );
        }
        $effectiveCondition .= ')';
        $conditions[] = '(' . $effectiveCondition . ')';

        if ($connection->isTableExists($sourceTable)) {
            $conditions[] = $connection->quoteInto(
                'EXISTS (SELECT 1 FROM ' . $sourceTable . ' us_filter'
                . ' WHERE us_filter.url_id = ' . $queueTable . '.url_id'
                . ' AND us_filter.source_code = ?'
                . ' AND us_filter.is_active = 1'
                . ' AND us_filter.source_instance_key = ' . $connection->quote($sourceInstanceKey) . ')',
                $source
            );
        }

        $select->where('(' . implode(' OR ', $conditions) . ')');
    }

    private function hydrateDisplaySourceInstances(array $rows)
    {
        if (!$rows) {
            return $rows;
        }

        $connection = $this->resource->getConnection();
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if (!$connection->isTableExists($sourceTable)) {
            return $rows;
        }

        $urlIds = [];
        foreach ($rows as $row) {
            $source = (string)($row['source'] ?? '');
            $instance = (string)($row['source_instance_key'] ?? '');
            if ((int)($row['url_id'] ?? 0) > 0 && ($instance === '' || $instance === $source)) {
                $urlIds[] = (int)$row['url_id'];
            }
        }
        $urlIds = array_values(array_unique($urlIds));
        if (!$urlIds) {
            return $rows;
        }

        $instances = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($sourceTable, [
                    'url_id',
                    'source_code',
                    'source_instance_key' => new \Zend_Db_Expr('MIN(source_instance_key)'),
                ])
                ->where('url_id IN (?)', $urlIds)
                ->where('is_active = ?', 1)
                ->where('source_instance_key IS NOT NULL')
                ->where('source_instance_key != source_code')
                ->group(['url_id', 'source_code'])
        ) as $row) {
            $instances[(int)$row['url_id'] . '|' . (string)$row['source_code']] = (string)$row['source_instance_key'];
        }

        foreach ($rows as &$row) {
            $source = (string)($row['source'] ?? '');
            $instance = (string)($row['source_instance_key'] ?? '');
            $key = (int)($row['url_id'] ?? 0) . '|' . $source;
            if (($instance === '' || $instance === $source) && !empty($instances[$key])) {
                $row['source_instance_key'] = $instances[$key];
            }
        }
        unset($row);

        return $rows;
    }

    private function hydrateSourceMemberships(array $rows)
    {
        if (!$rows) {
            return $rows;
        }

        $connection = $this->resource->getConnection();
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if (!$connection->isTableExists($sourceTable)) {
            return $rows;
        }

        $urlIds = [];
        foreach ($rows as $row) {
            if ((int)($row['url_id'] ?? 0) > 0) {
                $urlIds[] = (int)$row['url_id'];
            }
        }
        $urlIds = array_values(array_unique($urlIds));
        if (!$urlIds) {
            return $rows;
        }

        $memberships = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($sourceTable, [
                    'url_id',
                    'source_code',
                    'source_instance_key',
                    'source_priority',
                    'url_priority',
                    'effective_priority',
                ])
                ->where('url_id IN (?)', $urlIds)
                ->where('is_active = ?', 1)
                ->order(['source_priority ASC', 'effective_priority ASC', 'source_code ASC', 'source_instance_key ASC'])
        ) as $membership) {
            $memberships[(int)$membership['url_id']][] = $membership;
        }

        foreach ($rows as &$row) {
            $rowMemberships = $memberships[(int)($row['url_id'] ?? 0)] ?? [];
            $source = (string)($row['source'] ?? '');
            $instance = (string)($row['source_instance_key'] ?? '');
            $instance = $instance !== '' ? $instance : $source;
            $alsoFoundIn = [];
            foreach ($rowMemberships as $membership) {
                $membershipSource = (string)($membership['source_code'] ?? '');
                $membershipInstance = (string)($membership['source_instance_key'] ?? '');
                $membershipInstance = $membershipInstance !== '' ? $membershipInstance : $membershipSource;
                if ($membershipSource === $source && $membershipInstance === $instance) {
                    continue;
                }
                $alsoFoundIn[] = $membership;
            }

            $row['source_memberships'] = $rowMemberships;
            $row['also_source_memberships'] = $alsoFoundIn;
        }
        unset($row);

        return $rows;
    }

    private function getSourceInstanceJoin()
    {
        $connection = $this->resource->getConnection();
        $sourceTable = $this->resource->getTableName(self::TABLE_URL_SOURCE);
        if (!$connection->isTableExists($sourceTable)) {
            return null;
        }

        return $connection->select()
            ->from($sourceTable, [
                'url_id',
                'source_code',
                'source_instance_key' => new \Zend_Db_Expr(
                    'MIN(CASE WHEN source_instance_key IS NOT NULL AND source_instance_key <> source_code THEN source_instance_key ELSE NULL END)'
                ),
            ])
            ->where('is_active = ?', 1)
            ->group(['url_id', 'source_code']);
    }

    private function getResolvedSourceInstanceExpr()
    {
        return new \Zend_Db_Expr(
            "CASE WHEN q.source_instance_key IS NULL OR q.source_instance_key = '' OR q.source_instance_key = q.source "
            . "THEN COALESCE(us.source_instance_key, q.source_instance_key, q.source) ELSE q.source_instance_key END"
        );
    }

    private function normalizeProfileId($profileId)
    {
        return $profileId === null || $profileId === '' ? 0 : (int)$profileId;
    }

    private function addProfileWhere($select, $profileId)
    {
        $profileId = $this->normalizeProfileId($profileId);
        if ($profileId === 0) {
            $select->where('(profile_id = 0 OR profile_id IS NULL)');
            return;
        }
        $select->where('profile_id = ?', $profileId);
    }

    private function addExcludedLanesWhere($select, array $lanes)
    {
        $connection = $this->resource->getConnection();
        foreach ($lanes as $lane) {
            if (!is_array($lane) || empty($lane['mode']) || !isset($lane['store_id'])) {
                continue;
            }

            $profileId = $this->normalizeProfileId($lane['profile_id'] ?? null);
            $profileWhere = $profileId === 0
                ? '(profile_id = 0 OR profile_id IS NULL)'
                : $connection->quoteInto('profile_id = ?', $profileId);
            $select->where(sprintf(
                'NOT (%s AND %s AND %s)',
                $connection->quoteInto('mode = ?', (string)$lane['mode']),
                $connection->quoteInto('store_id = ?', (int)$lane['store_id']),
                $profileWhere
            ));
        }
    }

    private function isUrgentSource($source)
    {
        return strpos((string)$source, 'purge_') === 0 || (string)$source === 'purge';
    }

    private function normalizeWorkType($workType = null, $source = null)
    {
        $workType = (string)$workType;
        if ($workType === QueueWorkType::TYPE_DELTA) {
            return QueueWorkType::TYPE_DELTA;
        }

        if ($workType === QueueWorkType::TYPE_SCHEDULED) {
            return QueueWorkType::TYPE_SCHEDULED;
        }

        return $this->isDeltaSource($source) ? QueueWorkType::TYPE_DELTA : QueueWorkType::TYPE_SCHEDULED;
    }

    private function isDeltaSource($source)
    {
        return in_array((string)$source, self::DELTA_SOURCES, true);
    }

    private function mergeSourceFlags($existingFlags, $source)
    {
        $flags = [];
        foreach (preg_split('/[\s,]+/', (string)$existingFlags, 0, PREG_SPLIT_NO_EMPTY) as $flag) {
            $flags[$flag] = true;
        }
        $source = trim((string)$source);
        if ($source !== '') {
            $flags[$source] = true;
        }
        ksort($flags);
        return implode(',', array_keys($flags));
    }

    private function existingColumns($table, array $columns)
    {
        return array_values(array_filter($columns, function ($column) use ($table) {
            return $this->columnExists($table, $column);
        }));
    }

    private function forUpdate($select)
    {
        if (method_exists($select, 'forUpdate')) {
            return $select->forUpdate(true);
        }

        return (string)$select . ' FOR UPDATE';
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

    private function dueOrder($table)
    {
        $order = [];
        if ($this->columnExists($table, 'is_urgent')) {
            $order[] = 'is_urgent DESC';
        }
        if ($this->columnExists($table, 'profile_id')) {
            $order[] = new \Zend_Db_Expr('COALESCE(profile_id, 0) ASC');
        }
        $order[] = 'priority ASC';
        $order[] = 'next_run_at ASC';
        $order[] = 'queue_id ASC';
        return $order;
    }

    private function adminQueueOrder($table)
    {
        $order = [];
        if ($this->columnExists($table, 'is_urgent')) {
            $order[] = 'is_urgent DESC';
        }
        if ($this->columnExists($table, 'source_priority')) {
            $order[] = 'source_priority ASC';
        }
        if ($this->columnExists($table, 'priority')) {
            $order[] = 'priority ASC';
        }
        if ($this->columnExists($table, 'profile_id')) {
            $order[] = new \Zend_Db_Expr('COALESCE(profile_id, 0) ASC');
        }
        if ($this->columnExists($table, 'next_run_at')) {
            $order[] = 'next_run_at ASC';
        }
        $order[] = 'queue_id ASC';
        return $order;
    }

    private function bestPriority($newPriority, $existingPriority = null)
    {
        if ($existingPriority === null || $existingPriority === '') {
            return (int)$newPriority;
        }

        return min((int)$newPriority, (int)$existingPriority);
    }

    private function columnExists($table, $column)
    {
        if (!isset($this->tableColumns[$table])) {
            $this->tableColumns[$table] = $this->resource->getConnection()->describeTable($table);
        }
        return isset($this->tableColumns[$table][$column]);
    }
}
