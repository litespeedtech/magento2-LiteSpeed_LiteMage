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


namespace Litespeed\Litemage\Model\App\Response;

/**
 * HTTP response plugin for frontend.
 */
class HttpPlugin
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * Constructor
     *
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
    \Litespeed\Litemage\Model\CacheControl $litemageCache
    )
    {
        $this->litemageCache = $litemageCache;
    }

    /**
     * Set proper value of X-LiteSpeed headers
     *
     * @param \Magento\Framework\App\Response\Http $subject
     * @return void
     */
    public function beforeSendResponse(\Magento\Framework\App\Response\Http $subject)
    {
        if ($subject instanceof \Magento\Framework\App\PageCache\NotCacheableInterface) {
            return;
        }
		if ($this->litemageCache->moduleEnabled()) {
            $this->litemageCache->setCacheControlHeaders($subject);
        }
    }

    public function aroundSetPublicHeaders(\Magento\Framework\App\Response\Http $subject, \Closure $proceed, $ttl)
    {
        $proceed($ttl);
        if ($this->litemageCache->moduleEnabled()) {
            $this->litemageCache->setCacheable(true, $ttl);
            $this->litemageCache->debugLog("around setpublicheaders $ttl ");
        }
    }

    //public function renderResult(ResponseInterface $response);
}
