<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Litespeed\Litemage\Model\Warmup\VaryProfileResolver;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmProfileUpsert extends Command
{
    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    public function __construct(VaryProfileResolver $varyProfileResolver)
    {
        $this->varyProfileResolver = $varyProfileResolver;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:profile:upsert');
        $this->setDescription('Create or update a LiteMage warmup vary profile.');
        $this->addArgument('code', InputArgument::REQUIRED, 'Profile code.');
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'Profile label.');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Profile type.', 'custom');
        $this->addOption('device', null, InputOption::VALUE_REQUIRED, 'Device vary: mobile or desktop.');
        $this->addOption('webp', null, InputOption::VALUE_NONE, 'Request WebP-capable HTML/image Accept headers.');
        $this->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Currency code for Magento currency-cookie vary.');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED, 'Accept-Language header value.');
        $this->addOption('customer-id', null, InputOption::VALUE_REQUIRED, 'Representative customer ID for real frontend-session warmup.');
        $this->addOption('customer-group', null, InputOption::VALUE_REQUIRED, 'Customer group ID for signed LiteMage warmup HttpContext.');
        $this->addOption('customer-auth', null, InputOption::VALUE_NONE, 'Set customer_logged_in=true for the signed customer group context.');
        $this->addOption('cookie', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Public cookie as name=value. Can be repeated.');
        $this->addOption('config', null, InputOption::VALUE_REQUIRED, 'Additional JSON config merged into this profile.');
        $this->addOption('inactive', null, InputOption::VALUE_NONE, 'Save the profile as inactive.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $code = (string)$input->getArgument('code');
            $label = (string)($input->getOption('label') ?: $code);
            $config = $this->buildConfig($input);
            $profileId = $this->varyProfileResolver->upsert(
                $code,
                $label,
                (string)$input->getOption('type'),
                $config,
                !$input->getOption('inactive')
            );
            $output->writeln(sprintf('Saved LiteMage warmup profile %s as ID %d.', $code, $profileId));
            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function buildConfig(InputInterface $input)
    {
        $config = [];
        if ($input->getOption('config')) {
            $decoded = json_decode((string)$input->getOption('config'), true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Profile config must be a JSON object.');
            }
            $config = $decoded;
        }
        if ($input->getOption('device')) {
            $config['device'] = (string)$input->getOption('device');
        }
        if ($input->getOption('webp')) {
            $config['webp'] = true;
        }
        if ($input->getOption('currency')) {
            $config['currency'] = strtoupper((string)$input->getOption('currency'));
        }
        if ($input->getOption('language')) {
            $config['language'] = (string)$input->getOption('language');
        }
        if ($input->getOption('customer-id') !== null) {
            $customerId = (int)$input->getOption('customer-id');
            if ($customerId <= 0) {
                throw new \InvalidArgumentException('Customer ID must be greater than zero.');
            }
            $config['customer_id'] = $customerId;
            $config['customer_session'] = true;
        }
        if ($input->getOption('customer-group') !== null) {
            $groupId = (int)$input->getOption('customer-group');
            if ($groupId < 0) {
                throw new \InvalidArgumentException('Customer group ID must be zero or greater.');
            }
            $config['customer_group_id'] = $groupId;
            $config['customer_group_logged_in'] = (bool)$input->getOption('customer-auth');
        }
        foreach ((array)$input->getOption('cookie') as $cookie) {
            [$name, $value] = array_pad(explode('=', (string)$cookie, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                throw new \InvalidArgumentException('Cookie option must use name=value syntax.');
            }
            $config['cookies'][$name] = $value;
        }
        return $config;
    }
}
