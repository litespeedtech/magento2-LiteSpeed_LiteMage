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
	protected $salebleqty;

	/**
	 * @var \Magento\InventorySalesApi\Api\StockResolverInterface
	 */
	protected $stockresolver;

    private $enabled;


	/**
	 * 
	 * @param \Magento\Store\Model\StoreManagerInterface $storemanager
	 * @param \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface $salebleqty
	 * @param \Magento\InventorySalesApi\Api\StockResolverInterface $stockresolver
	 * @param \Litespeed\Litemage\Model\CachePurge $litemagePurge
	 * @param \Litespeed\Litemage\Model\Config $config
	 */
    public function __construct(
			\Magento\Store\Model\StoreManagerInterface $storemanager,
			\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface $salebleqty,
			\Magento\InventorySalesApi\Api\StockResolverInterface $stockresolver,
			\Litespeed\Litemage\Model\CachePurge $litemagePurge,
			\Litespeed\Litemage\Model\Config $config
	)
	{
		$this->storemanager = $storemanager;
		$this->salebleqty = $salebleqty;
		$this->stockresolver = $stockresolver;
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

        $websiteCode = $this->storemanager->getWebsite()->getCode();
        $stockDetails = $this->stockresolver->execute(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
        $stockId = $stockDetails->getStockId();

		$order = $observer->getData('order');
		$isPaypalExpress = ($order->getPayment()->getMethod() == 'paypal_express');
        $items = $order->getAllItems();
		$pids = [];

		$only_outofstock = ($this->enabled & 1);
		$include_parent = (($this->enabled & 4) == 4);
		$has_parent = false;

		$parents = [];

        foreach ($items as $item) {

			$this->litemagePurge->debugLog('AfterOrderPlaced prod type = ' . $item->getProductType() . ' prod id ' . $item->getProductId());
			$pid = $item->getProductId();
			if ($item->getProductType() !== 'simple') {
				$parents[] = $pid;
				continue;
			}

			if ($only_outofstock) {
				$stockQty = $this->salebleqty->execute($item->getSku(), $stockId);
				$this->litemagePurge->debugLog('stockqty pid = ' . $pid . '  qty ' . $stockQty);
				if (($isPaypalExpress && $stockQty > $item->getQtyOrdered())
						|| (!$isPaypalExpress && $stockQty > 0)) {
					$parents = []; // reset parent
					continue;
				}
			}
			$pids[] = $pid;
			if ($include_parent && !empty($parents)) {
				$pids = array_merge($pids, $parents);
				$has_parent = true;
			}
			$parents = [];
        }

		if (!empty($pids)) {
			$reason = 'After Order Placed';
			if ($only_outofstock) {
				$reason .= ' due to out of stock';
			}
			if ($has_parent) {
				$reason .= ', include parent products';
			}
			$this->litemagePurge->purgeProductIds(array_unique($pids) , $reason);
		}
    }


}
