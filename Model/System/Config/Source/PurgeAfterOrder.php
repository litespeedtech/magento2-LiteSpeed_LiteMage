<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

/**
 * Class CustomVary
 *
 */
class PurgeAfterOrder implements \Magento\Framework\Option\ArrayInterface
{

    /**
     * Return list of debug options
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('No')],
            ['value' => 1, 'label' => __('Only purge the product when out of stock')],
            ['value' => 2, 'label' => __('Always purge the product')],
        ];
    }

}
