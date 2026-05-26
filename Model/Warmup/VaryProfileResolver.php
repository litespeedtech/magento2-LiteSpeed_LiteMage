<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

class VaryProfileResolver
{
    public const PROFILE_GUEST = 'guest';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    public function __construct(ResourceConnection $resource, DateTime $dateTime)
    {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
    }

    public function getDefaultProfile()
    {
        return [
            'code' => self::PROFILE_GUEST,
            'label' => 'Guest',
            'type' => 'guest',
            'config' => [],
        ];
    }

    public function getInitialProfiles()
    {
        return [$this->getDefaultProfile()];
    }

    public function getProfiles($activeOnly = false)
    {
        $profiles = [$this->getDefaultProfile() + [
            'profile_id' => null,
            'is_active' => 1,
            'is_builtin' => 1,
        ]];

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('litemage_warm_profile'))
            ->order('code ASC');
        if ($activeOnly) {
            $select->where('is_active = ?', 1);
        }

        foreach ($connection->fetchAll($select) as $row) {
            $config = [];
            if (!empty($row['config'])) {
                $decoded = json_decode($row['config'], true);
                $config = is_array($decoded) ? $decoded : [];
            }

            $profiles[] = [
                'profile_id' => (int)$row['profile_id'],
                'code' => $row['code'],
                'label' => $row['label'],
                'type' => $row['profile_type'],
                'config' => $config,
                'is_active' => (int)$row['is_active'],
                'is_builtin' => 0,
            ] + $config;
        }

