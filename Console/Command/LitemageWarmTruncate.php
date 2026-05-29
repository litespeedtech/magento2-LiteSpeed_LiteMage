<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\DataCleaner;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmTruncate extends Command
{
    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var DataCleaner
     */
    private $dataCleaner;

    public function __construct(
        QueueRepository $queueRepository,
        DataCleaner $dataCleaner
    ) {
        $this->queueRepository = $queueRepository;
        $this->dataCleaner = $dataCleaner;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:truncate');
        $this->setDescription('Delete LiteMage cache warmer data.');
        $this->addOption('failed-only', null, InputOption::VALUE_NONE, 'Delete only failed queued URL rows.');
        $this->addOption(
            'all-data',
            null,
            InputOption::VALUE_NONE,
            'Delete all LiteMage warmer data and temporary warmer files.'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('all-data')) {
            if ($input->getOption('failed-only')) {
                $output->writeln('<error>Use either --all-data or --failed-only, not both.</error>');
                return Cli::RETURN_FAILURE;
            }

            $result = $this->dataCleaner->truncateAll();
            foreach ($result['tables'] as $table => $count) {
                $output->writeln(sprintf('%s: deleted %d row(s).', $table, $count));
            }
            $output->writeln(sprintf(
                'Deleted %d LiteMage warmer row(s) total.',
                array_sum($result['tables'])
            ));
            $output->writeln(sprintf(
                'Deleted %d LiteMage warmer temporary file(s).',
                count($result['files'])
            ));
            return Cli::RETURN_SUCCESS;
        }

        $deleted = $this->queueRepository->truncate((bool)$input->getOption('failed-only'));
        $output->writeln(sprintf('Deleted %d LiteMage warmer queued URL row(s).', $deleted));
        return Cli::RETURN_SUCCESS;
    }
}
