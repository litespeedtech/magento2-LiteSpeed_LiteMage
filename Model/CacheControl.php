<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

use Magento\Framework\View\Layout\Element as Element;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class CacheControl
 *
 */
class CacheControl
{

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /*
     * Cache related headers only for LiteSpeed Web Server
     */

    private const LSHEADER_CACHE_CONTROL = 'X-LiteSpeed-Cache-Control';
    private const LSHEADER_CACHE_TAG = 'X-LiteSpeed-Tag';
    private const LSHEADER_CACHE_VARY = 'X-LiteSpeed-Vary';
    private const ENV_VARYCOOKIE_DEFAULT = '_lscache_vary'; // hardcoded by LSWS
    private const LITEMAGE_CUSTVARY_COOKIE = 'litemage-custvary';
    private const LSHEADER_DEBUG_INFO = 'X-LiteMage-Debug-Info';
    private const LSHEADER_DEBUG_CC = 'X-LiteMage-Debug-CC';
    private const LSHEADER_DEBUG_VARY = 'X-LiteMage-Debug-Vary';
    private const LSHEADER_DEBUG_Tag = 'X-LiteMage-Debug-Tag';

    protected $_bypassedContext = [];
    protected $_cacheTags = [];
    protected $_isCacheable = -1; // -1: not set, 0: No, 1: true
    protected $_isEsiRequest = false;
    protected $_moduleEnabled;
    protected $_hasESI = false;
    protected $_ttl = 0;
    protected $_nocacheReason = '';

    /** @var \Magento\Framework\App\Http\Context */
    protected $httpContext;
    protected $request;

    /** @var \Magento\Framework\Session\SessionManagerInterface */
    protected $session;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    protected $cookieMetadataFactory;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    protected $storeManager;
    protected $helper;
    protected $rawVaryString; // for debug only

    /**
     * constructor
     *
     * @param \Magento\Framework\App\Http\Context $httpContext,
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
     * @param \Magento\Framework\App\Request\Http $requObserver/Checkcurrentcountrystore.php
      est,
     * @param \Litespeed\Litemage\Model\Config $config,
     * @param \Litespeed\Litemage\Helper\Data $helper
     */
    public function __construct(\Magento\Framework\App\Http\Context $httpContext,
                                \Magento\Framework\Session\SessionManagerInterface $session,
                                \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
                                \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
                                \Magento\Framework\App\Request\Http $request,
                                \Magento\Store\Model\StoreManagerInterface $storeManager,
                                \Litespeed\Litemage\Model\Config $config,
                                \Litespeed\Litemage\Helper\Data $helper
    )
    {
        $this->httpContext = $httpContext;
        $this->session = $session;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->request = $request;
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->helper = $helper;
        $this->_moduleEnabled = $config->moduleEnabled();
        if ($this->_moduleEnabled) {
            $this->_bypassedContext = $this->config->getBypassedContext();
        }

        $reason = '';
        if (!$this->_moduleEnabled) {
            $reason = 'module disabled';
        } elseif (!$request->isGet() && !$request->isHead()) {
            $reason = $request->getMethod();
        } elseif ($request->isAjax() && $request->getQuery('_')) {
            $reason = 'ajax with random string';
        }

        if ($reason) {
            $fullreason = sprintf("%s CacheControl constructor: %s",
                                  $request->getRequestUri(), $reason);
            $this->setNotCacheable($fullreason, $reason);
        }
    }

    public function moduleEnabled()
    {
        return $this->_moduleEnabled;
    }

    public function setCacheable($ttl, $msg)
    {
        // cannot set from non cacheable to cacheable
        if ($this->_isCacheable == -1) {
            $this->_isCacheable = 1;
            $this->helper->setCacheableFlag(1, $msg);
        }
        if ($ttl > 0) {
            $this->_ttl = $ttl;
        }
    }

    public function setNotCacheable($reason, $shortReason = '')
    {
        if ($this->_isCacheable != 0) {
            $this->_isCacheable = 0;
            $this->helper->setCacheableFlag(0, $reason);
            $this->_nocacheReason = $shortReason ?: $reason;
        }
    }

    public function setEsiRequest($isEsiReq = true)
    {
        $this->_isEsiRequest = $isEsiReq;
    }


    public function isCacheable()
    {
        return ($this->_isCacheable === 1);
    }

