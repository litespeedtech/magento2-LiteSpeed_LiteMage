<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\CrawlerMode;
use Litespeed\Litemage\Model\Warmup\Worker;
use Litespeed\Litemage\Logger\WarmupLogger;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmQueueProcess extends Command
{
    /**
     * @var Worker
     */
    private $worker;

    /**
     * @var CrawlerMode
     */
    private $crawlerMode;

    /**
     * @var WarmupLogger
     */
    private $logger;

    public function __construct(Worker $worker, CrawlerMode $crawlerMode, WarmupLogger $logger)
    {
        $this->worker = $worker;
        $this->crawlerMode = $crawlerMode;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:queue:process');
        $this->setDescription('Process due LiteMage cache warmer queued URLs.');
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum queued URL rows to process.');
        $this->addOption('profile', null, InputOption::VALUE_OPTIONAL, 'Only process queued URLs for one profile ID.');
        $this->addOption('mode', null, InputOption::VALUE_OPTIONAL, 'Only process one crawler mode: runner or walker.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        $filters = [];
        if ($input->getOption('mode')) {
            $filters['mode'] = $this->crawlerMode->normalize($input->getOption('mode'));
        }
        if ($input->getOption('profile') !== null && $input->getOption('profile') !== '') {
            $filters['profile_id'] = (int)$input->getOption('profile');
        }
        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                'Processing LiteMage warmer queued URLs with limit=%s mode=%s profile=%s',
                $limit === null ? 'config' : (int)$limit,
                $filters['mode'] ?? 'any',
                array_key_exists('profile_id', $filters) ? $filters['profile_id'] : 'any'
            ));
        }
        $this->logger->notice(sprintf(
            'CLI queue:process started limit=%s mode=%s profile=%s',
            $limit === null ? 'config' : (int)$limit,
            $filters['mode'] ?? 'any',
            array_key_exists('profile_id', $filters) ? $filters['profile_id'] : 'any'
        ));

        $stats = $this->worker->process($limit === null ? null : (int)$limit, $filters);
        if (!empty($stats['disabled'])) {
            $output->writeln('LiteMage cache warmer is disabled.');
            return Cli::RETURN_SUCCESS;
        }
        if (!empty($stats['load_deferred'])) {
            $output->writeln(sprintf(
                'LiteMage warmer skipped: server load %.4f is at or above the configured limit %.4f.',
                $stats['load_average'],
                $stats['load_limit']
            ));
            return Cli::RETURN_SUCCESS;
        }
        $output->writeln(sprintf(
            'LiteMage warmer processed: claimed=%d warmed=%d skipped=%d failed=%d lane_locked=%d',
            $stats['claimed'],
            $stats['warmed'],
            $stats['skipped'],
            $stats['failed'],
            $stats['lane_locked'] ?? 0
        ));
        $this->logger->notice(sprintf(
            'CLI queue:process finished claimed=%d warmed=%d skipped=%d failed=%d lane_locked=%d',
            $stats['claimed'],
            $stats['warmed'],
            $stats['skipped'],
            $stats['failed'],
            $stats['lane_locked'] ?? 0
        ));
        return Cli::RETURN_SUCCESS;
    }
}
