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

/**
 * Class CacheControl
 *
 */
class CacheControl
{

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $config;


    /*
     * Cache related headers only for LiteSpeed Web Server
     */
    const LSHEADER_PURGE = 'X-LiteSpeed-Purge';
    const LSHEADER_CACHE_CONTROL = 'X-LiteSpeed-Cache-Control';
    const LSHEADER_CACHE_TAG = 'X-LiteSpeed-Tag';
    const LSHEADER_CACHE_VARY = 'X-LiteSpeed-Vary';
    const ENV_VARYCOOKIE_DEFAULT = '_lscache_vary'; // hardcoded by LSWS


    protected $_debug;

    protected $_purgeTags = array();

    protected $_cacheTags = array();

    protected $_isCacheable = -1; // -1: not set

    protected $_hasESI = false;

    protected $_ttl = 0;

    protected $context;

    protected $request;

        /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected $cookieManager;

    protected $helper;

    /**
     * Retrieve url
     *
     * @param string $route
     * @param array $params
     * @return string
     */

    public function __construct(\Magento\Framework\App\Http\Context $httpContext,
                                \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
                                \Magento\Framework\App\Request\Http $request,
                                \Litespeed\Litemage\Model\Config $config,
                                \Litespeed\Litemage\Helper\Data $helper
    ) {
        $this->context = $httpContext;
        $this->cookieManager = $cookieManager;
        $this->request = $request;
        $this->config = $config;
        $this->helper = $helper;
        $this->_debug = $config->debugEnabled();
    }

    public function moduleEnabled()
    {
        return $this->config->moduleEnabled();
    }

    public function debugLog($message)
    {
        if ($this->_debug === -1) {

        }
        $this->helper->debugLog($message);
    }

    public function addPurgeTags($tags)
    {
        $this->debugLog("add purge Tags " . print_r($tags, true));
        if (is_array($tags)) {
            $this->_purgeTags = array_merge($this->_purgeTags, $tags);
        }
        else if ($tags) {
            $this->_purgeTags[] = $tags;
        }
        $this->_purgeTags = array_unique(($this->_purgeTags));
    }

    public function setCacheable($isCacheable, $ttl=0)
    {
        // cannot set from non cacheable to cacheable
        if ($isCacheable) {
            if ($this->_isCacheable == -1)
                $this->_isCacheable = 1;
            if ($ttl > 0)
                $this->_ttl = $ttl;
        }
        else {
            $this->_isCacheable = 0;
        }
    }

    public function isCacheable()
    {
        return ($this->_isCacheable == 1);
    }

    public function setEsiOn($isOn)
    {
        $this->_hasESI = $isOn;
    }

    public function setCacheControlHeaders($response)
    {
        $cacheControlHeader = '';

        $responsecode = $response->getHttpResponseCode();
        if (( $responsecode == 200 || $responsecode == 404)
                && ($this->request->isGet() || $this->request->isHead())
                        && ($this->_isCacheable == 1)
        ) {
            // cacheable
            $this->setCacheTags($response);
            $changed = $this->checkCacheVary();

            $cacheControlHeader = 'public,max-age=' . $this->_ttl;
        }
        if ($this->_hasESI) {
            if ($cacheControlHeader != '')
                $cacheControlHeader .= ',';
            $cacheControlHeader .= 'esi=on';
        }
        if ($cacheControlHeader) {
            $response->setHeader(self::LSHEADER_CACHE_CONTROL, $cacheControlHeader);
            $this->debugLog('X-LiteSpeed-CacheControl ' . $cacheControlHeader);
            $this->debugLog("orginal path " . $this->request->getActionName());
        }
        if ($cch = $response->getHeader('Cache-Control')) {
            if (preg_match('/public.*s-maxage=(\d+)/', $cch->getFieldValue(), $matches)) {
                $maxAge = $matches[1];
                $response->setNoCacheHeaders();
                $this->debugLog('remove cache-control ' . $cch->getFieldValue());
            }
        }

    }

    protected function setCacheTags($response)
    {
        $lstags = '';
        if (empty($this->_cacheTags)) {
            $tagsHeader = $response->getHeader('X-Magento-Tags');
            if ($tagsHeader) {
                // from esi req
                $lstags = $tagsHeader->getFieldValue();
            }
        }
        else {
            $lstags = implode(',', $this->_cacheTags);

        }

        if ($lstags) {
            $cacheTags = $this->translateTags($lstags);
            $response->setHeader(self::LSHEADER_CACHE_TAG, $cacheTags);
            $response->setHeader('DEBUG-LiteSpeed-Tag', $cacheTags); //remove
            $response->clearHeader('X-Magento-Tags');
        }
    }

    protected function checkCacheVary()
    {
        $varyString = null;
        $data = $this->context->getData();
        if (!empty($data)) {
            ksort($data);
            $varyString = sha1(serialize($data));
        }

        $currentVary = $this->request->get(self::ENV_VARYCOOKIE_DEFAULT);
        if ($varyString != $currentVary) {
            if ($varyString) {
                $sensitiveCookMetadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()->setPath('/');
                $this->cookieManager->setSensitiveCookie(self::ENV_VARYCOOKIE_DEFAULT, $varyString, $sensitiveCookMetadata);
            } else {
                $cookieMetadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()->setPath('/');
                $this->cookieManager->deleteCookie(self::ENV_VARYCOOKIE_DEFAULT, $cookieMetadata);
            }
            return true;
        }
        return false;
    }

    public function setPurgeHeaders($response)
    {
        if (!empty($this->_purgeTags)) {
            if (in_array('*', $this->_purgeTags)) {
                $purgeTags = '*';
            }
            else {
                $purgeTags = $this->translateTags(implode(',', $this->_purgeTags));
            }
            $response->setHeader(self::LSHEADER_PURGE, $purgeTags);
            $this->debugLog('set purge header ' . $purgeTags);
        }
    }

    public function addCacheTags($tags)
    {
        if (is_array($tags)) {
            $this->_cacheTags = array_unique(array_merge($this->_cacheTags, $tags));
        }
        else if ($tags && !in_array($tags, $this->_cacheTags)) {
            $this->_cacheTags[] = $tags;
        }
    }

    public function translateTags($tagString)
    {
        $lstags = str_replace(['_block', 'catalog_product_', 'catalog_category_'],
                ['.B', 'P.', 'C.'], $tagString);
        $this->debugLog("in translate tags from = $tagString , to = $lstags");
        return $lstags;
    }

}
