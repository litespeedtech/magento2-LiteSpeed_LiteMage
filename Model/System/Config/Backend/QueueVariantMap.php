<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Backend;

use Litespeed\Litemage\Model\Warmup\QueueVariantConfig;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class QueueVariantMap extends Value
{
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new LocalizedException(__('LiteMage queue/variant map must be valid JSON.'));
        }

        $normalized = QueueVariantConfig::normalizeMap($decoded);
        $json = json_encode($normalized);
        if ($json === false) {
            throw new LocalizedException(__('LiteMage queue/variant map could not be saved.'));
        }

        $this->setValue($json);
        return parent::beforeSave();
    }
}
