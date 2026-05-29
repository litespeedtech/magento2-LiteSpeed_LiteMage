<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\HttpWarmupClient;
use Litespeed\Litemage\Model\Warmup\UrlNormalizer;
use Litespeed\Litemage\Model\Warmup\VaryProfileResolver;
use Litespeed\Litemage\Logger\WarmupLogger;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmUrl extends Command
{
    /**
     * @var UrlNormalizer
     */
    private $urlNormalizer;

    /**
     * @var HttpWarmupClient
     */
    private $httpWarmupClient;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var WarmupLogger
     */
    private $logger;

    public function __construct(
        UrlNormalizer $urlNormalizer,
        HttpWarmupClient $httpWarmupClient,
        Config $config,
        VaryProfileResolver $varyProfileResolver,
        WarmupLogger $logger
    ) {
        $this->urlNormalizer = $urlNormalizer;
        $this->httpWarmupClient = $httpWarmupClient;
        $this->config = $config;
        $this->varyProfileResolver = $varyProfileResolver;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:url');
        $this->setDescription('Warm or debug one LiteMage URL without queue generation.');
        $this->addArgument('url', InputArgument::REQUIRED, 'URL to warm.');
        $this->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Store ID for relative URLs.');
        $this->addOption('mode', null, InputOption::VALUE_OPTIONAL, 'runner or walker.');
        $this->addOption('profile', null, InputOption::VALUE_OPTIONAL, 'Profile ID or code. Defaults to guest.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('mode') ?: $this->config->getWarmupDefaultDeltaMode();
        $profile = $this->varyProfileResolver->resolve($input->getOption('profile'));
        $normalized = $this->urlNormalizer->normalize($input->getArgument('url'), $input->getOption('store'));
        $row = $normalized + [
            'queue_id' => null,
            'url_id' => null,
            'profile_id' => $profile['profile_id'] ?? null,
            'mode' => $mode,
        ];
        if ($output->isVerbose()) {
            $output->writeln('Profile: ' . ($profile['code'] ?? 'guest'));
            $output->writeln('cURL: ' . $this->httpWarmupClient->buildCurlCommand(
                $normalized['url'],
                $mode,
                $profile,
                $normalized['store_id'],
                true
            ));
        }
        $result = $this->httpWarmupClient->warm($row, $profile);
        $this->logger->notice(sprintf(
            'CLI warm:url url=%s mode=%s profile=%s status=%s http=%s cache=%s time=%sms error=%s',
            $normalized['url'],
            $mode,
            $profile['code'] ?? 'guest',
            $result['status'],
            $result['http_status'] === null ? '-' : $result['http_status'],
            $result['cache_status'] ?: '-',
            $result['response_time_ms'],
            $result['error'] ?? '-'
        ));
        $output->writeln(sprintf(
            'LiteMage warm URL: status=%s http=%s cache=%s time=%sms',
            $result['status'],
            $result['http_status'] === null ? '-' : $result['http_status'],
            $result['cache_status'] ?: '-',
            $result['response_time_ms']
        ));
        if (!empty($result['error'])) {
            $output->writeln($result['error']);
        }
        if (!empty($result['headers_summary'])) {
            $output->writeln($result['headers_summary']);
        }
        return Cli::RETURN_SUCCESS;
    }
}
