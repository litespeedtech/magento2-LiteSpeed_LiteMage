<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class EntityUrlResolver
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(ResourceConnection $resource, StoreManagerInterface $storeManager)
    {
        $this->resource = $resource;
        $this->storeManager = $storeManager;
    }

    public function resolve(array $entities)
    {
        if (!$entities) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('url_rewrite');
        $urls = [];
        foreach ($entities as $entityType => $entityIds) {
            $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
            if (!$entityIds) {
                continue;
            }
            $select = $connection->select()
                ->from($table, ['request_path', 'store_id', 'entity_type', 'entity_id'])
                ->where('redirect_type = ?', 0)
                ->where('entity_type = ?', $entityType)
                ->where('entity_id IN (?)', $entityIds);
            foreach ($connection->fetchAll($select) as $row) {
                $urls[] = [
                    'url' => $this->buildStoreUrl((int)$row['store_id'], $row['request_path']),
                    'store_id' => (int)$row['store_id'],
                    'page_type' => $row['entity_type'],
                    'entity_type' => $row['entity_type'],
                    'entity_id' => (int)$row['entity_id'],
                    'priority' => 1000,
                ];
            }
        }
        return $urls;
    }

    private function buildStoreUrl($storeId, $path)
    {
        $store = $this->storeManager->getStore($storeId);
        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/') . '/' . ltrim($path, '/');
    }
}
