<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Block;

class CustomVary extends \Magento\Framework\App\Action\Action
{

    /** @var  \Magento\Framework\Controller\Result\JsonFactory */
    protected $jsonFactory;

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonFactory
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
            \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
            \Litespeed\Litemage\Model\CacheControl $litemageCache
    )
    {
        parent::__construct($context);
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
        return $result->setData(['success' => true, 'ajaxReload' => $ajaxReload]);
    }

}
