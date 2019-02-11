<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
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
    public function __construct(\Litespeed\Litemage\Model\CacheControl $litemageCache)
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
            $msg = "Response HttpPlugin aroundSetPublicHeaders1 ttl=$ttl";
            $this->litemageCache->setCacheable($ttl, $msg);
        }
    }

    //public function renderResult(ResponseInterface $response);
}
