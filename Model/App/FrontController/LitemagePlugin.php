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
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * @var \Magento\Framework\App\PageCache\Version
     */
    protected $version;


    /**
     * Constructor
     *
     * @param \Litespeed\Litemage\Model\Config $config
     * @param \Magento\Framework\App\PageCache\Version $version
     */
    public function __construct(
        \Litespeed\Litemage\Model\CacheControl $litemageCache,
        \Magento\Framework\App\PageCache\Version $version
    ) {
        $this->litemageCache = $litemageCache;
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
        \Closure $proceed,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->litemageCache->debugLog('aroundDispatch0 ' . $request->getMethod() . ' '. $request->getUriString());
        $response = $proceed($request);
        $this->litemageCache->debugLog('aroundDispatch1 FrontController ' . $request->getModuleName() . ':' . $request->getActionName() . ' cacheable=' . (int)$this->litemageCache->isCacheable());
        if ($this->litemageCache->moduleEnabled() && $response instanceof \Magento\Framework\App\Response\Http) {
            $this->version->process();
        }
        return $response;
    }

}
