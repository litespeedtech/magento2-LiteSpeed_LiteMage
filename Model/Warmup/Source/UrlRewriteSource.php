<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup\Source;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class UrlRewriteSource
{
    private const DEFAULT_ENTITY_TYPES = [
        'product',
        'category',
        'cms-page',
    ];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $config;

    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        Config $config
    ) {
        $this->resource = $resource;
        $this->storeManager = $storeManager;
        $this->config = $config;
    }

    public function collect(array $storeIds = [])
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('url_rewrite');
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        $storeCount = $storeIds ? count($storeIds) : count($this->storeManager->getStores(false));
        $limit = $this->config->getWarmupQueueLimitPerStore() * max(1, $storeCount);
        $entityTypes = $this->getEntityTypes();
        $select = $connection->select()
            ->from($table, ['request_path', 'store_id', 'entity_type', 'entity_id'])
            ->where('redirect_type = ?', 0)
            ->where('entity_type IN (?)', $entityTypes)
            ->where('request_path IS NOT NULL')
            ->order(['store_id ASC', 'entity_type ASC', 'url_rewrite_id ASC'])
            ->limit($limit);

        if ($storeIds) {
            $select->where('store_id IN (?)', $storeIds);
        }
        $this->applyEntityEnabledFilters($select);

        $items = [];
        foreach ($connection->fetchAll($select) as $row) {
            $storeId = (int)$row['store_id'];
            $items[] = [
                'url' => $this->buildStoreUrl($storeId, $row['request_path']),
                'store_id' => $storeId,
                'page_type' => $row['entity_type'],
                'entity_type' => $row['entity_type'],
                'entity_id' => (int)$row['entity_id'],
                'priority' => $this->getPriority($row['entity_type']),
            ];
        }
        return $items;
    }

    private function buildStoreUrl($storeId, $path)
    {
        $store = $this->storeManager->getStore($storeId);
        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/') . '/' . ltrim($path, '/');
    }

    private function getPriority($entityType)
    {
        switch ($entityType) {
            case 'cms-page':
                return 80;
            case 'category':
                return 60;
            case 'product':
                return 40;
            default:
                return 0;
        }
    }

    private function getEntityTypes()
    {
        $allowed = array_fill_keys(self::DEFAULT_ENTITY_TYPES, true);
        $types = array_filter(array_map('trim', $this->config->getWarmupUrlRewriteEntityTypes()));
        $types = array_values(array_intersect($types, array_keys($allowed)));
        return $types ?: self::DEFAULT_ENTITY_TYPES;
    }

    private function applyEntityEnabledFilters($select)
    {
        $connection = $this->resource->getConnection();
        $productStatusAttributeId = $this->getAttributeId('catalog_product', 'status');
        $categoryActiveAttributeId = $this->getAttributeId('catalog_category', 'is_active');
        $productStatusTable = $this->resource->getTableName('catalog_product_entity_int');
        $categoryActiveTable = $this->resource->getTableName('catalog_category_entity_int');
        $cmsPageTable = $this->resource->getTableName('cms_page');

        $conditions = [];
        if ($productStatusAttributeId) {
            $conditions[] = sprintf(
                "(entity_type = 'product' AND EXISTS (%s))",
                $connection->select()
                    ->from(['product_status' => $productStatusTable], new \Zend_Db_Expr('1'))
                    ->where('product_status.attribute_id = ?', $productStatusAttributeId)
                    ->where('product_status.entity_id = url_rewrite.entity_id')
                    ->where('product_status.store_id IN (0, url_rewrite.store_id)')
                    ->where('product_status.value = ?', 1)
                    ->limit(1)
            );
        }
        if ($categoryActiveAttributeId) {
            $conditions[] = sprintf(
                "(entity_type = 'category' AND EXISTS (%s))",
                $connection->select()
                    ->from(['category_active' => $categoryActiveTable], new \Zend_Db_Expr('1'))
                    ->where('category_active.attribute_id = ?', $categoryActiveAttributeId)
                    ->where('category_active.entity_id = url_rewrite.entity_id')
                    ->where('category_active.store_id IN (0, url_rewrite.store_id)')
                    ->where('category_active.value = ?', 1)
                    ->limit(1)
            );
        }
        $conditions[] = sprintf(
            "(entity_type = 'cms-page' AND EXISTS (%s))",
            $connection->select()
                ->from(['cms_page' => $cmsPageTable], new \Zend_Db_Expr('1'))
                ->where('cms_page.page_id = url_rewrite.entity_id')
                ->where('cms_page.is_active = ?', 1)
                ->limit(1)
        );

        $select->where(implode(' OR ', $conditions));
    }

    private function getAttributeId($entityTypeCode, $attributeCode)
    {
        $connection = $this->resource->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from(['attribute' => $this->resource->getTableName('eav_attribute')], 'attribute_id')
                ->join(
                    ['entity_type' => $this->resource->getTableName('eav_entity_type')],
                    'entity_type.entity_type_id = attribute.entity_type_id',
                    []
                )
                ->where('entity_type.entity_type_code = ?', $entityTypeCode)
                ->where('attribute.attribute_code = ?', $attributeCode)
                ->limit(1)
        );
    }
}
