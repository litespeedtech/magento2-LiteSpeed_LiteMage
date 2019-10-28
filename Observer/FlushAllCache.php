<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class FlushAllCache implements \Magento\Framework\Event\ObserverInterface
{

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /** @var \Magento\Framework\Event\ManagerInterface */
    protected $eventManager;

    /**
     * @param \Litespeed\Litemage\Model\Config $config,
     * @param \Magento\Framework\Event\ManagerInterface $eventManager,
     */
    public function __construct(\Litespeed\Litemage\Model\Config $config,
                                \Magento\Framework\Event\ManagerInterface $eventManager)
    {
        $this->config = $config;
        $this->eventManager = $eventManager;
    }

    /**
     * Flush All Litemage cache
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->moduleEnabled()) {
            return;
        }

        $reason = 'FlushAllCache from ' . $observer->getEvent()->getName();
        $param = ['tags' => ['*'], 'reason' => $reason];

        if (PHP_SAPI == 'cli') {
            // from command line
            $this->eventManager->dispatch('litemage_cli_purge', $param);
        } else {
            $this->eventManager->dispatch('litemage_purge', $param);
        }
    }

}
