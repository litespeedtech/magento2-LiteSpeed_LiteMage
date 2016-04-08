<?php
/**
 * LiteMage2
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

    /**
     * XML path to Varnish settings
     */
    const XML_PAGECACHE_TTL = 'system/full_page_cache/ttl';

    const XML_PAGECACHE_TYPE = 'system/full_page_cache/caching_application';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;


    /**
     * @var \Magento\Framework\App\Cache\StateInterface $_cacheState
     */
    protected $cacheState;

    /**
     * @var Filesystem\Directory\ReadFactory
     */
    protected $readFactory;

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $reader;
    protected $_debug = -1; // avail value: -1(not set), true, false

    protected $_moduleEnabled = false;

    /**
     * @param Filesystem\Directory\ReadFactory $readFactory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\Cache\StateInterface $cacheState
     * @param Dir\Reader $reader
     */
    public function __construct(
        \Magento\Framework\Filesystem\Directory\ReadFactory $readFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Cache\StateInterface $cacheState,
        \Magento\PageCache\Model\Config $pagecacheConfig,
        \Magento\Framework\Module\Dir\Reader $reader,
        \Magento\Framework\App\State $state
    ) {
        $this->readFactory = $readFactory;
        $this->scopeConfig = $scopeConfig;
        $this->cacheState = $cacheState;
        $this->reader = $reader;

        if (isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE']) {
            if ($pagecacheConfig->isEnabled()
                    && $pagecacheConfig->getType() == self::LITEMAGE
                    && $cacheState->isEnabled(\Magento\PageCache\Model\Cache\Type::TYPE_IDENTIFIER)) {
                $this->_moduleEnabled = true;
            }
        }
        if ($state->getMode() === \Magento\Framework\App\State::MODE_PRODUCTION) {
            // turn off debug for production
            $this->_debug = 0;
        }
    }


        /**
     * Check if LiteMage module is enabled based on LiteSpeed license and config
     *
     * @return bool
     */
    public function moduleEnabled()
    {
        return $this->_moduleEnabled;
    }

    public function debugEnabled()
    {
        if ($this->_debug == -1) {

        }
        return $this->_debug;
    }


    /**
     * Return page lifetime
     *
     * @return int
     * @api
     */
    public function getTtl()
    {
        return $this->scopeConfig->getValue(self::XML_PAGECACHE_TTL);
    }

    /**
     * Return generated varnish.vcl configuration file
     *
     * @param string $vclTemplatePath
     * @return string
     * @api
     */
    public function getVclFile($vclTemplatePath)
    {
        $moduleEtcPath = $this->reader->getModuleDir(Dir::MODULE_ETC_DIR, 'Magento_PageCache');
        $configFilePath = $moduleEtcPath . '/' . $this->_scopeConfig->getValue($vclTemplatePath);
        $directoryRead = $this->readFactory->create($moduleEtcPath);
        $configFilePath = $directoryRead->getRelativePath($configFilePath);
        $data = $directoryRead->readFile($configFilePath);
        return strtr($data, $this->_getReplacements());
    }


    /**
     * Get IPs access list that can purge Varnish configuration for config file generation
     * and transform it to appropriate view
     *
     * acl purge{
     *  "127.0.0.1";
     *  "127.0.0.2";
     *
     * @return mixed|null|string
     */
    protected function _getAccessList()
    {
        $result = '';
        $tpl = "    \"%s\";";
        $accessList = $this->_scopeConfig->getValue(
            self::XML_VARNISH_PAGECACHE_ACCESS_LIST,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if (!empty($accessList)) {
            $ips = explode(',', $accessList);
            foreach ($ips as $ip) {
                $result[] = sprintf($tpl, trim($ip));
            }
            return implode("\n", $result);
        }
        return $result;
    }

    /**
     * Get regexs for design exceptions
     * Different browser user-agents may use different themes
     * Varnish supports regex with internal modifiers only so
     * we have to convert "/pattern/iU" into "(?Ui)pattern"
     *
     * @return string
     */
    protected function _getDesignExceptions()
    {
        $result = '';
        $tpl = "%s (req.http.user-agent ~ \"%s\") {\n" . "        hash_data(\"%s\");\n" . "    }";

        $expressions = $this->_scopeConfig->getValue(
            self::XML_VARNISH_PAGECACHE_DESIGN_THEME_REGEX,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        if ($expressions) {
            $rules = array_values(unserialize($expressions));
            foreach ($rules as $i => $rule) {
                if (preg_match('/^[\W]{1}(.*)[\W]{1}(\w+)?$/', $rule['regexp'], $matches)) {
                    if (!empty($matches[2])) {
                        $pattern = sprintf("(?%s)%s", $matches[2], $matches[1]);
                    } else {
                        $pattern = $matches[1];
                    }
                    $if = $i == 0 ? 'if' : ' elsif';
                    $result .= sprintf($tpl, $if, $pattern, $rule['value']);
                }
            }
        }
        return $result;
    }
}
