<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

class InventorySalesResolver
{
    private const MODULE_INVENTORY_SALES_API = 'Magento_InventorySalesApi';
    private const MODULE_INVENTORY_SALES = 'Magento_InventorySales';
    private const SALABLE_QTY_INTERFACE = \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class;
    private const STOCK_RESOLVER_INTERFACE = \Magento\InventorySalesApi\Api\StockResolverInterface::class;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManager;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var object|null
     */
    private $salableQty;

    /**
     * @var object|null
     */
    private $stockResolver;

    /**
     * @var bool
     */
    private $initialized = false;

    /**
     * @var string|null
     */
    private $unavailableReason;

    public function __construct(
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
        $this->moduleManager = $moduleManager;
        $this->objectManager = $objectManager;
    }

    public function isAvailable()
    {
        return $this->initServices();
    }

    public function getUnavailableReason()
    {
        $this->initServices();
        return $this->unavailableReason;
    }

    public function getProductSalableQty()
    {
        return $this->isAvailable() ? $this->salableQty : null;
    }

    public function getStockResolver()
    {
        return $this->isAvailable() ? $this->stockResolver : null;
    }

    private function initServices()
    {
        if ($this->initialized) {
            return ($this->unavailableReason === null);
        }

        $this->initialized = true;

        if (!$this->moduleManager->isEnabled(self::MODULE_INVENTORY_SALES_API)) {
            $this->unavailableReason = 'Magento_InventorySalesApi is not enabled or not installed.';
            return false;
        }

        if (!$this->moduleManager->isEnabled(self::MODULE_INVENTORY_SALES)) {
            $this->unavailableReason = 'Magento_InventorySales is not enabled or not installed. '
                . 'The magento/module-inventory-sales-api package installs only API interfaces; '
                . 'install and enable magento/module-inventory-sales for the stock service implementations.';
            return false;
        }

        if (!interface_exists(self::SALABLE_QTY_INTERFACE)
            || !interface_exists(self::STOCK_RESOLVER_INTERFACE)
        ) {
            $this->unavailableReason = 'Magento Inventory Sales API interfaces could not be loaded.';
            return false;
        }

        try {
            $this->salableQty = $this->objectManager->get(self::SALABLE_QTY_INTERFACE);
            $this->stockResolver = $this->objectManager->get(self::STOCK_RESOLVER_INTERFACE);
        } catch (\Throwable $e) {
            $this->unavailableReason = 'Magento Inventory stock services could not be instantiated: '
                . $e->getMessage();
            return false;
        }

        $this->unavailableReason = null;
        return true;
    }
}
