<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Block;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

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
        $request = $this->getEsiRequest();
        
        $block_name = $request->getParam('b');
        $handle = $request->getParam('h');

        if ($handle && $block_name) {
            $this->sendBlockContent($handle, $block_name);
        }
    }
    
    protected function sendBlockContent($handle, $block_name)
    {
        $handles = $this->litemageCache->decodeEsiHandles($handle);

        $this->_view->loadLayout($handles, true, true, false);

        $layout = $this->_view->getLayout();

        if (!$layout->hasElement($block_name)) {
            return;
        }
        
        $block = $layout->getBlock($block_name);
        $blockTtl = $block->getTtl();
        $this->litemageCache->setCacheable($blockTtl, "Render ESI block $block_name $blockTtl");
        
        $response = $this->getResponse();
        $html = $layout->renderElement($block_name);
        if ($tags = $this->litemageCache->getElementCacheTags($layout, $block_name)) {
            $this->litemageCache->setCacheTags($tags);
        }

        $this->translateInline->processResponseBody($html);
        $response->appendBody($html);
    }

	protected function getEsiRequest()
	{
        $request = $this->getRequest();
        
        $origEsiUrl = $_SERVER['REQUEST_URI'] ;
		// for lsws
		$refererUrl = $request->getServer('ESI_REFERER');
		if (!$refererUrl) {
			//lslb
			$refererUrl = $request->getServer('HTTP_ESI_REFERER');
		}

		if ( $refererUrl ) {
            /** may set original host url later if needed
			$_SERVER['REQUEST_URI'] = $refererUrl ;
			$request->setRequestUri($refererUrl) ;
			$request->setPathInfo() ; */
		} else {
            throw new LocalizedException(
                    new Phrase('Illegal ESI entrace "%1"', [$origEsiUrl]));
        }

        $this->litemageCache->setEsiRequest();
        return $request;
	}    
    
}
