<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

class LaneLockRepository
{
    private const TABLE_LOCK = 'litemage_warm_lane_lock';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
    }

    public function acquire($profileId, $mode, $storeId, $owner, $ttlSeconds)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_LOCK);
        if (!$connection->isTableExists($table)) {
            return true;
        }

        $profileId = $this->normalizeProfileId($profileId);
        $storeId = (int)$storeId;
        $mode = (string)$mode;
        $owner = substr((string)$owner, 0, 64);
        $now = $this->dateTime->gmtDate();
        $expiresAt = $this->dateTime->gmtDate('Y-m-d H:i:s', time() + max(60, (int)$ttlSeconds));

        $connection->beginTransaction();
        try {
            $row = $connection->fetchRow(
                $this->forUpdate(
                    $connection->select()
                        ->from($table)
                        ->where('profile_id = ?', $profileId)
                        ->where('mode = ?', $mode)
                        ->where('store_id = ?', $storeId)
                        ->limit(1)
                )
            );

            if ($row) {
                $expires = strtotime((string)$row['expires_at']);
                $ownedByThisProcess = (string)$row['lock_owner'] === $owner;
                if (!$ownedByThisProcess && $expires !== false && $expires > time()) {
                    $connection->commit();
                    return false;
                }

                $connection->update(
                    $table,
                    [
                        'lock_owner' => $owner,
                        'locked_at' => $now,
                        'expires_at' => $expiresAt,
                        'updated_at' => $now,
                    ],
                    ['lock_id = ?' => (int)$row['lock_id']]
                );
                $connection->commit();
                return true;
            }

            $connection->insert(
                $table,
                [
                    'profile_id' => $profileId,
                    'mode' => $mode,
                    'store_id' => $storeId,
                    'lock_owner' => $owner,
                    'locked_at' => $now,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $connection->commit();
            return true;
        } catch (\Exception $e) {
            $connection->rollBack();
            return false;
        }
    }

    public function release($profileId, $mode, $storeId, $owner)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_LOCK);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        return $connection->delete(
            $table,
            [
                'profile_id = ?' => $this->normalizeProfileId($profileId),
                'mode = ?' => (string)$mode,
                'store_id = ?' => (int)$storeId,
                'lock_owner = ?' => substr((string)$owner, 0, 64),
            ]
        );
    }

    public function releaseExpired()
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_LOCK);
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        return $connection->delete($table, ['expires_at < ?' => $this->dateTime->gmtDate()]);
    }

    private function normalizeProfileId($profileId)
    {
        return $profileId === null || $profileId === '' ? 0 : (int)$profileId;
    }

    private function forUpdate($select)
    {
        if (method_exists($select, 'forUpdate')) {
            return $select->forUpdate(true);
        }

        return (string)$select . ' FOR UPDATE';
    }
}
