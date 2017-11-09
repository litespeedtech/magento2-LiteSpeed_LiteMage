<?php
/**
 * LiteMage
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) 2016 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

/**
 * Used in creating options for Caching Application config value selection
 */

namespace Litespeed\Litemage\Model\System\Config\Source;

/**
 * Class Application
 *
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
		else
			return false;
    }

}
