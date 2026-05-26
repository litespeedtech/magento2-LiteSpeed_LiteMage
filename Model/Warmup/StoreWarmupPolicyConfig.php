<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreWarmupPolicyConfig
{
    public const XML_PATH = 'litemage/warmup/store_policy';
    public const VARIANT_POLICY_GLOBAL = 'global';
    public const VARIANT_POLICY_GUEST_ONLY = 'guest_only';
    public const VARIANT_POLICY_CUSTOM = 'custom';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var array|null
     */
    private $policy;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function getPolicy()
    {
        if ($this->policy !== null) {
            return $this->policy;
        }

        $decoded = json_decode((string)$this->scopeConfig->getValue(self::XML_PATH), true);
        $this->policy = self::normalizePolicy(is_array($decoded) ? $decoded : []);
        return $this->policy;
    }

    public function isStoreEnabled($storeId)
    {
        $storeId = (int)$storeId;
        $storePolicy = $this->getPolicy()['stores'][$storeId] ?? [];
        return !array_key_exists('enabled', $storePolicy) || (bool)$storePolicy['enabled'];
    }

    public function getPriorityOffset($storeId)
    {
        $storePolicy = $this->getPolicy()['stores'][(int)$storeId] ?? [];
        return self::normalizePriorityOffset($storePolicy['priority_offset'] ?? 0);
    }

    public function isProfileAllowedForStore(array $profile, $storeId)
    {
        $storeId = (int)$storeId;
        if (!$this->isStoreEnabled($storeId)) {
            return false;
        }

        $storePolicy = $this->getPolicy()['stores'][$storeId] ?? [];
        $variantPolicy = self::normalizeVariantPolicy($storePolicy['variant_policy'] ?? self::VARIANT_POLICY_GLOBAL);
        if ($variantPolicy === self::VARIANT_POLICY_GLOBAL) {
            return true;
        }

        $variantKey = $this->getProfileVariantKey($profile);
        if ($variantKey === QueueVariantConfig::PROFILE_GUEST) {
            return true;
        }
        if ($variantPolicy === self::VARIANT_POLICY_GUEST_ONLY) {
            return false;
        }

        $variants = is_array($storePolicy['variants'] ?? null) ? $storePolicy['variants'] : [];
        return !empty($variants[$variantKey]['enabled']);
    }

    public function getAllowedStoreIds(array $requestedStoreIds = [])
    {
        $requested = array_values(array_filter(array_map('intval', $requestedStoreIds)));
        $requestedMap = $requested ? array_fill_keys($requested, true) : [];
        $storeIds = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            if ($requestedMap && !isset($requestedMap[$storeId])) {
                continue;
            }
            if ($this->isStoreEnabled($storeId)) {
                $storeIds[] = $storeId;
            }
        }

        return $storeIds;
    }

    public static function normalizePolicy(array $policy)
    {
        $normalized = [
            'version' => 1,
            'stores' => [],
        ];

        foreach (($policy['stores'] ?? []) as $storeId => $storePolicy) {
            $storeId = (int)$storeId;
            if ($storeId <= 0 || !is_array($storePolicy)) {
                continue;
            }

            $normalized['stores'][$storeId] = [
                'enabled' => !array_key_exists('enabled', $storePolicy) || (bool)$storePolicy['enabled'],
                'priority_offset' => self::normalizePriorityOffset($storePolicy['priority_offset'] ?? 0),
                'variant_policy' => self::normalizeVariantPolicy($storePolicy['variant_policy'] ?? self::VARIANT_POLICY_GLOBAL),
                'variants' => self::normalizeVariants($storePolicy['variants'] ?? []),
            ];
        }

        return $normalized;
    }

    private function getProfileVariantKey(array $profile)
    {
        $code = trim((string)($profile['code'] ?? ''));
        return $code !== '' ? $code : QueueVariantConfig::PROFILE_GUEST;
    }

    private static function normalizePriorityOffset($offset)
    {
        if ($offset === null || $offset === '' || !is_numeric($offset)) {
            return 0;
        }

        $offset = (int)$offset;
        if ($offset < 0) {
            return 0;
        }
        if ($offset > Config::WARMUP_PRIORITY_MAX) {
            return Config::WARMUP_PRIORITY_MAX;
        }

        return $offset;
    }

    private static function normalizeVariantPolicy($variantPolicy)
    {
        $variantPolicy = (string)$variantPolicy;
        if (in_array($variantPolicy, [
            self::VARIANT_POLICY_GLOBAL,
            self::VARIANT_POLICY_GUEST_ONLY,
            self::VARIANT_POLICY_CUSTOM,
        ], true)) {
            return $variantPolicy;
        }

        return self::VARIANT_POLICY_GLOBAL;
    }

    private static function normalizeVariants($variants)
    {
        if (!is_array($variants)) {
            return [];
        }

        $normalized = [];
        foreach ($variants as $variantKey => $variant) {
            $variantKey = trim((string)$variantKey);
            if ($variantKey === '') {
                continue;
            }
            $normalized[$variantKey] = [
                'enabled' => !empty($variant['enabled']),
            ];
        }

        return $normalized;
    }
}
