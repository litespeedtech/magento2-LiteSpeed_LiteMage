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
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;

class QueueVariantConfig
{
    public const XML_PATH = 'litemage/warmup/queue_variant_map';
    public const PROFILE_GUEST = 'guest';

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
    private $map;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ?StoreManagerInterface $storeManager = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
    }

    public function getMap()
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $decoded = json_decode((string)$this->scopeConfig->getValue(self::XML_PATH), true);
        $this->map = self::normalizeMap(is_array($decoded) ? $decoded : []);
        return $this->map;
    }

    public function getQueueConfig($source, $sourceInstanceKey, $defaultPriority)
    {
        $defaultPriority = $this->normalizePriority($defaultPriority, 100);
        $queue = $this->getMap()['queues'][$this->getQueueKey($source, $sourceInstanceKey)] ?? [];

        return [
            'enabled' => !array_key_exists('enabled', $queue) || (bool)$queue['enabled'],
            'priority' => $this->normalizePriority($queue['priority'] ?? $defaultPriority, $defaultPriority),
            'variants' => is_array($queue['variants'] ?? null) ? $queue['variants'] : [],
        ];
    }

    public function getVariantConfig(array $queueConfig, array $profile)
    {
        $variantKey = $this->getProfileVariantKey($profile);
        $defaultOffset = $this->getDefaultVariantOffset($profile);
        $variant = $queueConfig['variants'][$variantKey] ?? [];

        if ($variantKey === self::PROFILE_GUEST) {
            return ['enabled' => true, 'offset' => 0];
        }

        return [
            'enabled' => !array_key_exists('enabled', $variant) || (bool)$variant['enabled'],
            'offset' => $this->normalizePriority($variant['offset'] ?? $defaultOffset, $defaultOffset),
        ];
    }

    public function calculateEffectivePriority($sourcePriority, $urlPriority, $variantOffset, $storeOffset = 0)
    {
        $urlPriority = $urlPriority === null || $urlPriority === '' ? 0 : (int)$urlPriority;
        return min(
            Config::WARMUP_PRIORITY_MAX,
            max(Config::WARMUP_PRIORITY_MIN, (int)$sourcePriority + $urlPriority + (int)$variantOffset + (int)$storeOffset)
        );
    }

    public function getQueueKey($source, $sourceInstanceKey)
    {
        return (string)$source . '|' . sha1((string)$sourceInstanceKey);
    }

    public function getProfileVariantKey(array $profile)
    {
        $code = trim((string)($profile['code'] ?? ''));
        return $code !== '' ? $code : self::PROFILE_GUEST;
    }

    public function getProfileId(array $profile)
    {
        return isset($profile['profile_id']) && $profile['profile_id'] !== null ? (int)$profile['profile_id'] : 0;
    }

    public function isProfileApplicableToStore(array $profile, $storeId)
    {
        $currency = strtoupper(trim((string)($profile['currency'] ?? '')));
        if ($currency === '') {
            return true;
        }

        try {
            $store = $this->storeManager->getStore((int)$storeId);
            $defaultCurrency = strtoupper((string)$store->getDefaultCurrencyCode());
            $available = array_fill_keys(array_map('strtoupper', (array)$store->getAvailableCurrencyCodes(true)), true);
        } catch (\Exception $e) {
            return false;
        }

        return $currency !== $defaultCurrency && isset($available[$currency]);
    }

    public function getDefaultVariantOffset(array $profile)
    {
        $type = (string)($profile['type'] ?? '');
        switch ($type) {
            case 'guest':
                return 0;
            case 'currency':
                return 100;
            case 'customer':
            case 'customer_currency':
                return 200;
            default:
                return 150;
        }
    }

    public static function normalizeMap(array $map)
    {
        $normalized = [
            'version' => 1,
            'queues' => [],
        ];

        foreach (($map['queues'] ?? []) as $queueKey => $queue) {
            if (!is_array($queue) || !is_string($queueKey) || $queueKey === '') {
                continue;
            }

            $queueRow = [
                'enabled' => !array_key_exists('enabled', $queue) || (bool)$queue['enabled'],
                'priority' => self::normalizePriorityValue($queue['priority'] ?? 100, 100),
                'variants' => [],
            ];

            foreach (($queue['variants'] ?? []) as $variantKey => $variant) {
                if (!is_array($variant) || !is_string($variantKey) || $variantKey === '') {
                    continue;
                }
                $queueRow['variants'][$variantKey] = [
                    'enabled' => $variantKey === self::PROFILE_GUEST
                        ? true
                        : (!array_key_exists('enabled', $variant) || (bool)$variant['enabled']),
                    'offset' => $variantKey === self::PROFILE_GUEST
                        ? 0
                        : self::normalizePriorityValue($variant['offset'] ?? 0, 0),
                ];
            }

            $queueRow['variants'][self::PROFILE_GUEST] = ['enabled' => true, 'offset' => 0];
            $normalized['queues'][$queueKey] = $queueRow;
        }

        return $normalized;
    }

    private function normalizePriority($priority, $default)
    {
        return self::normalizePriorityValue($priority, $default);
    }

    private static function normalizePriorityValue($priority, $default)
    {
        if ($priority === null || $priority === '' || !is_numeric($priority)) {
            return (int)$default;
        }

        $priority = (int)$priority;
        if ($priority < Config::WARMUP_PRIORITY_MIN || $priority > Config::WARMUP_PRIORITY_MAX) {
            return (int)$default;
        }

        return $priority;
    }
}
