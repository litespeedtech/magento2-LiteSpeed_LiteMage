<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Litespeed\Litemage\Model\Warmup\UrlNormalizer;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmBlacklist extends Command
{
    /**
     * @var UrlNormalizer
     */
    private $urlNormalizer;

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    public function __construct(UrlNormalizer $urlNormalizer, QueueRepository $queueRepository)
    {
        $this->urlNormalizer = $urlNormalizer;
        $this->queueRepository = $queueRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('cache:litemage:warm:blacklist');
        $this->setDescription('Blacklist or unblacklist one LiteMage cache warmer URL.');
        $this->addArgument('url', InputArgument::REQUIRED, 'URL to blacklist or unblacklist.');
        $this->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Store ID for relative URLs.');
        $this->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the URL from the blacklist.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $normalized = $this->urlNormalizer->normalize($input->getArgument('url'), $input->getOption('store'));
        $blacklisted = !$input->getOption('remove');
        $this->queueRepository->setBlacklisted($normalized + ['source' => 'manual'], $blacklisted);
        $output->writeln(sprintf(
            'LiteMage warmer URL %s: %s',
            $blacklisted ? 'blacklisted' : 'unblacklisted',
            $normalized['url']
        ));
        return Cli::RETURN_SUCCESS;
    }
}
