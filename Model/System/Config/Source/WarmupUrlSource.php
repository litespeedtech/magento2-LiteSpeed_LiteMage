<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

class WarmupUrlSource implements \Magento\Framework\Option\ArrayInterface
{
    public const SOURCE_SITEMAP = 'sitemap';
    public const SOURCE_URL_REWRITE = 'url_rewrite';
    public const SOURCE_TEXT_FILE = 'text_file';
    public const SOURCE_PURGE_ENTITY = 'purge_entity';

    public function toOptionArray()
    {
        return [
            ['value' => self::SOURCE_SITEMAP, 'label' => __('Sitemap')],
            ['value' => self::SOURCE_URL_REWRITE, 'label' => __('Magento URL Rewrites')],
            ['value' => self::SOURCE_TEXT_FILE, 'label' => __('Text/CSV File')],
        ];
    }
}
