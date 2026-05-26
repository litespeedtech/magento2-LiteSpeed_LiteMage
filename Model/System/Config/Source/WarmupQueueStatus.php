<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

use Litespeed\Litemage\Model\Warmup\QueueStatus;

class WarmupQueueStatus implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => QueueStatus::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => QueueStatus::STATUS_RUNNING, 'label' => __('Running')],
            ['value' => QueueStatus::STATUS_WARMED, 'label' => __('Warmed')],
            ['value' => QueueStatus::STATUS_SKIPPED, 'label' => __('Skipped')],
            ['value' => QueueStatus::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => QueueStatus::STATUS_BLACKLISTED, 'label' => __('Blacklisted')],
        ];
    }
}
