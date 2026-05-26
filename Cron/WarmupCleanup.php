<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Cron;

use Litespeed\Litemage\Helper\Data as Helper;
use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\PurgeEventRepository;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Litespeed\Litemage\Model\Warmup\ReverseIndexRepository;
use Litespeed\Litemage\Model\Warmup\ResultRepository;
use Litespeed\Litemage\Model\Warmup\RunnerEventRepository;

class WarmupCleanup
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
     * @var PurgeEventRepository
     */
    private $purgeEventRepository;

    /**
     * @var ReverseIndexRepository
     */
    private $reverseIndexRepository;

    /**
     * @var RunnerEventRepository
     */
    private $runnerEventRepository;

    /**
     * @var Helper
     */
    private $helper;

    public function __construct(
        Config $config,
        QueueRepository $queueRepository,
        ResultRepository $resultRepository,
        PurgeEventRepository $purgeEventRepository,
        ReverseIndexRepository $reverseIndexRepository,
        RunnerEventRepository $runnerEventRepository,
        Helper $helper
    ) {
        $this->config = $config;
        $this->queueRepository = $queueRepository;
        $this->resultRepository = $resultRepository;
        $this->purgeEventRepository = $purgeEventRepository;
        $this->reverseIndexRepository = $reverseIndexRepository;
        $this->runnerEventRepository = $runnerEventRepository;
        $this->helper = $helper;
    }

    public function execute()
    {
        $eventId = $this->runnerEventRepository->start(
            RunnerEventRepository::RUNNER_CLEANUP,
            RunnerEventRepository::MODE_CRON
        );
        try {
            $days = $this->config->getWarmupResultRetentionDays();
            $resultRows = $this->resultRepository->cleanup($days);
            $queueRows = $this->queueRepository->cleanupCompleted($days);
            $purgeEventRows = $this->purgeEventRepository->cleanup($days);
            $reverseIndexRows = $this->reverseIndexRepository->cleanupExpired();
            $runnerEventRows = $this->runnerEventRepository->cleanup($days);
            $stats = [
                'result_rows' => $resultRows,
                'queue_rows' => $queueRows,
                'purge_event_rows' => $purgeEventRows,
                'reverse_index_rows' => $reverseIndexRows,
                'runner_event_rows' => $runnerEventRows,
            ];
            $this->runnerEventRepository->finish($eventId, RunnerEventRepository::STATUS_SUCCESS, $stats);
            $this->helper->debugLog(sprintf(
                'Warmup cleanup removed result_rows=%d queue_rows=%d purge_event_rows=%d reverse_index_rows=%d runner_event_rows=%d',
                $resultRows,
                $queueRows,
                $purgeEventRows,
                $reverseIndexRows,
                $runnerEventRows
            ));
        } catch (\Exception $e) {
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_FAILED,
                [],
                $e->getMessage()
            );
            $this->helper->debugLog('Warmup cleanup cron failed: ' . $e->getMessage(), true);
        }
    }
}
