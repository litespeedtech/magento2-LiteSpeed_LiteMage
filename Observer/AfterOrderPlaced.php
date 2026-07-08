<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class AfterOrderPlaced implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * InventorySalesApi SalesChannelInterface::TYPE_WEBSITE value.
     */
    private const SALES_CHANNEL_TYPE_WEBSITE = 'website';

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /**
     * @var \Litespeed\Litemage\Model\CachePurge
     */
    protected $litemagePurge;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storemanager;

    /**
     * @var \Litespeed\Litemage\Model\InventorySalesResolver
     */
    protected $inventorySalesResolver;

    /**
     * @var object|null
     */
    protected $salableQty;

    /**
     * @var object|null
     */
    protected $stockresolver;

    private $enabled;

    /**
     * @var bool|null
     */
    private $inventoryApiAvailable;


    /**
     * @param \Magento\Store\Model\StoreManagerInterface $storemanager
     * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
     * @param \Litespeed\Litemage\Model\Config $config
     * @param \Litespeed\Litemage\Model\InventorySalesResolver $inventorySalesResolver
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storemanager,
        \Litespeed\Litemage\Model\CachePurge $litemagePurge,
        \Litespeed\Litemage\Model\Config $config,
        \Litespeed\Litemage\Model\InventorySalesResolver $inventorySalesResolver
    )
    {
        $this->storemanager = $storemanager;
        $this->litemagePurge = $litemagePurge;
        $this->config = $config;
        $this->inventorySalesResolver = $inventorySalesResolver;
        $this->inventoryApiAvailable = null;

        $this->enabled = $this->config->moduleEnabled();
        if ($this->enabled) {
            $this->enabled = $this->config->getPurgeProdAfterOrder(); // 0: no; 1: when out of stock; 2: always; 4: include parent prod
        }

    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->enabled) {
            return;
        }

        $order = $observer->getData('order');
        $items = $order->getAllItems();
        $pids = [];

        $only_outofstock = ($this->enabled & 1);
        $include_parent = (($this->enabled & 4) == 4);
        $has_parent = false;

        if ($only_outofstock && !$this->initInventoryApi()) {
            // Magento inventory (MSI) modules are disabled/unavailable; skip the
            // out-of-stock check and always purge instead of failing order placement.
            $this->litemagePurge->debugLog(
                'AfterOrderPlaced: "Only purge when out of stock" is configured but Magento Inventory stock services '
                . 'are disabled or unavailable. ' . $this->inventorySalesResolver->getUnavailableReason()
                . ' Falling back to always-purge for this order. Update the Purge Products after '
                . 'a Sale setting to avoid this fallback.',
                true
            );
            $only_outofstock = false;
        }

        if ($only_outofstock) {
            try {
                $websiteCode = $order->getStore()->getWebsite()->getCode();
                $stockDetails = $this->stockresolver->execute(self::SALES_CHANNEL_TYPE_WEBSITE, $websiteCode);
                $stockId = $stockDetails->getStockId();
                $isPaypalExpress = ($order->getPayment()->getMethod() == 'paypal_express');
            } catch (\Throwable $e) {
                $this->litemagePurge->debugLog(
                    'AfterOrderPlaced stock resolver failed: ' . $e->getMessage()
                    . '; falling back to always-purge for this order.',
                    true
                );
                $only_outofstock = false;
            }
        }

        foreach ($items as $item) {

            $this->litemagePurge->debugLog('AfterOrderPlaced prod type = ' . $item->getProductType() . ' prod id ' . $item->getProductId());
            if ($item->getProductType() !== 'simple') {
                continue;
            }
            if ($only_outofstock) {
                if ($this->isStillSalableAfterOrder($item, $stockId, $isPaypalExpress)) {
                    continue;
                }
            }
            $pids[] = $item->getProductId();
            if ($include_parent) {
                $parent = $item->getParentItem();
                if ($parent && $parent->getProductId()) {
                    $pids[] = $parent->getProductId();
                    $has_parent = true;
                }
            }
        }

        if (!empty($pids)) {
            $reason = 'After Order Placed';
            if ($only_outofstock) {
                $reason .= ' due to out of stock';
            }
            if ($has_parent) {
                $reason .= ', include parent products';
            }
            $this->litemagePurge->purgeProductIds(array_unique($pids), $reason);
        }
    }

    private function initInventoryApi()
    {
        if ($this->inventoryApiAvailable !== null) {
            return $this->inventoryApiAvailable;
        }

        $this->inventoryApiAvailable = false;
        if (!$this->inventorySalesResolver->isAvailable()) {
            $this->litemagePurge->debugLog(
                'AfterOrderPlaced inventory stock services unavailable: '
                . $this->inventorySalesResolver->getUnavailableReason()
            );
            return false;
        }

        $this->salableQty = $this->inventorySalesResolver->getProductSalableQty();
        $this->stockresolver = $this->inventorySalesResolver->getStockResolver();

        $this->inventoryApiAvailable = ($this->salableQty && $this->stockresolver);
        return $this->inventoryApiAvailable;
    }

    private function isStillSalableAfterOrder($item, $stockId, $isPaypalExpress)
    {
        $pid = $item->getProductId();
        try {
            $sku = $item->getSku();
            $options = $item->getProductOptions();
            if (strpos($sku, '-') && !empty($options['options'])) {
                // possible combined sku string due to options, pick original sku by product
                $sku = $item->getProduct()->getSku();
            }
            $stockQty = $this->salableQty->execute($sku, $stockId);
            $this->litemagePurge->debugLog('stockqty pid = ' . $pid . ' qty ' . $stockQty);
            return (($isPaypalExpress && $stockQty > $item->getQtyOrdered())
                    || (!$isPaypalExpress && $stockQty > 0));
        } catch (\Throwable $e) {
            $this->litemagePurge->debugLog('AfterOrderPlaced stock check failed for pid ' . $pid . ': ' . $e->getMessage());
            return false;
        }
    }

}
