<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Warmup;

use Litespeed\Litemage\Model\Warmup\CustomerGroupContextSigner;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Login implements HttpGetActionInterface
{
    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CustomerGroupContextSigner
     */
    private $signer;

    public function __construct(
        JsonFactory $jsonFactory,
        RequestInterface $request,
        CustomerRepositoryInterface $customerRepository,
        CustomerSession $customerSession,
        CustomerGroupContextSigner $signer
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->request = $request;
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->signer = $signer;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);

        try {
            $userAgent = (string)$this->request->getHeader('User-Agent');
            if (strpos($userAgent, 'litemage_runner') === false && strpos($userAgent, 'litemage_walker') === false) {
                return $result->setHttpResponseCode(403)->setData(['success' => false, 'message' => 'Invalid crawler user agent.']);
            }

            $customerId = (int)$this->request->getParam('customer_id');
            $timestamp = (int)$this->request->getParam('ts');
            $signature = (string)$this->request->getParam('sig');
            if (!$this->signer->validateLogin($customerId, $timestamp, $signature)) {
                return $result->setHttpResponseCode(403)->setData(['success' => false, 'message' => 'Invalid warmup login signature.']);
            }

            $customer = $this->customerRepository->getById($customerId);
            $this->customerSession->setCustomerDataAsLoggedIn($customer);

            return $result->setData([
                'success' => true,
                'customer_id' => (int)$customer->getId(),
                'group_id' => (int)$customer->getGroupId(),
            ]);
        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
