<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmStatus extends Command
{
    /**
     * @var QueueRepository
     */
    private $queueRepository;

    public function __construct(QueueRepository $queueRepository)
    {
        $this->queueRepository = $queueRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('cache:litemage:warm:status');
        $this->setDescription('Show LiteMage cache warmer queue status counts.');
        $this->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Filter by store ID.');
        $this->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Filter by queue source.');
        $this->addOption('failed', null, InputOption::VALUE_NONE, 'Show failed rows only.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $store = $input->getOption('store');
        $source = $input->getOption('source');
        $summary = $this->queueRepository->getStatusSummary(
            $store === null ? null : (int)$store,
            $source === null ? null : (string)$source,
            (bool)$input->getOption('failed')
        );
        if (!$summary) {
            $output->writeln('LiteMage warmer queue is empty.');
            return Cli::RETURN_SUCCESS;
        }

        foreach ($summary as $status => $count) {
            $output->writeln(sprintf('%s: %d', $status, $count));
        }
        return Cli::RETURN_SUCCESS;
    }
}
