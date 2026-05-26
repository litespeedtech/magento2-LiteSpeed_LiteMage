<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

class QueueExpansionEstimator
{
    public function estimate($urlCount, $profileCount, $storeCount, $limitPerStore = 0)
    {
        $urlCount = max(0, (int)$urlCount);
        $profileCount = max(1, (int)$profileCount);
        $storeCount = max(1, (int)$storeCount);
        $limitPerStore = max(0, (int)$limitPerStore);
        $total = $urlCount * $profileCount * $storeCount;
        $limit = $limitPerStore ? $limitPerStore * $storeCount : 0;

        return [
            'url_count' => $urlCount,
            'profile_count' => $profileCount,
            'store_count' => $storeCount,
            'estimated_total' => $total,
            'limit' => $limit,
            'over_limit' => ($limit > 0 && $total > $limit),
        ];
    }
}
