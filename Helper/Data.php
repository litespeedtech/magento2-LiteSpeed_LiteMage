<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Helper;

/**
 * Helper for LiteMage module
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    public function __construct(\Magento\Framework\App\Helper\Context $context,
            \Litespeed\Litemage\Logger\Logger $logger)
    {
        parent::__construct($context);
        $this->_logger = $logger;
    }

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
        $this->_logger->notice($message); // allow to show in production mode
    }

}
