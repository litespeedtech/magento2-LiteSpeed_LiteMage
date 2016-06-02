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
 * @copyright  Copyright (c) 2016 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */


namespace Litespeed\Litemage\Observer;

use Magento\Framework\Event\ObserverInterface;

class LayoutRenderElement implements ObserverInterface
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;
    protected $_injectBlocks = [];

    /**
     * Class constructor
     *
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
    \Litespeed\Litemage\Model\CacheControl $litemageCache
    )
    {
        $this->litemageCache = $litemageCache;
        //$this->_injectBlocks = ['footer'];
    }

    /**
     * Replace the output of the block, containing ttl attribute, with ESI tag
     *
     * @param \Magento\Framework\View\Element\AbstractBlock $block
     * @param \Magento\Framework\View\Layout $layout
     * @return string
     */
    protected function _replaceEsi(
    $blockName, \Magento\Framework\View\Layout $layout, $transport)
    {
        $handles = $layout->getUpdate()->getHandles();
        $url = $this->litemageCache->getEsiUrl($handles, $blockName);

        $cacheTags = $this->litemageCache->getElementCacheTags($layout,
                                                               $blockName);
        if ($cacheTags) {
            $cacheTags = ' cache-tag="' . $cacheTags . '"';
        }

        $uri = sprintf('<esi:include src="%s"%s cache-control="public"/>', $url,
                       $cacheTags);

        $this->litemageCache->setEsiOn(true);
        $output = $uri; // discard original output

        if ($this->litemageCache->debugEnabled()) {
            $this->litemageCache->debugLog('replace esi ; ' . $uri);
            $output = '<!--litemage_esi start ' . $blockName . '-->' . $uri . '<!-- litemage_esi end -->';
        }
        $transport->setData('output', $output);
    }

    /**
     * Add comment cache containers to private blocks
     * Blocks are wrapped only if page is cacheable
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->litemageCache->canInjectEsi()) {
            return;
        }

        $event = $observer->getEvent();
        /** @var \Magento\Framework\View\Layout $layout */
        $layout = $event->getLayout();
        $name = $event->getElementName();

        if (in_array($name, $this->_injectBlocks)) {
            $this->_replaceEsi($name, $layout, $event->getTransport());
        } else {
            /** @var \Magento\Framework\View\Element\AbstractBlock $block */
            $block = $layout->getBlock($name);

            if ($block instanceof \Magento\Framework\View\Element\AbstractBlock) {
                $blockTtl = $block->getTtl();
                if (isset($blockTtl)) {
                    $this->_replaceEsi($name, $layout, $event->getTransport());
                }
            }
        }
    }

}
