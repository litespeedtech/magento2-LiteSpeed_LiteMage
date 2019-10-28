<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Controller\Result;

/**
 * Plugin for processing LiteMage cache
 */
class LitemagePlugin
{

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\App\PageCache\Version
     */
    protected $version;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * 
     * @param \Litespeed\Litemage\Model\Config $config
     * @param \Magento\Framework\App\PageCache\Version $version
     * @param \Magento\Framework\Registry $registry
     */
    public function __construct(
        \Litespeed\Litemage\Model\Config $config,
        \Magento\Framework\App\PageCache\Version $version,
        \Magento\Framework\Registry $registry
    )
    {
        $this->config = $config;
        $this->version = $version;
        $this->registry = $registry;
    }

    /**
     * @param \Magento\Framework\Controller\ResultInterface $subject
     * @param callable $proceed
     * @param \Magento\Framework\App\Response\Http $response
     * @return \Magento\Framework\Controller\ResultInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRenderResult(
        \Magento\Framework\Controller\ResultInterface $subject,
        \Closure $proceed,
        \Magento\Framework\App\Response\Http $response
    )
    {
        $result = $proceed($response);
        $usePlugin = $this->registry->registry('use_page_cache_plugin');
        if ($usePlugin && $this->config->moduleEnabled()) {
            $this->version->process();
        }
        return $result;
    }

}
