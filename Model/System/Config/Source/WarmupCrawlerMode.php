<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

use Litespeed\Litemage\Model\Warmup\CrawlerMode;

class WarmupCrawlerMode implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => CrawlerMode::MODE_RUNNER, 'label' => __('litemage_runner')],
            ['value' => CrawlerMode::MODE_WALKER, 'label' => __('litemage_walker')],
        ];
    }
}
