<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\VaryProfileResolver;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmProfileList extends Command
{
    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var Config
     */
    private $config;

    public function __construct(VaryProfileResolver $varyProfileResolver, Config $config)
    {
        $this->varyProfileResolver = $varyProfileResolver;
        $this->config = $config;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:profile:list');
        $this->setDescription('List LiteMage cache warmer request profile codes.');
        $this->addOption('active', null, InputOption::VALUE_NONE, 'Show active profiles only.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print profiles as JSON.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->varyProfileResolver->syncBusinessProfiles(
                $this->config->getWarmupCurrencyCodes(),
                $this->config->getWarmupCustomerIds()
            );
            $profiles = $this->varyProfileResolver->getProfiles((bool)$input->getOption('active'));
            if ($input->getOption('json')) {
                $output->writeln(json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::RETURN_SUCCESS;
            }

            $table = new Table($output);
            $table->setHeaders(['Code', 'Label', 'Type', 'Status', 'Config']);
            foreach ($profiles as $profile) {
                $table->addRow([
                    $profile['code'],
                    $profile['label'],
                    $profile['type'],
                    $this->formatStatus($profile),
                    $this->formatConfig($profile),
                ]);
            }
            $table->render();
            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function formatStatus(array $profile)
    {
        if (!empty($profile['is_builtin'])) {
            return 'built-in';
        }

        return !empty($profile['is_active']) ? 'active' : 'inactive';
    }

    private function formatConfig(array $profile)
    {
        if (!empty($profile['is_builtin'])) {
            return 'Baseline anonymous request; not a vary case.';
        }

        return empty($profile['config'])
            ? ''
            : json_encode($profile['config'], JSON_UNESCAPED_SLASHES);
    }
}
