<?php
/**
 * LiteMage
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) 2016-2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace Litespeed\Litemage\Observer;

use Magento\Framework\Event\ObserverInterface;

class FlushCacheByEvents implements ObserverInterface
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
    \Litespeed\Litemage\Model\CacheControl $litemageCache)
    {
        $this->litemageCache = $litemageCache;
    }

    /**
     * Event based flush cache
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
		if (!$this->litemageCache->moduleEnabled())
			return;
		
		$event = $observer->getEvent();
		// do not use getEventName() directly, maybe empty
		$eventName = $event->getName(); 
		$tags = [];
		switch ($eventName) {
			case 'catalog_category_save_after':
			case 'catalog_category_delete_after':
				$tags[] = 'topnav';
				break;
			case 'litemage_purge':
				$tags = $event->getTags();
				break;
		}
		if (!empty($tags)) {
			$this->litemageCache->addPurgeTags($tags , "FlushCacheByEvents $eventName");
		}
    }

}
