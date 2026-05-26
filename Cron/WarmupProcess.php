<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Cron;

use Litespeed\Litemage\Helper\Data as Helper;
use Litespeed\Litemage\Model\Warmup\RunnerEventRepository;
use Litespeed\Litemage\Model\Warmup\Worker;

class WarmupProcess
{
    /**
     * @var Worker
     */
    private $worker;

    /**
     * @var RunnerEventRepository
     */
    private $runnerEventRepository;

    /**
     * @var Helper
     */
    private $helper;

    public function __construct(
        Worker $worker,
        RunnerEventRepository $runnerEventRepository,
        Helper $helper
    ) {
        $this->worker = $worker;
        $this->runnerEventRepository = $runnerEventRepository;
        $this->helper = $helper;
    }

    public function execute()
    {
        $eventId = $this->runnerEventRepository->start(
            RunnerEventRepository::RUNNER_PROCESS,
            RunnerEventRepository::MODE_CRON
        );
        try {
            $stats = $this->worker->process();
            $this->runnerEventRepository->finish($eventId, $this->getEventStatus($stats), $stats);
            if (!empty($stats['load_deferred'])) {
                $this->helper->debugLog(sprintf(
                    'Warmup cron skipped due to server load %.4f >= %.4f',
                    $stats['load_average'],
                    $stats['load_limit']
                ));
            } elseif (empty($stats['disabled'])) {
                $this->helper->debugLog(sprintf(
                    'Warmup cron processed claimed=%d warmed=%d skipped=%d failed=%d',
                    $stats['claimed'],
                    $stats['warmed'],
                    $stats['skipped'],
                    $stats['failed']
                ));
            }
        } catch (\Exception $e) {
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_FAILED,
                [],
                $e->getMessage()
            );
            $this->helper->debugLog('Warmup process cron failed: ' . $e->getMessage(), true);
        }
    }

    private function getEventStatus(array $stats)
    {
        if (!empty($stats['disabled'])) {
            return RunnerEventRepository::STATUS_DISABLED;
        }
        if (!empty($stats['load_deferred'])) {
            return RunnerEventRepository::STATUS_LOAD_SKIPPED;
        }

        return RunnerEventRepository::STATUS_SUCCESS;
    }
}
