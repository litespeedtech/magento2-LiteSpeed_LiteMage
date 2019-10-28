<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

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

    private const CFGXML_DEFAULTLM = 'litemage' ;

	private const STOREXML_PUBLICTTL = 'system/full_page_cache/ttl';

    private const CFG_DEBUGON = 'debug' ;
    private const CFG_CONTEXTBYPASS = 'contextbypass';
    private const CFG_CUSTOMVARY = 'custom_vary';
    //const CFG_ADMINIPS = 'admin_ips';
    private const CFG_PUBLICTTL = 'public_ttl';
    private const LITEMAGE_GENERAL_CACHE_TAG = 'LITESPEED_LITEMAGE' ;

    // config items
    protected $_conf = [];
    protected $_userModuleEnabled = -2 ; // -2: not set, true, false
    protected $_esiTag;
    protected $_isDebug ;
    protected $_bypassedContext = [];

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
    protected $_debug_trace = 0;

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

        $this->_debug = $this->getConf(self::CFG_DEBUGON);
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

    public function debugTraceEnabled()
    {
        return $this->_debug_trace;
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

    public function getBypassedContext()
    {
        return $this->getConf(self::CFG_CONTEXTBYPASS);
    }

    public function getCustomVaryMode()
    {
        return $this->getConf(self::CFG_CUSTOMVARY);
    }

    protected function _initConf( $type = '' )
    {
        $this->_conf = [];

        if ( ! isset($this->_conf['defaultlm']) ) {
            $this->_conf['defaultlm'] = $this->scopeConfig->getValue(self::CFGXML_DEFAULTLM) ;
        }
        $lm = $this->_conf['defaultlm'];
        $pattern = "/[\s,]+/" ;

        switch ( $type ) {

            default:
                $debugon = isset($lm['dev'][self::CFG_DEBUGON]) ? $lm['dev'][self::CFG_DEBUGON] : 0;
                if ($debugon && isset($lm['dev']['debug_ips'])) {
                    // check ips
                    $debugips = trim($lm['dev']['debug_ips']);
                    if (PHP_SAPI !== 'cli' && $debugips) {
                        $ips = array_unique(preg_split($pattern, $debugips, null, PREG_SPLIT_NO_EMPTY));
                        if (!empty($ips)) {
                            $ip = $this->_getIp();
                            if (!in_array($ip, $ips)) {
                                $debugon = 0;
                            }
                        }
                    }
                }

                $this->_conf[self::CFG_DEBUGON] = $debugon ;
                $this->_isDebug = $debugon;
                if ($debugon) {
                    $this->_debug_trace = isset($lm['dev']['debug_trace']) ? $lm['dev']['debug_trace'] : 0;
                }
                $this->_conf[self::CFG_PUBLICTTL] = $this->scopeConfig->getValue(self::STOREXML_PUBLICTTL);

                $bypass = isset($lm['general'][self::CFG_CONTEXTBYPASS]) ? $lm['general'][self::CFG_CONTEXTBYPASS] : '';
                if ($bypass) {
                    $this->_conf[self::CFG_CONTEXTBYPASS] = array_unique(preg_split($pattern, $bypass, null, PREG_SPLIT_NO_EMPTY));
                } else {
                    $this->_conf[self::CFG_CONTEXTBYPASS] = [];
                }
                $this->_conf[self::CFG_CUSTOMVARY] = isset($lm['general'][self::CFG_CUSTOMVARY]) ? $lm['general'][self::CFG_CUSTOMVARY] : 0;
                $this->_esiTag = array('include' => 'esi:include', 'inline' => 'esi:inline', 'remove' => 'esi:remove');
        }
    }

    protected function _getIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }

}
