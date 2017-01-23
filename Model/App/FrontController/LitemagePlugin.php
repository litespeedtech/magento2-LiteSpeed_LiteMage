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

namespace Litespeed\Litemage\Model\App\FrontController;

use Magento\Framework\App\Response\Http as ResponseHttp;

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
        $response = $proceed($request);
        $this->litemageCache->debugLog('process dispatch action=' . $request->getActionName());
        if ($this->litemageCache->moduleEnabled() && $response instanceof ResponseHttp) {
            $this->version->process();
        }
        return $response;
    }

}
