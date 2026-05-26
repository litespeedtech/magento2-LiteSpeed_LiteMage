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

class RunnerEventRepository
{
    public const RUNNER_GENERATE = 'generate';
    public const RUNNER_PROCESS = 'process';
    public const RUNNER_CLEANUP = 'cleanup';
    public const RUNNER_PURGE_ALL = 'purge_all';

    public const MODE_CRON = 'cron';
    public const MODE_ADMIN = 'admin';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_LOAD_SKIPPED = 'load_skipped';
    public const STATUS_FAILED = 'failed';

    private const TABLE_EVENT = 'litemage_warm_runner_event';

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

    public function start($runnerType, $runnerMode)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_EVENT);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        $now = $this->dateTime->gmtDate();
        $connection->insert($table, [
            'runner_type' => $this->sanitizeRunnerType($runnerType),
            'runner_mode' => $this->sanitizeRunnerMode($runnerMode),
            'status' => self::STATUS_RUNNING,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$connection->lastInsertId($table);
    }

    public function finish($eventId, $status, array $summary = [], $errorText = null)
    {
        $eventId = (int)$eventId;
        if ($eventId <= 0) {
            return 0;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_EVENT);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        return (int)$connection->update(
            $table,
            [
                'status' => $this->sanitizeStatus($status),
                'finished_at' => $this->dateTime->gmtDate(),
                'summary_json' => $this->encodeSummary($summary),
                'error_text' => $this->trimText($errorText),
                'updated_at' => $this->dateTime->gmtDate(),
            ],
            ['event_id = ?' => $eventId]
        );
    }

    public function record($runnerType, $runnerMode, $status, array $summary = [], $errorText = null)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_EVENT);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        $now = $this->dateTime->gmtDate();
        $connection->insert($table, [
            'runner_type' => $this->sanitizeRunnerType($runnerType),
            'runner_mode' => $this->sanitizeRunnerMode($runnerMode),
            'status' => $this->sanitizeStatus($status),
            'started_at' => $now,
            'finished_at' => $now,
            'summary_json' => $this->encodeSummary($summary),
            'error_text' => $this->trimText($errorText),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$connection->lastInsertId($table);
    }

    public function getRecent($limit = 20)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_EVENT);
        if (!$connection->isTableExists($table)) {
            return [];
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->order('event_id DESC')
                ->limit(max(1, (int)$limit))
        );

        return array_map([$this, 'normalizeRow'], $rows);
    }

    public function getLatestByType()
    {
        $latest = [];
        foreach ($this->getRecent(50) as $event) {
            $runnerType = $event['runner_type'];
            if (!isset($latest[$runnerType])) {
                $latest[$runnerType] = $event;
            }
        }

        return $latest;
    }

    public function cleanup($olderThanDays)
    {
        $olderThanDays = max(1, (int)$olderThanDays);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_EVENT);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        return (int)$connection->delete(
            $table,
            ['created_at < ?' => $this->dateTime->gmtDate('Y-m-d H:i:s', time() - ($olderThanDays * 86400))]
        );
    }

    private function normalizeRow(array $row)
    {
        $row['event_id'] = (int)($row['event_id'] ?? 0);
        $row['summary'] = $this->decodeSummary($row['summary_json'] ?? null);
        return $row;
    }

    private function encodeSummary(array $summary)
    {
        if (!$summary) {
            return null;
        }

        $json = json_encode($summary);
        return $json === false ? null : $json;
    }

    private function decodeSummary($summaryJson)
    {
        if (!$summaryJson) {
            return [];
        }

        $summary = json_decode($summaryJson, true);
        return is_array($summary) ? $summary : [];
    }

    private function sanitizeRunnerType($runnerType)
    {
        $runnerType = (string)$runnerType;
        if (in_array($runnerType, [
            self::RUNNER_GENERATE,
            self::RUNNER_PROCESS,
            self::RUNNER_CLEANUP,
            self::RUNNER_PURGE_ALL,
        ], true)) {
            return $runnerType;
        }

        return substr(preg_replace('/[^a-z0-9_]/', '', strtolower($runnerType)), 0, 32) ?: self::RUNNER_PROCESS;
    }

    private function sanitizeRunnerMode($runnerMode)
    {
        return (string)$runnerMode === self::MODE_ADMIN ? self::MODE_ADMIN : self::MODE_CRON;
    }

    private function sanitizeStatus($status)
    {
        $status = (string)$status;
        if (in_array($status, [
            self::STATUS_RUNNING,
            self::STATUS_SUCCESS,
            self::STATUS_DISABLED,
            self::STATUS_LOAD_SKIPPED,
            self::STATUS_FAILED,
        ], true)) {
            return $status;
        }

        return self::STATUS_FAILED;
    }

    private function trimText($text)
    {
        if ($text === null || $text === '') {
            return null;
        }

        return substr((string)$text, 0, 65535);
    }
}
