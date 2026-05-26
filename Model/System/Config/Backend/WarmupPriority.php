<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Backend;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class WarmupPriority extends Value
{
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            return parent::beforeSave();
        }

        if (!preg_match('/^\d+$/', $value)) {
            throw new LocalizedException(__(
                'LiteMage warmup priority must be a whole number from %1 to %2.',
                Config::WARMUP_PRIORITY_MIN,
                Config::WARMUP_PRIORITY_MAX
            ));
        }

        $priority = (int)$value;
        if ($priority < Config::WARMUP_PRIORITY_MIN || $priority > Config::WARMUP_PRIORITY_MAX) {
            throw new LocalizedException(__(
                'LiteMage warmup priority must be from %1 to %2.',
                Config::WARMUP_PRIORITY_MIN,
                Config::WARMUP_PRIORITY_MAX
            ));
        }

        $this->setValue($priority);
        return parent::beforeSave();
    }
}
