<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
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
    )
    {
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
            if ($tags = $this->litemageCache->getElementCacheTags($layout,
                                                                  $block)) {
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
        return null;
    }

}
