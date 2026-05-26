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
use Litespeed\Litemage\Model\Warmup\QueueGenerator;
use Litespeed\Litemage\Model\Warmup\RunnerEventRepository;

class WarmupGenerate
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var QueueGenerator
     */
    private $queueGenerator;

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
        QueueGenerator $queueGenerator,
        RunnerEventRepository $runnerEventRepository,
        Helper $helper
    ) {
        $this->config = $config;
        $this->queueGenerator = $queueGenerator;
        $this->runnerEventRepository = $runnerEventRepository;
        $this->helper = $helper;
    }

    public function execute()
    {
        if (!$this->config->isWarmupEnabled()) {
            $this->runnerEventRepository->record(
                RunnerEventRepository::RUNNER_GENERATE,
                RunnerEventRepository::MODE_CRON,
                RunnerEventRepository::STATUS_DISABLED
            );
            return;
        }

        $eventId = $this->runnerEventRepository->start(
            RunnerEventRepository::RUNNER_GENERATE,
            RunnerEventRepository::MODE_CRON
        );
        try {
            $stats = $this->queueGenerator->generate();
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_SUCCESS,
                $stats,
                !empty($stats['errors']) ? implode("\n", array_slice($stats['errors'], 0, 10)) : null
            );
            $this->helper->debugLog(sprintf(
                'Warmup cron generated seen=%d enqueued=%d skipped=%d',
                $stats['seen'],
                $stats['enqueued'],
                $stats['skipped']
            ));
        } catch (\Exception $e) {
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_FAILED,
                [],
                $e->getMessage()
            );
            $this->helper->debugLog('Warmup generate cron failed: ' . $e->getMessage(), true);
        }
    }
}
