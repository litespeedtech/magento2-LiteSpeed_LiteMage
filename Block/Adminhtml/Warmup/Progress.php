<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\ProgressRepository;
use Litespeed\Litemage\Model\Warmup\QueueWorkType;
use Litespeed\Litemage\Model\Warmup\RunnerEventRepository;
use Magento\Backend\Block\Template\Context;

class Progress extends Navigation
{
    protected $_template = 'Litespeed_Litemage::warmup/progress.phtml';

    /**
     * @var ProgressRepository
     */
    private $progressRepository;

    /**
     * @var RunnerEventRepository
     */
    private $runnerEventRepository;

    /**
     * @var array|null
     */
    private $latestPurgeAllEvent;

    /**
     * @var bool
     */
    private $latestPurgeAllEventLoaded = false;

    public function __construct(
        Context $context,
        Config $config,
        ProgressRepository $progressRepository,
        RunnerEventRepository $runnerEventRepository,
        array $data = []
    ) {
        $this->progressRepository = $progressRepository;
        $this->runnerEventRepository = $runnerEventRepository;
        parent::__construct($context, $config, $data);
    }

    public function getOverallSummary()
    {
        return $this->progressRepository->getOverallSummary();
    }

    public function getLanes()
    {
        $lanesByKey = [];
        foreach ($this->progressRepository->getLaneProgress() as $lane) {
            if ($this->isStalePurgeDeltaAfterPurgeAll($lane)) {
                continue;
            }
            $lanesByKey[$this->getLaneMergeKey($lane)] = $lane;
        }
        foreach ($this->progressRepository->getCoveredLaneProgress() as $coveredLane) {
            if ($this->isStalePurgeDeltaAfterPurgeAll($coveredLane)) {
                continue;
            }
            $key = $this->getLaneMergeKey($coveredLane);
            if (!isset($lanesByKey[$key])) {
                $lanesByKey[$key] = $coveredLane;
                continue;
            }
            $lanesByKey[$key]['covered'] = (int)($lanesByKey[$key]['covered'] ?? 0)
                + (int)($coveredLane['covered'] ?? 0);
        }

        return array_values($lanesByKey);
    }

    public function getQueueGroups()
    {
        $groups = [];
        foreach ($this->getLanes() as $lane) {
            $key = $this->getQueueGroupKey($lane);
            if (!isset($groups[$key])) {
                $groups[$key] = $this->createQueueGroup($lane);
            }
            $this->addLaneToQueueGroup($groups[$key], $lane);
        }

        uasort($groups, function (array $left, array $right) {
            foreach (['source_priority', 'max_priority', 'source', 'source_instance_key'] as $field) {
                if ($left[$field] == $right[$field]) {
                    continue;
                }
                return $left[$field] < $right[$field] ? -1 : 1;
            }
            return 0;
        });

        return array_values($groups);
    }

    public function getRunnerSummary($overall = null)
    {
        $overall = $overall ?: $this->getOverallSummary();
        $workState = $this->progressRepository->getWorkStateSummary();
        $latestEvents = $this->runnerEventRepository->getLatestByType();
        $recentEvents = $this->runnerEventRepository->getRecent(10);
        $enabled = $this->config->isWarmupEnabled();
        $loadLimit = $this->config->getWarmupMaxLoadAverage();
        $currentLoad = $this->getCurrentLoadAverage();
        $state = $this->getStateLabel($enabled, $overall, $workState, $latestEvents);

        return [
            'state' => $state,
            'enabled' => $enabled,
            'process_cron_schedule' => $this->config->getWarmupProcessCronSchedule(),
            'generate_cron_schedule' => $this->config->getWarmupGenerateCronSchedule(),
            'load_limit' => $loadLimit,
            'current_load' => $currentLoad,
            'work_state' => $workState,
            'latest_events' => $latestEvents,
            'recent_events' => $recentEvents,
        ];
    }

