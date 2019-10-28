<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class FlushCacheByEvents implements \Magento\Framework\Event\ObserverInterface
{

    /**
     * @var \Litespeed\Litemage\Model\CachePurge
     */
    protected $litemagePurge;

    /**
     *
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;
    
    /**
     * 
     * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
     * @param \Litespeed\Litemage\Model\Config $config
     */
    public function __construct(\Litespeed\Litemage\Model\CachePurge $litemagePurge,
            \Litespeed\Litemage\Model\Config $config)
    {
        $this->litemagePurge = $litemagePurge;
        $this->config = $config;
    }

    /**
     * Event based flush cache
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
		if (!$this->config->moduleEnabled())
			return;

		$event = $observer->getEvent();
		// do not use getEventName() directly, maybe empty
		$eventName = $event->getName();
        $msg = "FlushCacheByEvents $eventName";
		$tags = [];
		switch ($eventName) {
			case 'catalog_category_save_after':
			case 'catalog_category_delete_after':
				$tags[] = 'topnav';
				break;
			case 'litemage_purge':
				$tags = $event->getTags();
                $msg .= ' ' . $event->getReason();
				break;
		}
		if (!empty($tags)) {
			$this->litemagePurge->addPurgeTags($tags , $msg);
		}
    }

}
