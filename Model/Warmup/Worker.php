<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Helper\Data as Helper;
use Litespeed\Litemage\Logger\WarmupLogger;

class Worker
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var ResultRepository
     */
    private $resultRepository;

    /**
     * @var HttpWarmupClient
     */
    private $httpWarmupClient;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var LaneLockRepository
     */
    private $laneLockRepository;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * @var WarmupLogger
     */
    private $logger;

    public function __construct(
        Config $config,
        QueueRepository $queueRepository,
        ResultRepository $resultRepository,
        HttpWarmupClient $httpWarmupClient,
        VaryProfileResolver $varyProfileResolver,
        LaneLockRepository $laneLockRepository,
        Helper $helper,
        WarmupLogger $logger
    ) {
        $this->config = $config;
        $this->queueRepository = $queueRepository;
        $this->resultRepository = $resultRepository;
        $this->httpWarmupClient = $httpWarmupClient;
        $this->varyProfileResolver = $varyProfileResolver;
        $this->laneLockRepository = $laneLockRepository;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    public function process($limit = null, array $filters = [])
    {
        if (!$this->config->isWarmupEnabled()) {
            $this->logger->notice('Worker skipped: warmup is disabled.');
            return $this->emptyStats(['disabled' => true]);
        }

        $loadAverage = null;
        if ($this->isServerBusy($loadAverage)) {
            return $this->deferForLoad($loadAverage);
        }

        $started = time();
        $limit = max(1, $limit === null ? $this->config->getWarmupBatchSize() : (int)$limit);
        $stats = $this->emptyStats();
        $delayMs = $this->config->getWarmupCrawlDelayMs();
        $maxRuntime = $this->config->getWarmupMaxRuntime();
        $concurrency = $this->config->getWarmupConcurrency();
        $lockOwner = $this->getLockOwner();
        $skippedLanes = [];
        $stop = false;

        $this->logger->notice(sprintf(
            'Worker started limit=%d mode=%s profile=%s concurrency=%d max_runtime=%d',
            $limit,
            $filters['mode'] ?? 'any',
            array_key_exists('profile_id', $filters) ? $filters['profile_id'] : 'any',
            $concurrency,
            $maxRuntime
        ));

        $this->laneLockRepository->releaseExpired();

        while ($stats['claimed'] < $limit && !$stop) {
            $loadAverage = null;
            if ($this->isServerBusy($loadAverage)) {
                $stats['load_deferred'] = true;
                $stats['load_average'] = $loadAverage;
                $stats['load_limit'] = $this->config->getWarmupMaxLoadAverage();
                break;
            }

            if ((time() - $started) >= $maxRuntime) {
                break;
            }

            $claimLimit = $limit - $stats['claimed'];
            $claimFilters = $filters;
            if ($skippedLanes) {
                $claimFilters['exclude_lanes'] = $skippedLanes;
            }
            $rows = $this->queueRepository->claimDue($claimLimit, $lockOwner, $claimFilters);
            if (!$rows) {
                break;
            }

            $rows = $this->attachProfiles($rows);
            $lane = $this->getLane($rows);
            $requiresLaneLock = $this->requiresExecutionLaneLock($rows);
            $laneLocked = false;
            if ($requiresLaneLock) {
                $laneLocked = $this->laneLockRepository->acquire(
                    $lane['profile_id'],
                    $lane['mode'],
                    $lane['store_id'],
                    $lockOwner,
                    max(300, $maxRuntime * 2)
                );
                if (!$laneLocked) {
                    $this->queueRepository->release($this->rowIds($rows));
                    $skippedLanes[] = $lane;
                    $stats['lane_locked'] += count($rows);
                    continue;
                }
            }

            $stats['claimed'] += count($rows);
            $effectiveConcurrency = $requiresLaneLock ? 1 : $concurrency;

            try {
                for ($index = 0; $index < count($rows); $index += $effectiveConcurrency) {
                    $loadAverage = null;
                    if ($this->isServerBusy($loadAverage)) {
                        $this->releaseRemainingRows($rows, $index);
                        $stats['load_deferred'] = true;
                        $stats['load_average'] = $loadAverage;
                        $stats['load_limit'] = $this->config->getWarmupMaxLoadAverage();
                        $stop = true;
                        break;
                    }

                    if ((time() - $started) >= $maxRuntime) {
                        $this->releaseRemainingRows($rows, $index);
                        $stop = true;
                        break;
                    }

                    $batch = array_slice($rows, $index, $effectiveConcurrency);
                    $results = $this->httpWarmupClient->warmBatch($batch, $effectiveConcurrency);
                    foreach ($batch as $row) {
                        $result = $results[$row['queue_id']] ?? [
                            'status' => QueueStatus::STATUS_FAILED,
                            'http_status' => null,
                            'response_time_ms' => null,
                            'cache_status' => null,
                            'final_url' => $row['url'],
                            'headers_summary' => null,
                            'error' => 'Warmup batch did not return a result.',
                        ];
                        $resultId = $this->resultRepository->create($row, $result);

                        if ((int)($result['http_status'] ?? 0) === 404) {
                            $this->queueRepository->markGone(
                                $row['queue_id'],
                                $resultId,
                                $result['error'] ?? 'HTTP 404; URL deactivated.',
                                $this->getClaimedWarmupRound($row)
                            );
                            $stats['skipped']++;
                        } elseif ($result['status'] === QueueStatus::STATUS_WARMED) {
                            $applied = $this->queueRepository->markSuccess(
                                $row['queue_id'],
                                $resultId,
                                $result['cache_status'] ?? null,
                                $this->getClaimedWarmupRound($row)
                            );
                            $stats[$applied ? 'warmed' : 'skipped']++;
                        } elseif ($result['status'] === QueueStatus::STATUS_SKIPPED) {
                            $this->queueRepository->markSkipped(
                                $row['queue_id'],
                                $resultId,
                                $result['error'] ?? 'Skipped',
                                $this->getClaimedWarmupRound($row)
                            );
                            $stats['skipped']++;
                        } else {
                            $applied = $this->queueRepository->markFailure(
                                $row['queue_id'],
                                $resultId,
                                $result['error'] ?? 'Warmup failed',
                                null,
                                $this->getClaimedWarmupRound($row)
                            );
                            $stats[$applied ? 'failed' : 'skipped']++;
                        }
                        if ($result['status'] !== QueueStatus::STATUS_WARMED) {
                            $this->logger->notice(sprintf(
                                'Worker row %s url=%s status=%s http=%s error=%s',
                                $row['queue_id'],
                                $row['url'],
                                $result['status'],
                                $result['http_status'] === null ? '-' : $result['http_status'],
                                $result['error'] ?? '-'
                            ));
                        }
                    }

                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
            } finally {
                if ($laneLocked) {
                    $this->laneLockRepository->release($lane['profile_id'], $lane['mode'], $lane['store_id'], $lockOwner);
                }
            }
        }

        $this->helper->debugLog(sprintf(
            'Warmup queue processed claimed=%d warmed=%d skipped=%d failed=%d lane_locked=%d',
            $stats['claimed'],
            $stats['warmed'],
            $stats['skipped'],
            $stats['failed'],
            $stats['lane_locked']
        ));
        $this->logger->notice(sprintf(
            'Worker finished claimed=%d warmed=%d skipped=%d failed=%d lane_locked=%d load_deferred=%d',
            $stats['claimed'],
            $stats['warmed'],
            $stats['skipped'],
            $stats['failed'],
            $stats['lane_locked'],
            !empty($stats['load_deferred']) ? 1 : 0
        ));
        return $stats;
    }

    private function emptyStats(array $overrides = [])
    {
        return array_merge([
            'claimed' => 0,
            'warmed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'disabled' => false,
            'load_deferred' => false,
            'load_average' => null,
            'load_limit' => null,
            'lane_locked' => 0,
        ], $overrides);
    }

    private function isServerBusy(&$loadAverage = null)
    {
        $limit = $this->config->getWarmupMaxLoadAverage();
        if ($limit <= 0.0) {
            return false;
        }

        $loadAverage = $this->getCurrentLoadAverage();
        return $loadAverage !== null && $loadAverage >= $limit;
    }

    private function getCurrentLoadAverage()
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        return isset($load[0]) ? (float)$load[0] : null;
    }

    private function deferForLoad($loadAverage)
    {
        $limit = $this->config->getWarmupMaxLoadAverage();
        $this->helper->debugLog(sprintf(
            'Warmup queue skipped: load average %.4f >= limit %.4f',
            $loadAverage,
            $limit
        ));
        $this->logger->notice(sprintf(
            'Worker skipped: load average %.4f >= limit %.4f',
            $loadAverage,
            $limit
        ));

        return $this->emptyStats([
            'load_deferred' => true,
            'load_average' => $loadAverage,
            'load_limit' => $limit,
        ]);
    }

    private function getLockOwner()
    {
        return gethostname() . ':' . getmypid();
    }

    private function releaseRemainingRows(array $rows, $startIndex)
    {
        $this->queueRepository->release($this->rowIds(array_slice($rows, $startIndex)));
    }

    private function attachProfiles(array $rows)
    {
        $profiles = [];
        foreach ($rows as &$row) {
            $profileId = isset($row['profile_id']) ? (int)$row['profile_id'] : 0;
            if (!isset($profiles[$profileId])) {
                $profiles[$profileId] = $this->varyProfileResolver->resolve($profileId ?: null);
            }
            $row['_profile'] = $profiles[$profileId];
        }
        unset($row);
        return $rows;
    }

    private function rowIds(array $rows)
    {
        return array_values(array_filter(array_map(function ($row) {
            return isset($row['queue_id']) ? (int)$row['queue_id'] : 0;
        }, $rows)));
    }

    private function getClaimedWarmupRound(array $row)
    {
        return array_key_exists('warmup_round', $row) ? (int)$row['warmup_round'] : null;
    }

    private function getLane(array $rows)
    {
        $first = reset($rows);
        return [
            'profile_id' => isset($first['profile_id']) ? (int)$first['profile_id'] : 0,
            'mode' => (string)$first['mode'],
            'store_id' => (int)$first['store_id'],
        ];
    }

    private function requiresExecutionLaneLock(array $rows)
    {
        foreach ($rows as $row) {
            $profile = $row['_profile'] ?? [];
            if (!empty($profile['customer_id']) && !empty($profile['customer_session'])) {
                return true;
            }
        }
        return false;
    }
}
