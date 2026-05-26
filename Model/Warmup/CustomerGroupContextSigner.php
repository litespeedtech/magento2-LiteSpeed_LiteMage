<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;

class CustomerGroupContextSigner
{
    public const HEADER_GROUP = 'X-LiteMage-Warmup-Group';
    public const HEADER_AUTH = 'X-LiteMage-Warmup-Auth';
    public const HEADER_TIMESTAMP = 'X-LiteMage-Warmup-Ts';
    public const HEADER_SIGNATURE = 'X-LiteMage-Warmup-Sig';

    private const MAX_CLOCK_SKEW = 300;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    public function __construct(DeploymentConfig $deploymentConfig)
    {
        $this->deploymentConfig = $deploymentConfig;
    }

    public function buildHeaders($groupId, $loggedIn = false)
    {
        $groupId = $this->normalizeGroupId($groupId);
        $loggedIn = $loggedIn ? 1 : 0;
        $timestamp = time();

        return [
            self::HEADER_GROUP => (string)$groupId,
            self::HEADER_AUTH => (string)$loggedIn,
            self::HEADER_TIMESTAMP => (string)$timestamp,
            self::HEADER_SIGNATURE => $this->signGroupContext($groupId, $loggedIn, $timestamp),
        ];
    }

    public function buildLoginParams($customerId)
    {
        $customerId = $this->normalizeCustomerId($customerId);
        $timestamp = time();
        return [
            'customer_id' => (string)$customerId,
            'ts' => (string)$timestamp,
            'sig' => $this->signLogin($customerId, $timestamp),
        ];
    }

    public function validate($groupId, $loggedIn, $timestamp, $signature)
    {
        $groupId = $this->normalizeGroupId($groupId);
        $loggedIn = $loggedIn ? 1 : 0;
        $timestamp = (int)$timestamp;
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_CLOCK_SKEW) {
            return false;
        }

        $expected = $this->signGroupContext($groupId, $loggedIn, $timestamp);
        return hash_equals($expected, (string)$signature);
    }

    public function validateLogin($customerId, $timestamp, $signature)
    {
        $customerId = $this->normalizeCustomerId($customerId);
        $timestamp = (int)$timestamp;
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_CLOCK_SKEW) {
            return false;
        }

        $expected = $this->signLogin($customerId, $timestamp);
        return hash_equals($expected, (string)$signature);
    }

    private function signGroupContext($groupId, $loggedIn, $timestamp)
    {
        return $this->signPayload(['group', (int)$groupId, (int)$loggedIn, (int)$timestamp]);
    }

    private function signLogin($customerId, $timestamp)
    {
        return $this->signPayload(['customer_login', (int)$customerId, (int)$timestamp]);
    }

    private function signPayload(array $parts)
    {
        return hash_hmac('sha256', implode('|', $parts), $this->getSecret());
    }

    private function normalizeGroupId($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId < 0) {
            throw new LocalizedException(__('Warmup customer group ID must be zero or greater.'));
        }
        return $groupId;
    }

    private function normalizeCustomerId($customerId)
    {
        $customerId = (int)$customerId;
        if ($customerId <= 0) {
            throw new LocalizedException(__('Warmup customer ID must be greater than zero.'));
        }
        return $customerId;
    }

    private function getSecret()
    {
        $secret = (string)$this->deploymentConfig->get('crypt/key');
        if ($secret === '') {
            throw new LocalizedException(__('Magento crypt key is required for signed warmup customer-group context.'));
        }
        return $secret;
    }
}
