<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\App\FrontController;

/**
 * Plugin for processing litemage cache
 */
class LitemagePlugin
{

    /**
     * @var \Litespeed\Litemage\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Framework\App\PageCache\Version
     */
    protected $version;

    /**
     * 
     * @param \Litespeed\Litemage\Helper\Data $helper
     * @param \Magento\Framework\App\PageCache\Version $version
     */
    public function __construct(
            \Litespeed\Litemage\Helper\Data $helper,
            \Magento\Framework\App\PageCache\Version $version
    )
    {
        $this->helper = $helper;
        $this->version = $version;
    }

    /**
     * @param \Magento\Framework\App\FrontControllerInterface $subject
     * @param callable $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\Response\Http
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDispatch(
            \Magento\Framework\App\FrontControllerInterface $subject,
            \Closure $proceed, \Magento\Framework\App\RequestInterface $request
    )
    {
        $response = $proceed($request);
        if ($response instanceof \Magento\Framework\App\Response\Http && $this->helper->moduleEnabled()) {
            if ($this->helper->debugEnabled()) {
                $this->helper->debugLog(sprintf('after aroundDispatch %s %s [%s:%s] cacheable=%s',
                                                $request->getMethod(),
                                                $request->getUriString(),
                                                $request->getModuleName(),
                                                $request->getActionName(),
                                                $this->helper->isCacheable() ? '1' : '0'));
            }
            $this->version->process();
        }
        return $response;
    }

}