        return $profiles;
    }

    public function getConfiguredProfiles(
        array $profileCodes,
        $limit,
        array $currencyCodes = [],
        array $customerIds = []
    )
    {
        $limit = max(1, (int)$limit);
        $profileCodes = $profileCodes ?: [self::PROFILE_GUEST];
        $profileCodes = array_merge(
            $profileCodes,
            $this->syncBusinessProfiles($currencyCodes, $customerIds)
        );
        $profileCodes = array_values(array_unique(array_map('trim', $profileCodes)));
        if (count($profileCodes) > $limit) {
            throw new LocalizedException(__('Warmup profile expansion exceeds the configured profile limit of %1.', $limit));
        }

        $profiles = [];
        foreach ($profileCodes as $profileCode) {
            if ($profileCode === '') {
                continue;
            }
            $profile = $this->resolve($profileCode);
            $key = $profile['profile_id'] ?? $profile['code'];
            $profiles[$key] = $profile;
        }

        return $profiles ?: [$this->getDefaultProfile()];
    }

    public function resolve($profile = null)
    {
        if ($profile === null || $profile === '' || $profile === self::PROFILE_GUEST) {
            return $this->getDefaultProfile();
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('litemage_warm_profile'))
            ->where('is_active = ?', 1)
            ->limit(1);
        if (ctype_digit((string)$profile)) {
            $select->where('profile_id = ?', (int)$profile);
        } else {
            $select->where('code = ?', (string)$profile);
        }

        $row = $connection->fetchRow($select);
        if (!$row) {
            throw new LocalizedException(__('Warmup profile "%1" was not found or is inactive.', $profile));
        }

        $config = [];
        if (!empty($row['config'])) {
            $decoded = json_decode($row['config'], true);
            $config = is_array($decoded) ? $decoded : [];
        }

        return [
            'profile_id' => (int)$row['profile_id'],
            'code' => $row['code'],
            'label' => $row['label'],
            'type' => $row['profile_type'],
            'config' => $config,
        ] + $config;
    }

    public function upsert($code, $label, $type, array $config, $isActive = true)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('litemage_warm_profile');
        $now = $this->dateTime->gmtDate();
        $row = [
            'code' => substr(trim((string)$code), 0, 64),
            'label' => substr(trim((string)$label), 0, 255),
            'profile_type' => substr(trim((string)$type), 0, 32),
            'config' => json_encode($config),
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($row['code'] === '' || $row['label'] === '' || $row['profile_type'] === '') {
            throw new LocalizedException(__('Profile code, label, and type are required.'));
        }

        $connection->insertOnDuplicate(
            $table,
            $row + ['created_at' => $now],
            ['label', 'profile_type', 'config', 'is_active', 'updated_at']
        );

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($table, 'profile_id')
                ->where('code = ?', $row['code'])
        );
    }

    public function syncBusinessProfiles(array $currencyCodes = [], array $customerIds = [])
    {
        $codes = [];
        $currencyProfiles = [];
        $customerProfiles = [];
        foreach ($currencyCodes as $currencyCode) {
            $currencyCode = strtoupper(trim((string)$currencyCode));
            if ($currencyCode === '' || !preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                continue;
            }

            $code = 'currency_' . strtolower($currencyCode);
            $this->upsert($code, 'Currency ' . $currencyCode, 'currency', [
                'currency' => $currencyCode,
                'managed_by_config' => true,
            ]);
            $codes[] = $code;
            $currencyProfiles[] = [
                'code' => $code,
                'currency' => $currencyCode,
            ];
        }

        foreach ($customerIds as $customerId) {
            $customerId = (int)$customerId;
            if ($customerId <= 0) {
                continue;
            }

            $customerGroup = $this->getCustomerGroupInfo($customerId);
            $code = 'customer_' . $customerId;
            $this->upsert($code, $this->formatCustomerGroupLabel($customerGroup, $customerId), 'customer', [
                'customer_id' => $customerId,
                'customer_session' => true,
                'customer_group_logged_in' => true,
                'managed_by_config' => true,
            ] + $customerGroup);
            $codes[] = $code;
            $customerProfiles[] = [
                'code' => $code,
                'customer_id' => $customerId,
                'customer_group_id' => $customerGroup['customer_group_id'] ?? null,
                'customer_group_code' => $customerGroup['customer_group_code'] ?? '',
            ];
        }

        foreach ($customerProfiles as $customerProfile) {
            foreach ($currencyProfiles as $currencyProfile) {
                $code = sprintf(
                    'customer_%d_currency_%s',
                    (int)$customerProfile['customer_id'],
                    strtolower($currencyProfile['currency'])
                );
                $this->upsert(
                    $code,
                    $this->formatCustomerGroupLabel($customerProfile, (int)$customerProfile['customer_id'])
                        . ' + Currency ' . $currencyProfile['currency'],
                    'customer_currency',
                    [
                        'customer_id' => (int)$customerProfile['customer_id'],
                        'customer_session' => true,
                        'customer_group_logged_in' => true,
                        'currency' => $currencyProfile['currency'],
                        'managed_by_config' => true,
                    ] + array_filter([
                        'customer_group_id' => $customerProfile['customer_group_id'] ?? null,
                        'customer_group_code' => $customerProfile['customer_group_code'] ?? '',
                    ], function ($value) {
                        return $value !== null && $value !== '';
                    })
                );
                $codes[] = $code;
            }
        }

        $this->deactivateUnselectedBusinessProfiles($codes);
        return $codes;
    }

    private function deactivateUnselectedBusinessProfiles(array $selectedCodes)
    {
        $connection = $this->resource->getConnection();
        $conditions = [
            $connection->quoteInto('profile_type IN (?)', ['currency', 'customer', 'customer_currency']),
            '(code LIKE ' . $connection->quote('currency_%') . ' OR code LIKE ' . $connection->quote('customer_%') . ')',
        ];
        if ($selectedCodes) {
            $conditions[] = $connection->quoteInto('code NOT IN (?)', $selectedCodes);
        }

        $connection->update(
            $this->resource->getTableName('litemage_warm_profile'),
            ['is_active' => 0, 'updated_at' => $this->dateTime->gmtDate()],
            $conditions
        );
    }

    private function getCustomerGroupInfo($customerId)
    {
        $connection = $this->resource->getConnection();
        $customerTable = $this->resource->getTableName('customer_entity');
        $groupTable = $this->resource->getTableName('customer_group');
        if (!$connection->isTableExists($customerTable) || !$connection->isTableExists($groupTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['customer' => $customerTable], [])
            ->joinLeft(
                ['customer_group' => $groupTable],
                'customer_group.customer_group_id = customer.group_id',
                [
                    'customer_group_id' => 'customer_group.customer_group_id',
                    'customer_group_code' => 'customer_group.customer_group_code',
                ]
            )
            ->where('customer.entity_id = ?', (int)$customerId)
            ->limit(1);
        $row = $connection->fetchRow($select);
        if (!$row) {
            return [];
        }

        $result = [];
        if (isset($row['customer_group_id']) && $row['customer_group_id'] !== null) {
            $result['customer_group_id'] = (int)$row['customer_group_id'];
        }
        if (!empty($row['customer_group_code'])) {
            $result['customer_group_code'] = (string)$row['customer_group_code'];
        }

        return $result;
    }

    private function formatCustomerGroupLabel(array $customerGroup, $customerId)
    {
        $groupCode = trim((string)($customerGroup['customer_group_code'] ?? ''));
        if ($groupCode !== '') {
            return 'Customer Group ' . $groupCode;
        }

        if (isset($customerGroup['customer_group_id'])) {
            return 'Customer Group #' . (int)$customerGroup['customer_group_id'];
        }

        return 'Customer Group from Customer #' . (int)$customerId;
    }
}
