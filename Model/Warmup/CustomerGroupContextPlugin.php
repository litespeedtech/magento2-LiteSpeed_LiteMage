<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\GroupManagement;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;

class CustomerGroupContextPlugin
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var HttpContext
     */
    private $httpContext;

    /**
     * @var CustomerGroupContextSigner
     */
    private $signer;

    public function __construct(
        RequestInterface $request,
        HttpContext $httpContext,
        CustomerGroupContextSigner $signer
    ) {
        $this->request = $request;
        $this->httpContext = $httpContext;
        $this->signer = $signer;
    }

    public function beforeExecute(ActionInterface $subject)
    {
        $userAgent = (string)$this->request->getHeader('User-Agent');
        if (strpos($userAgent, 'litemage_runner') === false && strpos($userAgent, 'litemage_walker') === false) {
            return;
        }

        $groupId = $this->request->getHeader(CustomerGroupContextSigner::HEADER_GROUP);
        $timestamp = $this->request->getHeader(CustomerGroupContextSigner::HEADER_TIMESTAMP);
        $signature = $this->request->getHeader(CustomerGroupContextSigner::HEADER_SIGNATURE);
        if ($groupId === false || $timestamp === false || $signature === false) {
            return;
        }

        $loggedIn = (int)$this->request->getHeader(CustomerGroupContextSigner::HEADER_AUTH);
        if (!$this->signer->validate($groupId, $loggedIn, $timestamp, $signature)) {
            return;
        }

        $this->httpContext->setValue(
            CustomerContext::CONTEXT_GROUP,
            (string)(int)$groupId,
            GroupManagement::NOT_LOGGED_IN_ID
        );
        $this->httpContext->setValue(
            CustomerContext::CONTEXT_AUTH,
            (bool)$loggedIn,
            false
        );
    }
}
