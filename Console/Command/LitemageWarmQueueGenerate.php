<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\QueueGenerator;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Litespeed\Litemage\Logger\WarmupLogger;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmQueueGenerate extends Command
{
    /**
     * @var QueueGenerator
     */
    private $queueGenerator;

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var WarmupLogger
     */
    private $logger;

    public function __construct(
        QueueGenerator $queueGenerator,
        QueueRepository $queueRepository,
        StoreManagerInterface $storeManager,
        WarmupLogger $logger
    ) {
        $this->queueGenerator = $queueGenerator;
        $this->queueRepository = $queueRepository;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:queue:generate');
        $this->setDescription('Build or update the LiteMage cache warmer queue.');
        $this->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Generate from one source only.');
        $this->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Comma-separated store IDs or codes to generate.');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Delete existing queued URLs before generation.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate sources without updating queued URLs.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $storeIds = $this->parseStoreIds((string)$input->getOption('store'));
        } catch (NoSuchEntityException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($input->getOption('reset') && !$input->getOption('dry-run')) {
            $deleted = $this->queueRepository->truncate(false);
            $output->writeln(sprintf('Deleted %d existing queued URL row(s).', $deleted));
        }
        $stats = $this->queueGenerator->generate(
            $input->getOption('source'),
            (bool)$input->getOption('dry-run'),
            $storeIds
        );
        $this->logger->notice(sprintf(
            'CLI queue:generate%s source=%s stores=%s seen=%d updated=%d skipped=%d errors=%d',
            $input->getOption('dry-run') ? ' dry-run' : '',
            $input->getOption('source') ?: 'configured',
            $storeIds ? implode(',', $storeIds) : 'all',
            $stats['seen'],
            $stats['enqueued'],
            $stats['skipped'],
            count($stats['errors'])
        ));
        $output->writeln(sprintf(
            'LiteMage warmer queue build%s: seen=%d %s=%d skipped=%d',
            $input->getOption('dry-run') ? ' dry-run' : '',
            $stats['seen'],
            $input->getOption('dry-run') ? 'would_update' : 'updated',
            $stats['enqueued'],
            $stats['skipped']
        ));
        foreach (($stats['source_stats'] ?? []) as $sourceStats) {
            $output->writeln(sprintf(
                ' - source=%s source_rows=%d rows_seen=%d generated=%d skipped=%d errors=%d',
                $sourceStats['source'] ?? 'unknown',
                (int)($sourceStats['source_rows'] ?? 0),
                (int)($sourceStats['rows_seen'] ?? 0),
                (int)($sourceStats['generated'] ?? 0),
                (int)($sourceStats['skipped'] ?? 0),
                count($sourceStats['errors'] ?? [])
            ));
        }
        foreach (array_slice($stats['errors'], 0, 10) as $error) {
            $output->writeln(' - ' . $error);
        }
        return $stats['errors'] ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }

    private function parseStoreIds($storeOption)
    {
        $storeOption = trim((string)$storeOption);
        if ($storeOption === '') {
            return [];
        }

        $storeIds = [];
        foreach (array_filter(array_map('trim', explode(',', $storeOption))) as $storeCodeOrId) {
            $storeIds[] = (int)$this->storeManager->getStore($storeCodeOrId)->getId();
        }

        return array_values(array_unique($storeIds));
    }
}
