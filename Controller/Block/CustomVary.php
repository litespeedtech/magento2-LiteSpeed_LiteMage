<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Block;

use Magento\Framework\App\Action\HttpGetActionInterface;

class CustomVary implements HttpGetActionInterface
{

    /** @var  \Magento\Framework\Controller\Result\JsonFactory */
    protected $jsonFactory;

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonFactory
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
            \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
            \Litespeed\Litemage\Model\CacheControl $litemageCache
    )
    {
        $this->jsonFactory = $jsonFactory;
        $this->litemageCache = $litemageCache;
    }

    /**
     * Returns ajax response for vary check
     *
     * @return void
     */
    public function execute()
    {
        $ajaxReload = false;
        if ($this->litemageCache->moduleEnabled()) {
            $ajaxReload = $this->litemageCache->checkCacheVary();
        }
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        return $result->setData(['success' => true, 'ajaxReload' => $ajaxReload]);
    }

}