    // either unknown or cacheable
    public function maybeCacheable()
    {
        return ($this->_isCacheable !== 0);
    }

    public function canInjectEsi()
    {
        return ($this->_isCacheable === 1 && !$this->_isEsiRequest);
    }

    public function getEsiUrl($handles, $blockName)
    {
        $url = $this->helper->getUrl(
                'litemage/block/esi',
                [
                    'b' => $blockName,
                    'h' => $this->encodeEsiHandles($handles)
                ]
        );

        return $url;
    }

    protected function encodeEsiHandles($handles)
    {
        $translator = $this->config->getEsiHandlesTranslator();
        $ignored = $this->config->getEsiHandlesIgnored();
        $used = [];

        foreach ($handles as $h) {
            if (isset($translator[$h])) {
                $used[] = $translator[$h];
            } else {
                foreach ($ignored as $i) {
                    if (strpos($h, $i) !== false) {
                        break 2;
                    }
                }
                $used[] = $h;
            }
        }
        return implode(',', $used);
    }

    public function decodeEsiHandles($reqParam)
    {
        $translator = $this->config->getEsiHandlesTranslator();
        $used = explode(',', $reqParam);
        $handles = [];
        foreach ($used as $h) {
            if ($realHandle = array_search($h, $translator)) {
                $handles[] = $realHandle;
            } else {
                $handles[] = $h;
            }
        }
        return $handles;
    }

    public function setEsiOn($isOn)
    {
        $this->_hasESI = $isOn;
    }

    public function setCacheControlHeaders($response)
    {
        $cacheControlHeader = '';
        $lstags = '';
        $changed = $this->checkCacheVary();

        $responsecode = $response->getHttpResponseCode();
        if ($responsecode == 404) {
            if (strpos($this->request->getRequestUri(), 'checkout') !== false) {
                $this->_isCacheable = 0;
                $this->helper->setCacheableFlag(0, 'CHECKOUT ALERT 404', true);
            }
        }

        if (( $responsecode == 200 || $responsecode == 404) && ($this->_isCacheable == 1)
        ) {
            // cacheable
            $lstags = $this->_setCacheTagHeader($response);

            $cacheControlHeader = 'public,max-age=' . $this->_ttl;
        }
        if ($this->_hasESI) {
            if ($cacheControlHeader != '')
                $cacheControlHeader .= ',';
            $cacheControlHeader .= 'esi=on';
        }

        if ($cacheControlHeader) {
            $response->setHeader(self::LSHEADER_CACHE_CONTROL,
                                 $cacheControlHeader);
            $this->helper->debugLog(sprintf('SetCacheControlHeaders: %s Tags: %s',
                                            $cacheControlHeader, $lstags));
        }
        if ($cch = $response->getHeader('Cache-Control')) {
            if (preg_match('/public.*s-maxage=(\d+)/', $cch->getFieldValue(),
                           $matches)) {
                $maxAge = $matches[1];
                $response->setNoCacheHeaders();
            }
        }
        if ($this->helper->debugEnabled() == 2) {
            // show debug headers
            $response->setHeader(self::LSHEADER_DEBUG_CC, $cacheControlHeader);
            $response->setHeader(self::LSHEADER_DEBUG_Tag, $lstags);
            $response->setHeader(self::LSHEADER_DEBUG_INFO,
                                 substr(htmlspecialchars(str_replace("\n", ' ',
                                                                     $this->_nocacheReason)),
                                                                     0, 256));
            $response->setHeader(self::LSHEADER_DEBUG_VARY, $this->rawVaryString);
        }
    }

    protected function _setCacheTagHeader($response)
    {
        $lstags = '';
        if (!empty($this->_cacheTags)) {
            $lstags = implode(',', $this->helper->translateFilterTags($this->_cacheTags));
            $response->setHeader(self::LSHEADER_CACHE_TAG, $lstags);
            $response->clearHeader('X-Magento-Tags');
        }
        return $lstags;
    }

