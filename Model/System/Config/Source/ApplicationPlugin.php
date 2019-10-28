<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

/**
 * Used in creating options for Caching Application config value selection
 */
class ApplicationPlugin
{

    /**
     * Options getter
     *
     * @return array
     */
    public function afterToOptionArray(\Magento\PageCache\Model\System\Config\Source\Application $subject, $result)
    {
        if ($this->_hasLicense()) {
            $result[] = [
                        'value' => \Litespeed\Litemage\Model\Config::LITEMAGE,
                        'label' => __('LiteMage Cache Built-in to LiteSpeed Server')
            ];
        }
        return $result;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function afterToArray(\Magento\PageCache\Model\System\Config\Source\Application $subject, $result)
    {
        if ($this->_hasLicense()) {
            $result[\Litespeed\Litemage\Model\Config::LITEMAGE] = __('LiteMage Cache Built-in to LiteSpeed Server');
        }
        return $result;
    }

    protected function _hasLicense()
    {
        if (isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE']) {
			return true; // for lsws
		}
		elseif (isset($_SERVER['HTTP_X_LITEMAGE']) && $_SERVER['HTTP_X_LITEMAGE']) {
			return true; // for webadc
		}
		else {
			return false;
        }
    }

}
