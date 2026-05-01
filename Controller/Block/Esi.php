<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Block;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class Esi implements HttpGetActionInterface
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
     * @var \Litespeed\Litemage\Model\EsiRequestAuth
     */
    protected $esiRequestAuth;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ViewInterface
     */
    protected $view;

    /**
     * @var \Magento\Framework\Controller\Result\RawFactory
     */
    protected $resultRawFactory;

    /**
     * @param \Magento\Framework\Translate\InlineInterface $translateInline
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     * @param \Litespeed\Litemage\Model\EsiRequestAuth $esiRequestAuth
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Framework\App\ViewInterface $view
     * @param \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
     */
    public function __construct(
            \Magento\Framework\Translate\InlineInterface $translateInline,
            \Litespeed\Litemage\Model\CacheControl $litemageCache,
            \Litespeed\Litemage\Model\EsiRequestAuth $esiRequestAuth,
            \Magento\Framework\App\Request\Http $request,
            \Magento\Framework\App\ViewInterface $view,
            \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
    )
    {
        $this->translateInline = $translateInline;
        $this->litemageCache = $litemageCache;
        $this->esiRequestAuth = $esiRequestAuth;
        $this->request = $request;
        $this->view = $view;
        $this->resultRawFactory = $resultRawFactory;
    }

    /**
     * Returns block content as part of an ESI request.
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute()
    {
        try {
            $request = $this->getEsiRequest();
        } catch (LocalizedException $e) {
            return $this->errorExit($e->getMessage(), 403);
        }

        $block_name = $request->getParam('b');
        $handle = $request->getParam('h');

        if (!$this->esiRequestAuth->validateBlockName($block_name)
            || !$this->esiRequestAuth->validateEncodedHandles($handle)
        ) {
            return $this->errorExit('Invalid ESI request', 400);
        }

        $authError = $this->esiRequestAuth->validateParams([
            'b' => $block_name,
            'h' => $handle,
            'sig' => $request->getParam('sig'),
        ]);
        if ($authError !== null) {
            return $this->errorExit($authError, 403);
        }

        if ($handle && $block_name) {
            return $this->sendBlockContent($handle, $block_name);
        }

        return $this->errorExit('Invalid ESI request', 400);
    }
    
    protected function sendBlockContent($handle, $block_name)
    {
        $handles = $this->litemageCache->decodeEsiHandles($handle);

        $this->view->loadLayout($handles, true, true, false);

        $layout = $this->view->getLayout();

        if (!$layout->hasElement($block_name)) {
            return $this->errorExit('Invalid ESI block', 404);
        }
        
        $block = $layout->getBlock($block_name);
        if (!$block instanceof \Magento\Framework\View\Element\AbstractBlock) {
            return $this->errorExit('Invalid ESI block', 404);
        }

        $blockTtl = $block->getTtl();
        $this->litemageCache->setCacheable($blockTtl, "Render ESI block $block_name $blockTtl");
        
        $html = $layout->renderElement($block_name);
        if ($tags = $this->litemageCache->getElementCacheTags($layout, $block_name)) {
            $this->litemageCache->setCacheTags($tags);
        }

        $this->translateInline->processResponseBody($html);
        return $this->rawResult($html);
    }

    protected function errorExit($errorMesg, $statusCode)
    {
        return $this->rawResult($errorMesg, $statusCode);
    }

    protected function getEsiRequest()
    {
        $request = $this->request;

        $origEsiUrl = (string)$request->getRequestUri();
        // for lsws
        $refererUrl = $request->getServer('ESI_REFERER');
        if (!$refererUrl) {
            // lslb
            $refererUrl = $request->getServer('HTTP_ESI_REFERER');
        }

        if ($refererUrl) {
            /** may set original host url later if needed
            $_SERVER['REQUEST_URI'] = $refererUrl ;
            $request->setRequestUri($refererUrl) ;
            $request->setPathInfo() ; */
        } else {
            throw new LocalizedException(
                    new Phrase('Illegal ESI entrance "%1"', [$origEsiUrl]));
        }

        $this->litemageCache->setEsiRequest();
        return $request;
    }

    /**
     * @param string $content
     * @param int $statusCode
     * @return \Magento\Framework\Controller\Result\Raw
     */
    private function rawResult($content, $statusCode = 200)
    {
        $result = $this->resultRawFactory->create();
        $result->setHttpResponseCode($statusCode);
        return $result->setContents($content);
    }
    
}
