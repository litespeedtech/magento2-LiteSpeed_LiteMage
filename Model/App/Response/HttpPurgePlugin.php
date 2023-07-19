<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\App\Response;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Response\HttpInterface;

/**
 * HTTP response plugin for frontend purge.
 */
class HttpPurgePlugin
{

    /**
     * @var \Litespeed\Litemage\Model\CachePurge
     */
    protected $litemagePurge;

	/**
	 * @var \Magento\Framework\App\Request\Http
	 */
	protected $request;

    /**
     * Constructor
     *
     * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
     */
    public function __construct(
            \Litespeed\Litemage\Model\CachePurge $litemagePurge,
			\Magento\Framework\App\Request\Http $request
    )
    {
        $this->litemagePurge = $litemagePurge;
		$this->request = $request;
    }

    /**
     * Set proper value of X-LiteSpeed headers
     *
     * @param \Magento\Framework\App\Response\Http $subject
     * @return void
     */
    public function beforeSendResponse(\Magento\Framework\App\Response\Http $subject)
    {
        if ($this->litemagePurge->needPurge()) {
            $this->litemagePurge->setPurgeHeaders($subject);
        }
    }

	// used by webapi
    public function afterDispatch(FrontControllerInterface $controller, HttpInterface $response): HttpInterface
    {
        if ($this->litemagePurge->needPurge()) {
			$this->litemagePurge->debugLog('WebAPI request triggered purge ' . $this->request->getRequestUri());
            $this->litemagePurge->setPurgeHeaders($response);
        }
		return $response;
    }


}
