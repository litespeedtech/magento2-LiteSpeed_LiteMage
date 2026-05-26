<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Store\Model\StoreManagerInterface;

class WarmupCurrency implements ArrayInterface
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    public function toOptionArray()
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $defaultCurrency = strtoupper((string)$store->getDefaultCurrencyCode());
            foreach ((array)$store->getAvailableCurrencyCodes(true) as $code) {
                $code = strtoupper((string)$code);
                if ($code !== '' && $code !== $defaultCurrency) {
                    $codes[$code] = $code;
                }
            }
        }
        ksort($codes);

        $options = [];
        foreach ($codes as $code) {
            $options[] = ['value' => $code, 'label' => $code];
        }
        return $options;
    }
}
