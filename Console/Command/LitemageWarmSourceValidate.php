<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\Source\SitemapUrlSource;
use Litespeed\Litemage\Model\Warmup\Source\TextFileUrlSource;
use Litespeed\Litemage\Logger\WarmupLogger;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmSourceValidate extends Command
{
    /**
     * @var TextFileUrlSource
     */
    private $textFileUrlSource;

    /**
     * @var SitemapUrlSource
     */
    private $sitemapUrlSource;

    /**
     * @var WarmupLogger
     */
    private $logger;

    public function __construct(
        TextFileUrlSource $textFileUrlSource,
        WarmupLogger $logger,
        ?SitemapUrlSource $sitemapUrlSource = null
    ) {
        $this->textFileUrlSource = $textFileUrlSource;
        $this->sitemapUrlSource = $sitemapUrlSource ?: ObjectManager::getInstance()->get(SitemapUrlSource::class);
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('cache:litemage:warm:source:validate');
        $this->setDescription('Validate LiteMage warmup URL source configuration.');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source to validate: text_file or sitemap.', 'text_file');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $source = (string)$input->getOption('source');
        if (!in_array($source, ['text_file', 'sitemap'], true)) {
            $output->writeln('<error>Only text_file and sitemap validation are supported.</error>');
            return Cli::RETURN_FAILURE;
        }

        $stats = $source === 'sitemap'
            ? $this->sitemapUrlSource->validate()
            : $this->textFileUrlSource->validate();
        $this->logger->notice(sprintf(
            'CLI source:validate source=%s source_rows=%d files=%d rows=%d generated=%d skipped=%d errors=%d',
            $source,
            $stats['source_rows'] ?? 0,
            $stats['files'] ?? 0,
            $stats['rows'] ?? ($stats['rows_seen'] ?? 0),
            $stats['generated'] ?? ($stats['valid'] ?? 0),
            $stats['skipped'],
            count($stats['errors'])
        ));
        $output->writeln(sprintf(
            'LiteMage %s source validation: source_rows=%d files=%d rows=%d generated=%d skipped=%d errors=%d',
            $source,
            $stats['source_rows'] ?? 0,
            $stats['files'] ?? 0,
            $stats['rows'] ?? ($stats['rows_seen'] ?? 0),
            $stats['generated'] ?? ($stats['valid'] ?? 0),
            $stats['skipped'],
            count($stats['errors'])
        ));
        foreach (array_slice($stats['errors'], 0, 10) as $error) {
            $output->writeln(' - ' . $error);
        }

        return $stats['errors'] ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
