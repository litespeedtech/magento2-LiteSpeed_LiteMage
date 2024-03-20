<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Command for flush litemage cache by category IDs
 */
class LitemageCliFlush extends Command
{
	/**
	 * @var \Litespeed\Litemage\Model\Config
	 */
	protected $config;
    
    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    protected  $configWriter;
    
    /**
     * 
     * @var \Magento\Framework\App\Cache\TypeListInterface 
     */
    protected $cacheTypeList;
        
    public function __construct(\Litespeed\Litemage\Model\Config $config,
            \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
            \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList)
    {
        $this->config = $config;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('cache:litemage:cli-flush');
        $this->setDescription('Sets/Gets LiteMage CLI flush status');
        $this->addArgument(
               'action', 
                InputArgument::REQUIRED, 
                'allowed string: status, enable, disable'
        );
		parent::configure();
	}
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $cur_allowed = !$this->config->isCliPurgeDisabled();
        
        switch ($action) {
            case 'enable':
                $mesg = $this->setCliFlush($cur_allowed, 1);
                break;
            case 'disable':
                $mesg = $this->setCliFlush($cur_allowed, 0);
                break;
            case 'status':
            //case '':
                $mesg = $this->getCurrentStatus($cur_allowed);
                break;
            default:
                throw new \InvalidArgumentException(
				'Invalid action input, allowed values: status, enable, disable');
        }
        $output->writeln($mesg);
	}

    private function getCurrentStatus($allowed)
    {
        return 'LiteMage CLI flush is currently ' . ($allowed ? 'enabled' : 'disabled');
    }
    
    private function setCliFlush($current_allowed, $new_allowed)
    {
        $old_status = $current_allowed ? 'enabled' : 'disabled';
        if ($current_allowed == $new_allowed) {
            return "LiteMage CLI flush is already $old_status. No action needed.";
        }
        $new_status = $new_allowed ? 'enabled' : 'disabled';
        $new_value = $new_allowed ? 0 : 1;
        $this->configWriter->save('litemage/purge/disable_cli_purge', $new_value);
        
        // need to flush config cache
        $this->cacheTypeList->cleanType('config');
        
        return sprintf('LiteMage CLI flush has been %s', $new_status);
    }

}
