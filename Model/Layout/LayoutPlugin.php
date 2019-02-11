<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Layout;

/**
 * Class LayoutPlugin
 */
class LayoutPlugin
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * Constructor
     *
     * @param \Litespeed\Litemage\Model\CacheControl $litemageCache
     */
    public function __construct(\Litespeed\Litemage\Model\CacheControl $litemageCache)
    {
        $this->litemageCache = $litemageCache;
    }

    /**
     * Retrieve all identities from blocks for further cache invalidation
     *
     * @param \Magento\Framework\View\Layout $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetOutput(\Magento\Framework\View\Layout $subject, $result)
    {
        if ($this->litemageCache->isCacheable()) {
            foreach ($subject->getAllBlocks() as $block) {
                if (!$block->getData('litemage_esi') && ($block instanceof \Magento\Framework\DataObject\IdentityInterface)) {
                    $this->litemageCache->addCacheTags($block->getIdentities());
                }
            }
        }
        return $result;
    }

}
