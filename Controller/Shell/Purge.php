<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
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
