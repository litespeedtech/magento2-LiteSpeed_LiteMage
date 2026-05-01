<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Shell;

use Magento\Framework\App\Action\HttpGetActionInterface;

class Purge implements HttpGetActionInterface
{

    /**
     * @var \Litespeed\Litemage\Model\CachePurge
     */
    protected $litemagePurge;

    /**
     * @var \Litespeed\Litemage\Helper\Data
     */
    protected $helper;

    /**
     * @var \Litespeed\Litemage\Model\ShellPurgeAuth
     */
    protected $shellPurgeAuth;

    /**
     * purged tags
     * @var array 
     */
    private $_tags;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Framework\Controller\Result\RawFactory
     */
    protected $resultRawFactory;

    /**
     * 
     * @param \Litespeed\Litemage\Helper\Data $helper
     * @param \Litespeed\Litemage\Model\ShellPurgeAuth $shellPurgeAuth
     * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
     */
    public function __construct(
            \Litespeed\Litemage\Helper\Data $helper,
            \Litespeed\Litemage\Model\ShellPurgeAuth $shellPurgeAuth,
            \Litespeed\Litemage\Model\CachePurge $litemagePurge,
            \Magento\Framework\App\Request\Http $request,
            \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
    )
    {
        $this->litemagePurge = $litemagePurge;
        $this->helper = $helper;
        $this->shellPurgeAuth = $shellPurgeAuth;
        $this->request = $request;
        $this->resultRawFactory = $resultRawFactory;
    }

    /**
     * Handles signed LiteMage shell purge requests.
     *
     * @return \Magento\Framework\Controller\Result\Raw
     */
    public function execute()
    {
        if ($err = $this->_validateReq()) {
            return $this->_errorExit($err[0], $err[1]);
        }

        $this->litemagePurge->addPurgeTags($this->_tags, 'ShellPurgeController');
        return $this->rawResult("LiteMage purge request accepted\n");
    }

    private function _validateReq()
    {
        if (!$this->helper->moduleEnabled()) {
            return ['Abort: LiteMage is not enabled', 503];
        }

        $req = $this->request;
        $authError = $this->shellPurgeAuth->validateParams([
            'all' => $req->getParam('all'),
            'tags' => $req->getParam('tags'),
            'ts' => $req->getParam('ts'),
            'nonce' => $req->getParam('nonce'),
            'signature' => $req->getParam('signature'),
        ]);
        if ($authError !== null) {
            return [$authError, 403];
        }

        $this->_tags = [];

        if ($req->getParam('all')) {
            $this->_tags[] = '*';
        } elseif ($t = $req->getParam('tags')) {
            $this->_tags = array_values(array_filter(
                array_map('trim', explode(',', $t)),
                'strlen'
            ));
        }

        if (empty($this->_tags)) {
            return ['Invalid url', 400];
        }

        return null;
    }

    private function _errorExit($errorMesg, $statusCode)
    {
        $this->helper->debugLog('litemage/shell/purge ErrorExit: ' . $errorMesg);
        return $this->rawResult($errorMesg, $statusCode);
    }

    /**
     * @param string $content
     * @param int $statusCode
     * @return \Magento\Framework\Controller\Result\Raw
     */
    private function rawResult($content, $statusCode = 200)
    {
        $result = $this->resultRawFactory->create();
        $result->setHttpResponseCode($statusCode);
        return $result->setContents($content);
    }

}
