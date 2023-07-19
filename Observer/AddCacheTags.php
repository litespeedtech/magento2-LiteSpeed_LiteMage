<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class AddCacheTags implements \Magento\Framework\Event\ObserverInterface
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * 
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(\Litespeed\Litemage\Model\CacheControl $litemageCache)
    {
        $this->litemageCache = $litemageCache;
    }

    /**
     * Event based litemage_add_cache_tags
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->litemageCache->maybeCacheable()) {
            return;
        }

		$event = $observer->getEvent();
		$tags = $event->getTags(); // array of tags
		if (!empty($tags)) {
			$this->litemageCache->addCacheTags($tags);
		}
    }

}
