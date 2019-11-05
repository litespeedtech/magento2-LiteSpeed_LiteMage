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

    protected $_debug = false;
    protected $_debugTrace = false;
    protected $_isCacheable = -1; // sync with CacheControl var
    protected $config;

    /**
     * 
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Litespeed\Litemage\Model\Config $config
     * @param \Litespeed\Litemage\Logger\Logger $logger
     */
    public function __construct(\Magento\Framework\App\Helper\Context $context,
                                \Litespeed\Litemage\Model\Config $config,
                                \Litespeed\Litemage\Logger\Logger $logger)
    {
        parent::__construct($context);
        $this->config = $config;
        $this->_logger = $logger;
        if ($config->moduleEnabled()) {
            $this->_debug = $this->config->debugEnabled();
            $this->_debugTrace = $this->config->debugTraceEnabled();
        }
    }

    public function getUrl($route, array $params = [])
    {
        $fullurl = $this->_getUrl($route, $params);
        if ((stripos($fullurl, 'http') !== false) && ($pos = strpos($fullurl,
                                                                    '://'))) {
            // remove domain part
            $pos2 = strpos($fullurl, '/', $pos + 4);
            $fullurl = ($pos2 === false) ? '/' : substr($fullurl, $pos2);
        }
        return $fullurl;
    }

    private function log($message)
    {
        $this->_logger->notice($message); // allow to show in production mode
    }

    public function debugLog($message)
    {
        if ($this->_debug) {
            $this->log($message);
        }
    }

    public function debugTrace($message)
    {
        if ($this->_debug && $this->_debugTrace) {
            ob_start();
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 200);
            $trace = ob_get_contents();
            ob_end_clean();
            $this->log("***** $message *****\n$trace");
        }
    }

    public function debugEnabled()
    {
        return $this->_debug;
    }

    public function moduleEnabled()
    {
        return $this->config->moduleEnabled();
    }

    public function isCacheable()
    {
        return ($this->_isCacheable === 1);
    }

    public function needCustVaryAjax()
    {
        return (($this->_isCacheable === 1) && ($this->config->getCustomVaryMode() == 1));
    }

    /**
     * setCacheableFlag - should only be called by CacheControl
     * 
     * @param type $reason
     */
    public function setCacheableFlag($flag, $reason, $trace = false)
    {
        $this->_isCacheable = $flag;
        if ($this->_debug) {
            $msg = sprintf('setCacheableFlag=%d %s', $flag, $reason);
            if ($trace) {
                $this->debugTrace($msg);
            } else {
                $this->debugLog($msg);
            }
        }
    }

    /**
     * translateTags
     * @param array of string $tags
     * @return string or array
     */
    // input can be array or string
    public function translateFilterTags($tags)
    {
        $lstags = [];
        if (!empty($tags)) {
            $search = ['block', 'left-menu',
                'cms_b', 'cat_p',
                'cat_c_p', // sequence matters, need to be in front of shorter ones
                'cat_c'];
            $replace = ['B', 'l',
                'MB', 'P',
                'C', 'C'];

            $footer = false;
            foreach ($tags as $tag) {
                if (strpos($tag, 'footer') !== false) {
                    $footer = true;
                } else {
                    $lstags[] = str_replace($search, $replace, $tag);
                }
            }
            if ($footer) {
                $lstags[] = 'F';
            }

            $lstags = array_unique($lstags);
        }

//        $this->debugLog("in translate tags from = " . print_r($tags, 1) 
//          . ' to = ' . print_r($lstags,1)); 
        return $lstags;
    }

}
