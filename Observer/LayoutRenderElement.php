<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class LayoutRenderElement implements \Magento\Framework\Event\ObserverInterface
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
