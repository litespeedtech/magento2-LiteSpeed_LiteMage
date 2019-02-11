<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\App\Response;

/**
 * HTTP response plugin for frontend purge.
 */
class HttpPurgePlugin
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
    ) {
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
        if ($this->litemageCache->needPurge()) {
            $this->litemageCache->setPurgeHeaders($subject);
        }
    }

}
