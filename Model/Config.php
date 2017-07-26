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

namespace Litespeed\Litemage\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\Module\Dir;

/**
 * Class Config
 *
 */
class Config
{
    /**
     * Cache types
     */
    const LITEMAGE = 'LITEMAGE';


    const CFGXML_DEFAULTLM = 'litemage' ;

	const STOREXML_PUBLICTTL = 'system/full_page_cache/ttl';

    const CFG_DEBUGON = 'debug' ;
    //const CFG_ADMINIPS = 'admin_ips';
    const CFG_PUBLICTTL = 'public_ttl';
    const LITEMAGE_GENERAL_CACHE_TAG = 'LITESPEED_LITEMAGE' ;

    // config items
    protected $_conf = array() ;
    protected $_userModuleEnabled = -2 ; // -2: not set, true, false
    protected $_esiTag;
    protected $_isDebug ;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Filesystem\Directory\ReadFactory
     */
    protected $readFactory;

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $reader;
    protected $_debug = -1; // avail value: -1(not set), true, false

	/**
	 * @var int moduleStatus bitmask
	 *	 1: SERVER variable set
	 *	 2: FPC enabled
	 *   4: FPC type is LITEMAGE
	 */
	protected $_moduleStatus = 0;

    /**
     * @param Filesystem\Directory\ReadFactory $readFactory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\Cache\StateInterface $cacheState
     * @param Dir\Reader $reader
     */
    public function __construct(
        \Magento\Framework\Filesystem\Directory\ReadFactory $readFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\PageCache\Model\Config $pagecacheConfig,
        \Magento\Framework\Module\Dir\Reader $reader,
        \Magento\Framework\App\State $state
    ) {
        $this->readFactory = $readFactory;
        $this->scopeConfig = $scopeConfig;
        $this->reader = $reader;

		if ($this->licenseEnabled()) {
            $this->_moduleStatus |= 1;
		}
        if ($pagecacheConfig->isEnabled()) {
			$this->_moduleStatus |= 2;
		}
        if ($pagecacheConfig->getType() == self::LITEMAGE) {
			$this->_moduleStatus |= 4;
        }

        if ($state->getMode() === \Magento\Framework\App\State::MODE_PRODUCTION) {
            // turn off debug for production
            $this->_debug = 0;
        }
        else {
            $this->_debug = $this->getConf(self::CFG_DEBUGON);
        }

    }


    /**
     * Check if LiteMage module is enabled based on LiteSpeed license and config
     *
     * @return bool
     */
    public function moduleEnabled()
    {
		if (PHP_SAPI == 'cli') {
			return ($this->_moduleStatus == 6);
		}
		else {
			return ($this->_moduleStatus == 7);
		}
    }

    public function debugEnabled()
    {
        return $this->_debug;
    }

    public function licenseEnabled()
    {
        return ( (isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE'])
                || (isset($_SERVER['HTTP_X_LITEMAGE']) && $_SERVER['HTTP_X_LITEMAGE']));
    }

    /**
     * Return page lifetime
     *
     * @return int
     * @api
     */
    public function getTtl()
    {
        return $this->getConf(self::CFG_PUBLICTTL);
    }

    public function getEsiHandlesTranslator()
    {
        $translator = [
            'default' => '-',
            'catalog_product_view' => 'LPV',
            'catalog_category_view' => 'LCV',
            'catalog_category_view_type_layered' => 'LCVTL',
            'cms_page_view' => 'MPV'
        ];
        return $translator;
    }

    public function getEsiHandlesIgnored()
    {
        //catalog_product_view_id_1593,catalog_product_view_sku_WS05, catalog_category_view_id_21
        $ignored = [
            'catalog_product_view_id_',
            'catalog_product_view_sku_',
            'catalog_category_view_id_'
        ];
        return $ignored;
    }

    public function isAdminIP()
    {
        if ($adminIps = $this->getConf(self::CFG_ADMINIPS) ) {
            $remoteAddr = Mage::helper('core/http')->getRemoteAddr() ;
            if (in_array($remoteAddr, $adminIps)) {
                return true;
            }
        }
        return false;
    }

    public function esiTag($type)
    {
        if (isset($this->_esiTag[$type])) {
            return $this->_esiTag[$type];
        }
    }

    public function getConf( $name, $type = '' )
    {
        if ( ($type == '' && ! isset($this->_conf[$name])) || ($type != '' && ! isset($this->_conf[$type])) ) {
            $this->_initConf($type) ;
        }

        if ( $type == '' )
            return $this->_conf[$name] ;
        else if ( $name == '' )
            return $this->_conf[$type] ;
        else
            return $this->_conf[$type][$name] ;
    }

    protected function _initConf( $type = '' )
    {
        $this->_conf = [];
      //  return;
        if ( ! isset($this->_conf['defaultlm']) ) {
            $this->_conf['defaultlm'] = $this->scopeConfig->getValue(self::CFGXML_DEFAULTLM) ;
        }
        $pattern = "/[\s,]+/" ;

        switch ( $type ) {

            default:
                $general = $this->_conf['defaultlm']['general'] ;

                $this->_conf[self::CFG_DEBUGON] = $general[self::CFG_DEBUGON] ;
                $this->_isDebug = $this->_conf[self::CFG_DEBUGON] ; // required by cron, needs to be set even when module disabled.
                $this->_conf[self::CFG_PUBLICTTL] = $this->scopeConfig->getValue(self::STOREXML_PUBLICTTL);

                $this->_esiTag = array('include' => 'esi:include', 'inline' => 'esi:inline', 'remove' => 'esi:remove');
        }
    }


}