    public function getPauseUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/pause', ['return' => 'progress']);
    }

    public function getResumeUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/resume', ['return' => 'progress']);
    }

    public function getOperatorWarnings(array $overall, array $runnerSummary)
    {
        $warnings = [];
        $workState = $runnerSummary['work_state'];
        $loadLimit = (float)$runnerSummary['load_limit'];
        $currentLoad = $runnerSummary['current_load'];
        $latestProcess = $runnerSummary['latest_events'][RunnerEventRepository::RUNNER_PROCESS] ?? null;

        if (!$runnerSummary['enabled']) {
            $warnings[] = __('Warmup is paused from Warmup Queue. Resume it from that tab when you want queue processing to continue.');
        }
        if (!$this->config->getWarmupSources() && !$this->config->isWarmupDeltaEnabled()) {
            $warnings[] = __('No scheduled sources are enabled and purge-driven delta warmup is disabled.');
        }
        if ($overall['total'] === 0) {
            $warnings[] = __('No warmup URLs are queued.');
        } elseif ($overall['pending'] > 0 && $workState['due_pending'] === 0) {
            $warnings[] = __('Pending queued URLs exist, but no URL is due yet.');
        }
        if ($workState['expired_locks'] > 0) {
            $warnings[] = __('%1 expired lane lock(s) are waiting for cleanup.', $workState['expired_locks']);
        } elseif ($workState['active_locks'] > 0) {
            $warnings[] = __('%1 lane lock(s) are active.', $workState['active_locks']);
        }
        if ($latestProcess && $latestProcess['status'] === RunnerEventRepository::STATUS_LOAD_SKIPPED) {
            $warnings[] = __('The latest queue run skipped because server load reached the configured guard.');
        }
        if ($loadLimit > 0 && $currentLoad !== null && $currentLoad >= $loadLimit) {
            $warnings[] = __('Current 1-minute load %1 is at or above the configured guard %2.', $this->formatNumber($currentLoad), $this->formatNumber($loadLimit));
        }
        if ($this->getRecentLoadSkipCount($runnerSummary['recent_events']) >= 3) {
            $warnings[] = __('Several recent queue runs were skipped due to server load.');
        }
        if ($overall['total'] > 0 && $overall['failed'] >= 10 && ($overall['failed'] / $overall['total']) >= 0.1) {
            $warnings[] = __('Warmup failures are above 10% of queued URLs.');
        }

        return $warnings;
    }

    public function getRunnerEvent(array $runnerSummary, $runnerType)
    {
        return $runnerSummary['latest_events'][$runnerType] ?? null;
    }

    public function getRunnerEventLabel($runnerType)
    {
        $labels = [
            RunnerEventRepository::RUNNER_GENERATE => __('Last Build Queue Run'),
            RunnerEventRepository::RUNNER_PROCESS => __('Last Queue Process Run'),
            RunnerEventRepository::RUNNER_CLEANUP => __('Last History Cleanup'),
            RunnerEventRepository::RUNNER_PURGE_ALL => __('Last Purge All Restart'),
        ];

        return $labels[$runnerType] ?? $runnerType;
    }

    public function getRunnerEventSummary($event = null)
    {
        if (!$event) {
            return __('Never run');
        }
        if (($event['status'] ?? '') === RunnerEventRepository::STATUS_DISABLED) {
            return __('Cache Warmer was disabled when this run started.');
        }

        $parts = [];
        foreach ($event['summary'] ?? [] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = sprintf('%s %s', str_replace('_', ' ', (string)$key), $value === null ? '-' : $value);
            }
        }

        return $parts ? implode(', ', array_slice($parts, 0, 6)) : __('No run details');
    }

    public function getStatusLabel($status)
    {
        $labels = [
            RunnerEventRepository::STATUS_RUNNING => __('Running'),
            RunnerEventRepository::STATUS_SUCCESS => __('OK'),
            RunnerEventRepository::STATUS_DISABLED => __('Skipped: paused'),
            RunnerEventRepository::STATUS_LOAD_SKIPPED => __('Load skipped'),
            RunnerEventRepository::STATUS_FAILED => __('Failed'),
        ];

        return $labels[$status] ?? $status;
    }

    public function formatNumber($value)
    {
        return $value === null ? '-' : number_format((float)$value, 2);
    }

    public function getSegmentPercent(array $row, $key)
    {
        $total = max(1, (int)($row['total'] ?? 0));
        return round(((int)($row[$key] ?? 0) / $total) * 100, 2);
    }

    public function getLaneLabel(array $row)
    {
        return sprintf(
            'Store %d / %s / %s',
            (int)$row['store_id'],
            (string)$row['profile_label'],
            (string)$row['mode']
        );
    }

    public function getSourceGroupLabel(array $row)
    {
        $source = (string)($row['source'] ?? '');
        $instance = (string)($row['source_instance_key'] ?? '');
        $priority = (int)($row['source_priority'] ?? 100);
        if ($instance !== '' && $instance !== $source) {
            return sprintf('%s / %s / priority %d', $source, $this->getShortInstanceLabel($instance), $priority);
        }

        return sprintf('%s / priority %d', $source ?: 'unknown', $priority);
    }

    public function getQueueName(array $row)
    {
        $instance = (string)($row['source_instance_key'] ?? '');
        $source = (string)($row['source'] ?? '');
        if ($instance !== '' && $instance !== $source) {
            return $this->getInstanceLabel($instance);
        }

        return $this->getSourceLabel($source);
    }

    public function getQueueInstanceLabel(array $row)
    {
        $instance = (string)($row['source_instance_key'] ?? '');
        $source = (string)($row['source'] ?? '');

        if ($instance !== '' && $instance !== $source) {
            return $this->getInstanceLabel($instance);
        }

        return $this->getSourceLabel($source);
    }

    public function getQueueSourceLabel(array $row)
    {
        return __('Source: %1', $this->getSourceLabel((string)($row['source'] ?? '')));
    }

    public function getCompletionLabel(array $row)
    {
        if ((int)($row['total'] ?? 0) === 0 && (int)($row['covered'] ?? 0) > 0) {
            return sprintf('0 active work, %d covered', (int)$row['covered']);
        }

        return sprintf(
            '%s%% (%d of %d)',
            $this->getCompletionPercentLabel($row),
            (int)($row['completed'] ?? 0),
            (int)($row['total'] ?? 0)
        );
    }

    public function getCompletionPercentLabel(array $row)
    {
        return number_format((float)($row['completion_percent'] ?? 0), 1) . '%';
    }

    public function getCompletionCountLabel(array $row)
    {
        if ((int)($row['total'] ?? 0) === 0 && (int)($row['covered'] ?? 0) > 0) {
            return sprintf('(0 active, %d covered)', (int)$row['covered']);
        }

        return sprintf(
            '(%d of %d)',
            (int)($row['completed'] ?? 0),
            (int)($row['total'] ?? 0)
        );
    }

    public function getDueLabel(array $row)
    {
        if ((int)($row['total'] ?? 0) === 0 && (int)($row['covered'] ?? 0) > 0) {
            return (string)__('Covered');
        }
        if ((int)($row['pending'] ?? 0) === 0) {
            return (string)__('No pending URLs');
        }

        $date = $this->formatDateTime($row['oldest_pending_at'] ?? null);
        return $date === '-' ? (string)__('Due now') : $date;
    }

    public function getPriorityLabel(array $row)
    {
        return (int)($row['total'] ?? 0) > 0 ? (string)(int)($row['max_priority'] ?? 0) : '-';
    }

    public function formatDateTime($value)
    {
        return $value ? (string)$value : '-';
    }

    public function formatDuration($seconds)
    {
        $seconds = max(0, (int)$seconds);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        if ($days > 0) {
            return sprintf('%dd %dh', $days, $hours);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    public function getLaneQueueUrl(array $lane)
    {
        return $this->getUrl('litespeed_litemage/warmup/queue', $this->getLaneFilterParams($lane));
    }

    public function getLaneResultsUrl(array $lane)
    {
        $laneValue = (string)($lane['source_instance_key'] ?? '');
        $source = (string)($lane['source'] ?? '');
        if (in_array($source, ['purge_entity', 'purge_reverse_index', 'purge_broad'], true)) {
            $laneValue = $source;
        } elseif ($laneValue === '') {
            $laneValue = $source;
        }

        return $this->getUrl('litespeed_litemage/warmup/results', [
            'store_id' => (int)($lane['store_id'] ?? 0),
            'profile_id' => (int)($lane['profile_id'] ?? 0),
            'mode' => (string)($lane['mode'] ?? ''),
            'lane' => $laneValue,
        ]);
    }

    public function getWarmupConfigUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'litemage'])
            . '#litemage_warmup_queue_variant_map-head';
    }

    public function getStateCssClass(array $runnerSummary)
    {
        return empty($runnerSummary['enabled']) ? '_paused' : '';
    }

    public function getQueueStatusLine(array $row)
    {
        if ($this->isPurgeDeltaRow($row)) {
            $time = $this->formatDateTime($row['latest_updated_at'] ?? null);
            return $time === '-' ? '' : (string)__('Updated at %1 due to Purge Event.', $time);
        }

        $startedAt = $this->formatDateTime($row['cycle_started_at'] ?? null);
        if ($startedAt === '-') {
            return '';
        }

        $event = $this->getLatestPurgeAllEvent();
        $elapsedLabel = $this->getCycleElapsedLabel($row);
        if ($event && $this->isPurgeAllRestartForCycle($event, $row)) {
            return (string)__('Restarted at %1 due to Purge All. %2', $startedAt, $elapsedLabel);
        }

        return (string)__('Started at %1. %2', $startedAt, $elapsedLabel);
    }

    public function getSublaneStatusLine(array $row)
    {
        return $this->getQueueStatusLine($row);
    }

    private function getLaneFilterParams(array $lane)
    {
        return [
            'store_id' => (int)($lane['store_id'] ?? 0),
            'profile_id' => (int)($lane['profile_id'] ?? 0),
            'mode' => (string)($lane['mode'] ?? ''),
            'source_queue' => (string)($lane['source'] ?? '') . '|' . (string)($lane['source_instance_key'] ?? ''),
        ];
    }

    private function getQueueGroupKey(array $lane)
    {
        return implode('|', [
            (string)($lane['work_type'] ?? QueueWorkType::TYPE_SCHEDULED),
            (string)($lane['source'] ?? ''),
            (string)($lane['source_instance_key'] ?? ''),
        ]);
    }

    private function getLaneMergeKey(array $lane)
    {
        return implode('|', [
            (string)($lane['work_type'] ?? QueueWorkType::TYPE_SCHEDULED),
            (string)($lane['source'] ?? ''),
            (string)($lane['source_instance_key'] ?? ''),
            (int)($lane['store_id'] ?? 0),
            (int)($lane['profile_id'] ?? 0),
            (string)($lane['mode'] ?? ''),
        ]);
    }

    private function createQueueGroup(array $lane)
    {
        return [
            'work_type' => (string)($lane['work_type'] ?? QueueWorkType::TYPE_SCHEDULED),
            'source' => (string)($lane['source'] ?? ''),
            'source_instance_key' => (string)($lane['source_instance_key'] ?? ''),
            'source_priority' => (int)($lane['source_priority'] ?? 100),
            'max_priority' => (int)($lane['total'] ?? 0) > 0 ? (int)($lane['max_priority'] ?? 100) : 100,
            'total' => 0,
            'pending' => 0,
            'running' => 0,
            'warmed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'blacklisted' => 0,
            'urgent' => 0,
            'completed' => 0,
            'completion_percent' => 0.0,
            'oldest_pending_at' => null,
            'cycle_started_at' => null,
            'latest_warmed_at' => null,
            'latest_updated_at' => null,
            'covered' => 0,
            'lanes' => [],
        ];
    }

    private function addLaneToQueueGroup(array &$group, array $lane)
    {
        foreach (['total', 'pending', 'running', 'warmed', 'skipped', 'failed', 'blacklisted', 'urgent'] as $field) {
            $group[$field] += (int)($lane[$field] ?? 0);
        }
        $group['covered'] += (int)($lane['covered'] ?? 0);
        $group['completed'] = $group['warmed'] + $group['skipped'] + $group['failed'] + $group['blacklisted'];
        $group['completion_percent'] = $group['total'] > 0
            ? round(($group['completed'] / $group['total']) * 100, 1)
            : 0.0;
        $group['source_priority'] = min($group['source_priority'], (int)($lane['source_priority'] ?? 100));
        if ((int)($lane['total'] ?? 0) > 0) {
            $group['max_priority'] = min($group['max_priority'], (int)($lane['max_priority'] ?? 100));
        }

        $oldestPending = $lane['oldest_pending_at'] ?? null;
        if ($oldestPending && (!$group['oldest_pending_at'] || strcmp($oldestPending, $group['oldest_pending_at']) < 0)) {
            $group['oldest_pending_at'] = $oldestPending;
        }

        $cycleStarted = $lane['cycle_started_at'] ?? null;
        if ($cycleStarted && (!$group['cycle_started_at'] || strcmp($cycleStarted, $group['cycle_started_at']) < 0)) {
            $group['cycle_started_at'] = $cycleStarted;
        }

        $latestWarmed = $lane['latest_warmed_at'] ?? null;
        if ($latestWarmed && (!$group['latest_warmed_at'] || strcmp($latestWarmed, $group['latest_warmed_at']) > 0)) {
            $group['latest_warmed_at'] = $latestWarmed;
        }

        $latestUpdated = $lane['latest_updated_at'] ?? null;
        if ($latestUpdated && (!$group['latest_updated_at'] || strcmp($latestUpdated, $group['latest_updated_at']) > 0)) {
            $group['latest_updated_at'] = $latestUpdated;
        }

        $group['lanes'][] = $lane;
    }

    private function isPurgeDeltaRow(array $row)
    {
        if (($row['work_type'] ?? QueueWorkType::TYPE_SCHEDULED) === QueueWorkType::TYPE_DELTA) {
            return true;
        }

        return in_array((string)($row['source'] ?? ''), ['purge_entity', 'purge_reverse_index'], true);
    }

    private function isStalePurgeDeltaAfterPurgeAll(array $row)
    {
        if (!$this->isPurgeDeltaRow($row)) {
            return false;
        }

        $event = $this->getLatestPurgeAllEvent();
        if (!$event) {
            return false;
        }

        $eventTime = strtotime((string)($event['finished_at'] ?? ''));
        $updatedAt = strtotime((string)($row['latest_updated_at'] ?? ''));
        if ($eventTime === false || $updatedAt === false) {
            return false;
        }

        return $updatedAt <= $eventTime;
    }

    private function getCycleElapsedLabel(array $row)
    {
        $startedAt = strtotime((string)($row['cycle_started_at'] ?? ''));
        if ($startedAt === false) {
            return '';
        }

        $pendingOrRunning = (int)($row['pending'] ?? 0) + (int)($row['running'] ?? 0);
        if ($pendingOrRunning > 0) {
            return (string)__('Elapsed %1.', $this->formatDuration(time() - $startedAt));
        }

        $finishedAt = strtotime((string)($row['latest_warmed_at'] ?? ''));
        if ($finishedAt !== false && $finishedAt >= $startedAt) {
            return (string)__('Finished in %1.', $this->formatDuration($finishedAt - $startedAt));
        }

        return (string)__('Elapsed %1.', $this->formatDuration(time() - $startedAt));
    }

    private function isPurgeAllRestartForCycle(array $event, array $row)
    {
        $eventTime = strtotime((string)($event['finished_at'] ?? ''));
        $startedAt = strtotime((string)($row['cycle_started_at'] ?? ''));
        if ($eventTime === false || $startedAt === false) {
            return false;
        }

        return $eventTime >= $startedAt && ($eventTime - $startedAt) <= 300;
    }

    private function getStateLabel($enabled, array $overall, array $workState, array $latestEvents)
    {
        if (!$enabled) {
            return __('Paused');
        }
        if ($overall['running'] > 0) {
            return __('Running');
        }

        $latestProcess = $latestEvents[RunnerEventRepository::RUNNER_PROCESS] ?? null;
        if ($latestProcess && $latestProcess['status'] === RunnerEventRepository::STATUS_LOAD_SKIPPED) {
            return __('Load skipped');
        }
        if ($latestProcess && $latestProcess['status'] === RunnerEventRepository::STATUS_FAILED) {
            return __('Failing');
        }
        if ($workState['active_locks'] > 0 && $overall['pending'] > 0) {
            return __('Blocked');
        }
        if ($workState['due_pending'] > 0) {
            return __('Idle');
        }

        return __('Idle');
    }

    private function getCurrentLoadAverage()
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        return isset($load[0]) ? (float)$load[0] : null;
    }

    private function getRecentLoadSkipCount(array $events)
    {
        $count = 0;
        foreach ($events as $event) {
            if (
                ($event['runner_type'] ?? null) === RunnerEventRepository::RUNNER_PROCESS
                && ($event['status'] ?? null) === RunnerEventRepository::STATUS_LOAD_SKIPPED
            ) {
                $count++;
            }
        }

        return $count;
    }

    private function getLatestPurgeAllEvent()
    {
        if ($this->latestPurgeAllEventLoaded) {
            return $this->latestPurgeAllEvent;
        }

        $this->latestPurgeAllEventLoaded = true;
        $latest = $this->runnerEventRepository->getLatestByType();
        $event = $latest[RunnerEventRepository::RUNNER_PURGE_ALL] ?? null;
        if (!$event || ($event['status'] ?? null) !== RunnerEventRepository::STATUS_SUCCESS) {
            $this->latestPurgeAllEvent = null;
            return null;
        }

        $this->latestPurgeAllEvent = $event;
        return $this->latestPurgeAllEvent;
    }

    private function getShortInstanceLabel($instance)
    {
        $instance = trim((string)$instance);
        if ($instance === '') {
            return $instance;
        }

        $path = parse_url($instance, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $instance = $path;
        }

        $instance = str_replace('\\', '/', $instance);
        $basename = basename($instance);
        return $this->normalizeStoreSuffix($basename !== '' ? $basename : $instance);
    }

    private function normalizeStoreSuffix($label)
    {
        return preg_replace('/::stores=([0-9]+)$/', '::store=$1', (string)$label);
    }

    private function getSourceLabel($source)
    {
        $source = (string)$source;
        $labels = [
            'manual' => __('Legacy Custom URL'),
            'sitemap' => __('Sitemap'),
            'url_rewrite' => __('Magento URL Rewrites'),
            'text_file' => __('Text/CSV File'),
            'recently_seen' => __('Recently Seen URLs'),
            'purge_direct' => __('Purge Event'),
            'purge_entity' => __('Resolved Entity URL'),
            'purge_reverse_index' => __('Reverse Index URL'),
            'purge_broad' => __('Legacy Purge All Work'),
        ];

        return $labels[$source] ?? ($source !== '' ? $source : (string)__('Unknown'));
    }

    private function getInstanceLabel($instance)
    {
        $instance = (string)$instance;
        $labels = [
            'manual' => __('Legacy Custom URL'),
            'url_rewrite' => __('Magento URL Rewrites'),
            'recently_seen' => __('Recently Seen URLs'),
            'purge_direct' => __('Purge Event'),
        ];

        return $labels[$instance] ?? $this->getShortInstanceLabel($instance);
    }
}
