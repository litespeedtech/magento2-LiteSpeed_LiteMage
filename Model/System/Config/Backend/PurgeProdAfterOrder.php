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
use Magento\Framework\Registry;
use Litespeed\Litemage\Model\InventorySalesResolver;

class PurgeProdAfterOrder extends Value
{
    /**
     * Bit flag for "Only purge the product when out of stock", see Observer\AfterOrderPlaced
     */
    private const ONLY_OUTOFSTOCK = 1;

    /**
     * @var InventorySalesResolver
     */
    private $inventorySalesResolver;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        InventorySalesResolver $inventorySalesResolver,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->inventorySalesResolver = $inventorySalesResolver;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = (int) $this->getValue();
        if (($value & self::ONLY_OUTOFSTOCK) && !$this->inventorySalesResolver->isAvailable()) {
            throw new LocalizedException(
                __(
                    '"Only purge the product when out of stock" requires Magento Inventory (MSI) stock services. %1 Choose "Always purge the product" or "No" instead, or enable the required Magento Inventory modules.',
                    $this->inventorySalesResolver->getUnavailableReason()
                )
            );
        }

        return parent::beforeSave();
    }
}
