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
 * @copyright  Copyright (c) 2016-2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

/**
 * Litemage cache data helper
 *
 */

namespace Litespeed\Litemage\Helper;

/**
 * Helper for LiteMage module
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    protected $_debugTag;

    public function getUrl($route, array $params = [])
    {
        $fullurl = $this->_getUrl($route, $params);
        if ((stripos($fullurl, 'http') !== false) && ($pos = strpos($fullurl, '://'))) {
            // remove domain part
            $pos2 = strpos($fullurl, '/', $pos + 4);
            $fullurl = ($pos2 === false) ? '/' : substr($fullurl, $pos2);
        }
        return $fullurl;
    }

    public function debugLog($message)
    {
        if ($this->_debugTag == '') {
            $this->_initDebugTag();
        }
        $message = str_replace("\n", ("\n" . $this->_debugTag . '  '), $message);
        $this->_logger->debug($this->_debugTag . ' ' . $message);
    }

    protected function _initDebugTag()
    {
        $this->_debugTag = 'LiteMage ';
        //$cronUserAgent = \Litespeed\Litemage\Model\Cron::USER_AGENT;

        if ($this->_remoteAddress) {
            // from server http request
            $this->_debugTag .= '[';
            /*if ($this->_httpHeader->getHttpUserAgent() == $cronUserAgent) {
                $this->_debugTag .= $cronUserAgent . ':';
            }*/
            $this->_debugTag .= $_SERVER['REMOTE_ADDR'];
            $msec = microtime();
            $msec1 = substr($msec, 2, strpos($msec, ' ') - 2);
            $this->_debugTag .= ':' . $_SERVER['REMOTE_PORT'] . ':' . $msec1 . ']';
        }
    }

}
