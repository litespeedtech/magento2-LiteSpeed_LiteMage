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
 * @copyright  Copyright (c) 2016-2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Shell;

class Purge extends \Magento\Framework\App\Action\Action
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;
	protected $httpHeader;


    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Translate\InlineInterface $translateInline
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
			\Magento\Framework\HTTP\Header $httpHeader,
            \Litespeed\Litemage\Model\CacheControl $litemageCache
    ) {
        parent::__construct($context);
		$this->httpHeader = $httpHeader;
        $this->litemageCache = $litemageCache;
    }

    /**
     * Returns block content as part of ESI request from Varnish
     *
     * @return void
     */
    public function execute()
    {
        if (!$this->litemageCache->moduleEnabled()) {
			return $this->_errorExit('Abort: LiteMage is not enabled');
		}
		if ( $this->httpHeader->getHttpUserAgent() !== 'litemage_walker') {
			return $this->_errorExit('Access denied');
		}
		
		$tags = [];
		$req = $this->getRequest();
		if ($req->getParam('all')) {
			$tags[] = '*';
		}
		elseif ($t = $req->getParam('tags')) {
			$tags = explode(',', $t);
		}
		
		if (empty($tags)) {
			$this->_errorExit('Invalid url');
		}
		else {
			$this->litemageCache->addPurgeTags($tags, 'ShellPurgeController');
			$this->getResponse()->setBody('purged tags ' . implode(',', $tags));
		}
    }
	
    protected function _errorExit($errorMesg)
    {
        $resp = $this->getResponse() ;
        $resp->setHttpResponseCode(500) ;
        $resp->setBody($errorMesg) ;
		$this->litemageCache->debugLog('litemage/shell/purge ErrorExit: ' . $errorMesg) ;
    }	
}
