<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Backend;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\StoreWarmupPolicyConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class StoreWarmupPolicy extends Value
{
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            $this->setValue(json_encode(StoreWarmupPolicyConfig::normalizePolicy([])));
            return parent::beforeSave();
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new LocalizedException(__('LiteMage Store Warmup Policy must be valid JSON.'));
        }

        $this->validatePolicy($decoded);
        $json = json_encode(StoreWarmupPolicyConfig::normalizePolicy($decoded));
        if ($json === false) {
            throw new LocalizedException(__('LiteMage Store Warmup Policy could not be saved.'));
        }

        $this->setValue($json);
        return parent::beforeSave();
    }

    private function validatePolicy(array $policy)
    {
        if (!isset($policy['stores'])) {
            return;
        }
        if (!is_array($policy['stores'])) {
            throw new LocalizedException(__('LiteMage Store Warmup Policy stores value must be an object.'));
        }

        $validStoreIds = $this->getValidStoreIds();
        foreach ($policy['stores'] as $storeId => $storePolicy) {
            if (!ctype_digit((string)$storeId) || (int)$storeId <= 0) {
                throw new LocalizedException(__('LiteMage Store Warmup Policy contains an invalid store ID: %1.', $storeId));
            }
            if (!isset($validStoreIds[(int)$storeId])) {
                throw new LocalizedException(__('LiteMage Store Warmup Policy references unknown store ID %1.', $storeId));
            }
            if (!is_array($storePolicy)) {
                throw new LocalizedException(__('LiteMage Store Warmup Policy row for store %1 must be an object.', $storeId));
            }
            $this->validateStorePolicy((int)$storeId, $storePolicy);
        }
    }

    private function validateStorePolicy($storeId, array $storePolicy)
    {
        if (array_key_exists('priority_offset', $storePolicy)) {
            $offset = $storePolicy['priority_offset'];
            if ($offset === '' || filter_var($offset, FILTER_VALIDATE_INT) === false) {
                throw new LocalizedException(__('Store %1 warmup priority offset must be a whole number.', $storeId));
            }
            $offset = (int)$offset;
            if ($offset < 0 || $offset > Config::WARMUP_PRIORITY_MAX) {
                throw new LocalizedException(__(
                    'Store %1 warmup priority offset must be from 0 to %2.',
                    $storeId,
                    Config::WARMUP_PRIORITY_MAX
                ));
            }
        }

        $variantPolicy = (string)($storePolicy['variant_policy'] ?? StoreWarmupPolicyConfig::VARIANT_POLICY_GLOBAL);
        if (!in_array($variantPolicy, [
            StoreWarmupPolicyConfig::VARIANT_POLICY_GLOBAL,
            StoreWarmupPolicyConfig::VARIANT_POLICY_GUEST_ONLY,
            StoreWarmupPolicyConfig::VARIANT_POLICY_CUSTOM,
        ], true)) {
            throw new LocalizedException(__('Store %1 has invalid warmup variant policy "%2".', $storeId, $variantPolicy));
        }

        if (isset($storePolicy['variants']) && !is_array($storePolicy['variants'])) {
            throw new LocalizedException(__('Store %1 warmup variants must be an object.', $storeId));
        }
        foreach (($storePolicy['variants'] ?? []) as $variantKey => $variant) {
            if (trim((string)$variantKey) === '' || !is_array($variant)) {
                throw new LocalizedException(__('Store %1 has an invalid warmup variant row.', $storeId));
            }
        }
    }

    private function getValidStoreIds()
    {
        $storeManager = ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $storeIds = [];
        foreach ($storeManager->getStores(false) as $store) {
            $storeIds[(int)$store->getId()] = true;
        }

        return $storeIds;
    }
}
