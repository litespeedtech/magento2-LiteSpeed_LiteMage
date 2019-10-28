<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class LayoutGenerateBlocksAfter implements \Magento\Framework\Event\ObserverInterface
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
        $msg = 'Observer LayoutGenerateBlocksAfter';
        if ($layout->isCacheable()) {
            // only now, as maybe multiple layout
            $this->litemageCache->setCacheable(null, $msg);
        } else {
            // not cacheable by layout, find out which blocks caused that for trouble shooting
            $nocache = '//' . \Magento\Framework\View\Layout\Element::TYPE_BLOCK . '[@cacheable="false"]';
            $blocks = $layout->getUpdate()->asSimplexml()->xpath($nocache);
            $str = print_r($blocks, 1);
            $shortmsg = 'Layout has uncacheable blocks ';
            if (preg_match_all('/\[name\] => ([^\s]+)/', $str, $m)) {
                $shortmsg .= implode(', ', $m[1]);
            }
            $msg .= ' Blocks not cacheable ' . $str;
            $this->litemageCache->setNotCacheable($msg, $shortmsg);
        }
    }

}
