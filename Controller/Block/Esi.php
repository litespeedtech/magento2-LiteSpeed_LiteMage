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

namespace Litespeed\Litemage\Controller\Block;

class Esi extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Framework\Translate\InlineInterface
     */
    protected $translateInline;

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;


    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Translate\InlineInterface $translateInline
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
            \Magento\Framework\Translate\InlineInterface $translateInline,
            \Litespeed\Litemage\Model\CacheControl $litemageCache
    ) {
        parent::__construct($context);
        $this->translateInline = $translateInline;
        $this->litemageCache = $litemageCache;
    }

    /**
     * Returns block content as part of ESI request from Varnish
     *
     * @return void
     */
    public function execute()
    {
        $this->litemageCache->setEsiRequest();

        if ($layout = $this->_prepareLayout($block)) {
            $response = $this->getResponse();
            $ttl = 86400;
            $html = $layout->renderElement($block);
            if ( $tags = $this->litemageCache->getElementCacheTags($layout, $block) ) {
                $this->litemageCache->setCacheTags($tags);
            }

            $this->translateInline->processResponseBody($html);
            $response->appendBody($html);
            $response->setPublicHeaders($ttl);
        }
    }

    /**
     * Get blocks from layout by handles
     *
     * @return array [\Element\BlockInterface]
     */
    protected function _prepareLayout(&$block)
    {
        $request = $this->getRequest();

        $block = $request->getParam('b');
        $handle = $request->getParam('h');

        if (!$handle || !$block) {
            return null;
        }
        $handles = $this->litemageCache->decodeEsiHandles($handle);

        $this->_view->loadLayout($handles, true, true, false);

        $layout = $this->_view->getLayout();

        if ($layout->hasElement($block)) {
            return $layout;
        }
        else
            return null;
    }
}
