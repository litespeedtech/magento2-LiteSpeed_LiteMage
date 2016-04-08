<?php
/**
 * LiteMage2
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

/**
 * Class ProcessLayoutRenderElement
 */
class ProcessLayoutRenderElement implements ObserverInterface
{
    /**
     * Application config object
     *
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $litemageCache;

    /**
     * Is varnish enabled flag
     *
     * @var bool
     */
    protected $_isLiteMageCacheable = -1;


    /**
     * Class constructor
     *
     * @param \Magento\PageCache\Model\Config $config
     */
    public function __construct(
            \Litespeed\Litemage\Model\CacheControl $litemageCache
    ) {
        $this->litemageCache = $litemageCache;
        if (!$litemageCache->moduleEnabled()) {
            $this->_isLiteMageCacheable = false;
        }
    }

    /**
     * Replace the output of the block, containing ttl attribute, with ESI tag
     *
     * @param \Magento\Framework\View\Element\AbstractBlock $block
     * @param \Magento\Framework\View\Layout $layout
     * @return string
     */
    protected function _replaceEsi(
        \Magento\Framework\View\Element\AbstractBlock $block,
        \Magento\Framework\View\Layout $layout,
       $transport )
    {
        $handles = $layout->getUpdate()->getHandles();
        $used = [];
        foreach ($handles as $h) {
            if (strpos($h, '_view_id_')) {
                break;
            }
            $used[] = $h;
        }
        $blockName = $block->getNameInLayout();
        $url = $block->getUrl(
            'page_cache/block/esi',
            [
                'blocks' => json_encode([$blockName]),
                'handles' => json_encode($used)
            ]
        );

        /*
         */
        $cacheTags = '';
        if ($block instanceof \Magento\Framework\DataObject\IdentityInterface) {
            $tags = implode(',',array_unique($block->getIdentities()));
            $tags = $this->litemageCache->translateTags($tags);
            $cacheTags = ' cache-tag="' . $tags . '"';
            // need to use a stack here. identity should include all children block's id
        }

        $uri = sprintf('<esi:include src="%s"%s cache-control="public"/>', $url, $cacheTags);

        $this->litemageCache->setEsiOn(true);
        $this->litemageCache->debugLog('replace esi ; ' . $uri);

        $output = $transport->getData('output');
        $origoutput = '<!-- lauren ' . $output . ' -->';
        $output = $uri;
        $output .= $origoutput;
        $transport->setData('output', $output);
    }

    /**
     * Is varnish cache engine enabled;
     ;
     * @return bool
     */
    protected function _checkLiteMageCacheable($layout)
    {
        if ($this->_isLiteMageCacheable === -1) {
            // module is enabled
            $this->_isLiteMageCacheable = $layout->isCacheable();
        }
        return $this->_isLiteMageCacheable;
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
        if ($this->_isLiteMageCacheable === -1) {
            $this->_checkLiteMageCacheable($observer->getEvent()->getLayout());
        }

        if (!$this->_isLiteMageCacheable) {
            return;
        }

        $event = $observer->getEvent();
        /** @var \Magento\Framework\View\Layout $layout */
        $layout = $event->getLayout();
        $name = $event->getElementName();
        /** @var \Magento\Framework\View\Element\AbstractBlock $block */
        if ($name == 'footer') {
            $name1='footer';
        }
        $block = $layout->getBlock($name);

        if ($block instanceof \Magento\Framework\View\Element\AbstractBlock) {
            $blockTtl = $block->getTtl();
            if (isset($blockTtl)) {
                $this->_replaceEsi($block, $layout, $event->getTransport());
            }
        }
    }
}
