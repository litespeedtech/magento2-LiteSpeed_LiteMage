<?php
/**
 * LiteMage2
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
namespace Litespeed\Litemage\Model\Controller\Result;

use Magento\Framework\App\Response\Http as ResponseHttp;

/**
 * Class LitemagePlugin
 *
 * Plugin for processing LiteMage cache
 */
class LitemagePlugin
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $litemageCache;

    /**
     * @var \Magento\Framework\App\PageCache\Version
     */
    protected $version;

    /**
     * @var \Magento\Framework\App\PageCache\Kernel
     */


    /**
     * Constructor
     *
     * @param \Magento\PageCache\Model\Config $config
     * @param \Magento\Framework\App\PageCache\Kernel $kernel
     * @param \Magento\Framework\App\State $state
     * @param \Magento\Framework\Registry $registry
     */
    public function __construct(
        \Litespeed\Litemage\Model\CacheControl $litemageCache,
        \Magento\Framework\App\PageCache\Version $version
    ) {
        $this->litemageCache = $litemageCache;
        $this->version = $version;
    }

    /**
     * @param \Magento\Framework\Controller\ResultInterface $subject
     * @param callable $proceed
     * @param ResponseHttp $response
     * @return \Magento\Framework\Controller\ResultInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRenderResult(
        \Magento\Framework\Controller\ResultInterface $subject,
        \Closure $proceed,
        ResponseHttp $response
    ) {
        $proceed($response);

        if ($this->litemageCache->moduleEnabled()) {
            $this->version->process();
        }
        return $subject;
    }
}
