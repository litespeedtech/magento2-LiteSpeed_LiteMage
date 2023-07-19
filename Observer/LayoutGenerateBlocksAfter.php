<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class LayoutGenerateBlocksAfter
        implements \Magento\Framework\Event\ObserverInterface
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * Class constructor
     *
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(\Litespeed\Litemage\Model\CacheControl $litemageCache)
    {
        $this->litemageCache = $litemageCache;
    }

    /**
     * Check if still cacheable
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->litemageCache->maybeCacheable()) {
            return;
        }
        $layout = $observer->getEvent()->getLayout();
        if (!$layout->isCacheable()) {
            // not cacheable by layout, find out which blocks caused that for trouble shooting
            if ($this->litemageCache->debugEnabled()) {
                $nocache = '//' . \Magento\Framework\View\Layout\Element::TYPE_BLOCK . '[@cacheable="false"]';
                $blocks = $layout->getUpdate()->asSimplexml()->xpath($nocache);
				$notcacheable = [];
				foreach ($blocks as $block) {
					if ($block->getAttribute('cacheable') === 'false') {
						$notcacheable[] = $block->attributes();
					}
				}
                $str = print_r($notcacheable, true);
                $shortmsg = 'Layout has uncacheable blocks ';
                if (preg_match_all('/\[name\] => ([^\s]+)/', $str, $m)) {
                    $shortmsg .= implode(', ', $m[1]);
                }
                $msg = 'Observer LayoutGenerateBlocksAfter Blocks not cacheable ' . $str;
            } else {
                $msg = $shortmsg = 'layout blocks';
            }
            $this->litemageCache->setNotCacheable($msg, $shortmsg);
        }
    }

}
