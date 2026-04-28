<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */
namespace Litespeed\Litemage\Controller\Cli;

class Purge extends \Magento\Framework\App\Action\Action
{

    /**
     * @var \Litespeed\Litemage\Model\CachePurge
     */
    protected $litemagePurge;

    /**
     * @var \Magento\Framework\HTTP\Header
     */
    protected $httpHeader;

    /**
     * @var \Litespeed\Litemage\Helper\Data
     */
    protected $helper;

    /**
     * purged tags
     * @var array 
     */
    private $_tags;

    /**
     * 
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\HTTP\Header $httpHeader
     * @param \Litespeed\Litemage\Helper\Data $helper
     * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
     */
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
            \Magento\Framework\HTTP\Header $httpHeader,
            \Litespeed\Litemage\Helper\Data $helper,
            \Litespeed\Litemage\Model\CachePurge $litemagePurge
    )
    {
        parent::__construct($context);
        $this->httpHeader = $httpHeader;
        $this->litemagePurge = $litemagePurge;
        $this->helper = $helper;
    }

    /**
     * Returns block content as part of ESI request from Varnish
     *
     * @return void
     */
    public function execute()
    {
        if ($err = $this->_validateReq()) {
            return $this->_errorExit($err);
        }

        $this->litemagePurge->addPurgeTags($this->_tags, 'ShellPurgeController');
        $this->getResponse()->setBody(sprintf("LiteMage purged tags %s \n",
                                              implode(',', $this->_tags)));
    }

    private function _validateReq()
    {
        if (!$this->helper->moduleEnabled()) {
            return 'Abort: LiteMage is not enabled';
        }
        if ($this->httpHeader->getHttpUserAgent() !== 'litemage_walker') {
            return 'Access denied (User-Agent mismatch): ' . $this->httpHeader->getHttpUserAgent();
        }

        $req = $this->getRequest();
        $secret = $req->getParam('secret');

        if (strlen((string)$secret) != 32) {
            return 'Invalid request: secret length is ' . strlen((string)$secret);
        }
        $file = dirname(dirname(dirname(__FILE__))) . '/Observer/FlushCacheByCli.php';
        $stat_str = filemtime($file) . filesize($file);
        $secret1 = md5($stat_str . gmdate('Y-m-d-H'));
        $secret2 = md5($stat_str . gmdate('Y-m-d-H', time() - 3600));

        if ($secret != $secret1 && $secret != $secret2) {
            return 'Invalid token (Timezone/Stat mismatch)';
        }

        $this->_tags = [];

        if ($req->getParam('all')) {
            $this->_tags[] = '*';
        } elseif ($t = $req->getParam('tags')) {
            $this->_tags = explode(',', $t);
        }

        if (empty($this->_tags)) {
            return 'Invalid url';
        }

        return null;
    }

    private function _errorExit($errorMesg)
    {
        $resp = $this->getResponse();
        $resp->setHttpResponseCode(500);
        $resp->setBody($errorMesg);
        $this->helper->debugLog('litemage/cli/purge ErrorExit: ' . $errorMesg);
    }

}
