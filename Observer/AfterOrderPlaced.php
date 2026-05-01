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
     * @var \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface
     */
    protected $salableQty;

    /**
     * @var \Magento\InventorySalesApi\Api\StockResolverInterface
     */
    protected $stockresolver;

    private $enabled;


    /**
     * @param \Magento\Store\Model\StoreManagerInterface $storemanager
     * @param \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface $salableQty
     * @param \Magento\InventorySalesApi\Api\StockResolverInterface $stockResolver
     * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
     * @param \Litespeed\Litemage\Model\Config $config
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storemanager,
        \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface $salableQty,
        \Magento\InventorySalesApi\Api\StockResolverInterface $stockResolver,
        \Litespeed\Litemage\Model\CachePurge $litemagePurge,
        \Litespeed\Litemage\Model\Config $config
    )
    {
        $this->storemanager = $storemanager;
        $this->salableQty = $salableQty;
        $this->stockresolver = $stockResolver;
        $this->litemagePurge = $litemagePurge;
        $this->config = $config;

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

        if ($only_outofstock) {
            $websiteCode = $order->getStore()->getWebsite()->getCode();
            $stockDetails = $this->stockresolver->execute(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
            $stockId = $stockDetails->getStockId();
            $isPaypalExpress = ($order->getPayment()->getMethod() == 'paypal_express');
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