    public function checkCacheVary()
    {
        // check custvary first
        $rawdata = $varyString = '';
        $varymode = $this->config->getCustomVaryMode();
        if ($varymode == 2) {
            $this->httpContext->setValue('litemage_custvary_enforce', $varymode,
                                         0);
        }
        if ($varymode) { // 1 or 2
            $curcustvary = $this->request->get(self::LITEMAGE_CUSTVARY_COOKIE);
            if ($curcustvary != $varymode) {
                $cookMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()->setPath('/')->setHttpOnly(false)->setSecure(false);
                $this->cookieManager->setPublicCookie(self::LITEMAGE_CUSTVARY_COOKIE,
                                                      $varymode, $cookMetadata);
            }
        }

        $data = $this->httpContext->getData();

        // always check store & currency again. some bad plugins will update store without updating context
        $currentStore = $this->storeManager->getStore();
        $defaultStore = $this->storeManager->getWebsite()->getDefaultStore();
        if ($currentStore->getCode() != $defaultStore->getCode()) {
            $data[StoreManagerInterface::CONTEXT_STORE] = $currentStore->getCode();
        }

        $currentCurrency = $this->session->getCurrencyCode() ?: $currentStore->getDefaultCurrencyCode();
        $defaultCurrency = $defaultStore->getDefaultCurrencyCode();
        if ($currentCurrency != $defaultCurrency) {
            $data[HttpContext::CONTEXT_CURRENCY] = $currentCurrency;
        }

        if (!empty($data) && !empty($this->_bypassedContext)) {
            // already not cacheable, like POST request, do filter
            $data = array_filter($data,
                                 function($k) {
                return (!in_array($k, $this->_bypassedContext));
            }, ARRAY_FILTER_USE_KEY);
        }

        if (!empty($data)) {
            ksort($data);
            $rawdata = http_build_query($data);
            if ($rawdata)
                $varyString = sha1(json_encode($rawdata));
        }

        $changed = false;
        $currentVary = $this->request->get(self::ENV_VARYCOOKIE_DEFAULT);
        if ($varyString != $currentVary) {
            if ($varyString) {
                $sensitiveCookMetadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()->setPath('/');
                $this->cookieManager->setSensitiveCookie(self::ENV_VARYCOOKIE_DEFAULT,
                                                         $varyString,
                                                         $sensitiveCookMetadata);
            } else {
                $cookieMetadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()->setPath('/');
                $this->cookieManager->deleteCookie(self::ENV_VARYCOOKIE_DEFAULT,
                                                   $cookieMetadata);
            }
            $changed = true;
            $rawdata .= ' changed';
            $this->setNotCacheable("EnvVary $rawdata");
        }

        if ($rawdata) {
            $this->helper->debugLog("EnvVary: $rawdata");
        }
        $this->rawVaryString = $rawdata;
        return $changed;
    }

    public function addCacheTags($tags)
    {
        if (is_array($tags)) {
            $this->_cacheTags = array_unique(array_merge($this->_cacheTags,
                                                         $tags));
        } elseif ($tags && !in_array($tags, $this->_cacheTags)) {
            $this->_cacheTags[] = $tags;
        }
    }

    public function setCacheTags($tags)
    {
        if (!is_array($tags)) {
            $this->_cacheTags = [$tags];
        } else {
            $this->_cacheTags = $tags;
        }
    }

    // used by ESI blocks
    public function getElementCacheTags($layout, $elementName)
    {
        if (!$layout->hasElement($elementName))
            return '';

        $tags = [];

        // get all children blocks
        $blocks = [];
        $allnames = [$elementName];
        $etypes = [Element::TYPE_BLOCK, Element::TYPE_CONTAINER, Element::TYPE_UI_COMPONENT];

        while (count($allnames)) {
            $parent = array_pop($allnames);
            if ($block = $layout->getBlock($parent)) {
                $block->setData('litemage_esi', 1);
                if ($block instanceof \Magento\Framework\DataObject\IdentityInterface) {
                    // special handling for known blocks
                    if ($block instanceof \Magento\Theme\Block\Html\Topmenu) {
                        $tags[] = 'topnav';
                    } else {
                        $tags = array_merge($tags, $block->getIdentities());
                    }
                    $block->setData('litemage_esi', 2);
                }
            }
            $childNames = $layout->getChildNames($parent);
            foreach ($childNames as $childName) {
                $type = $layout->getElementProperty($childName, 'type');
                if (in_array($type, $etypes)) {
                    array_push($allnames, $childName);
                }
            }
        }
        if (empty($tags)) {
            $tags[] = $elementName;
        }
        return $this->helper->translateFilterTags($tags);
    }

}
