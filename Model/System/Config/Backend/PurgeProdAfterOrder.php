<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;

class PurgeProdAfterOrder extends Value
{
    /**
     * Bit flag for "Only purge the product when out of stock", see Observer\AfterOrderPlaced
     */
    private const ONLY_OUTOFSTOCK = 1;

    /**
     * @var Manager
     */
    private $moduleManager;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        Manager $moduleManager,
        ObjectManagerInterface $objectManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->moduleManager = $moduleManager;
        $this->objectManager = $objectManager;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = (int) $this->getValue();
        if (($value & self::ONLY_OUTOFSTOCK) && !$this->inventorySalesAvailable()) {
            throw new LocalizedException(
                __('"Only purge the product when out of stock" requires Magento Inventory (MSI) stock services, which are disabled or unavailable on this store. Choose "Always purge the product" or "No" instead, or enable Magento Inventory.')
            );
        }

        return parent::beforeSave();
    }

    private function inventorySalesAvailable()
    {
        if (!$this->moduleManager->isEnabled('Magento_InventorySalesApi')) {
            return false;
        }

        if (!interface_exists(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class)
            || !interface_exists(\Magento\InventorySalesApi\Api\StockResolverInterface::class)
        ) {
            return false;
        }

        try {
            $this->objectManager->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class);
            $this->objectManager->get(\Magento\InventorySalesApi\Api\StockResolverInterface::class);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}
