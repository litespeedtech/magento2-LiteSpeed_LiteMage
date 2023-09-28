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
    protected $_ignoredBlocks = [];
    protected $_ignoredTags = [];
    protected $_cacheTags = [];
    protected $_isCacheable = -1; // -1: not set, 0: No, 1: public, 2: private
	protected $_isStaticAssets;
    protected $_isEsiRequest = false;
    protected $_moduleEnabled;
    protected $_hasESI = false;
    protected $_ttl = 0; 
    protected $_nocacheReason = '';
    protected $_internal = [];

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

    /** @var \Magento\Customer\Model\Session */
    protected $userSession;
    
    /** @var \Litespeed\Litemage\Helper\Data */
    protected $helper;
    
    protected $rawVaryString; // for debug only

    /**
     * constructor
     *
     * @param HttpContext $httpContext
     * @param \Magento\Framework\Session\SessionManagerInterface $session
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
     * @param \Magento\Framework\App\Request\Http $request
     * @param StoreManagerInterface $storeManager
     * @param \Magento\Customer\Model\Session $userSession
     * @param \Litespeed\Litemage\Model\Config $config
     * @param \Litespeed\Litemage\Helper\Data $helper
     */
    public function __construct(\Magento\Framework\App\Http\Context $httpContext,
                                \Magento\Framework\Session\SessionManagerInterface $session,
                                \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
                                \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
                                \Magento\Framework\App\Request\Http $request,
                                \Magento\Store\Model\StoreManagerInterface $storeManager,
                                \Magento\Customer\Model\Session $userSession,
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
        $this->userSession = $userSession;

        $this->config = $config;
        $this->helper = $helper;
        $this->_moduleEnabled = $config->moduleEnabled();
        if ($this->_moduleEnabled) {
            $this->_bypassedContext = $this->config->getBypassedContext();
            $this->_ignoredBlocks = $this->config->getIgnoredBlocks();
            $this->_ignoredTags = $this->config->getIgnoredTags();
			$uri = $request->getUriString();
			$this->_isStaticAssets = in_array(substr($uri, -4), ['.css','.ico']);
            $this->debugLog(sprintf('New Request %s %s',
                            $request->getMethod(),
                            $uri));
        }

        $reason = '';
        if (!$this->_moduleEnabled) {
            $reason = 'module disabled';
        } elseif (!$request->isGet() && !$request->isHead()) {
            $reason = $request->getMethod();
        } elseif ($request->isAjax()) {
            if ($request->getQuery('_')) {
                $reason = 'ajax with random string';
            } elseif ('true' === $request->getQuery('force_new_section_timestamp')) {
                $reason = 'ajax with force_new_section_timestamp';
            } elseif ($request->getQuery('version')) {
                $reason = 'ajax with version string';
            }
        }
        // check if private info
        if (!$reason && $request->getControllerModule() == 'Magento_Customer') {
            $reason = 'Customer Private Data';
        }

        if ($reason) {
            $this->setNotCacheable("CacheControl constructor: $reason", $reason);
        }
    }

    public function moduleEnabled()
    {
        return $this->_moduleEnabled;
    }

    public function debugEnabled()
    {
        return $this->helper->debugEnabled();
    }
    
    /**
     * Can only reduce scope, make ttl smaller, public change to private
     * @param int $ttl > 0, is public, < 0, is private, -1, ESI not cacheable
     * @param string $msg
     */
    public function setCacheable($ttl, $msg, $trace=false)
    {
        $updated = false;
        
        // check valid range
        if ($ttl == -1) {
            // special value for ESI block not cacheable
            $ttl = 0;
        } elseif ($ttl < -1) {
            // for private cache, 300-14400 (5min - 4hr)
            if ($ttl > -300) {
                $ttl = -300;
            } elseif ($ttl < -14400) {
                $ttl = -14400;
            }
        } elseif ($ttl > 0) {
            // for public cache, >= 600
            if ($ttl < 600) {
                $ttl = 600;
            }
        } 
            
        switch ($this->_isCacheable) {
            case -1: // init
                if ($ttl === null || $ttl > 0) {
                    $this->_isCacheable = 1; // public cacheable
                } elseif ($ttl < 0) {
                    $this->_isCacheable = 2; // private cacheable
                } else {
                    $this->_isCacheable = 0; // not cacheable
                }
                $updated = true;
                break;
                
            case 1: // already set public
                if ($ttl > 0 && $ttl < $this->_ttl) { // allow reduce ttl
                    $updated = true; 
                } elseif ($ttl < 0) { // downgrade to private
                    $this->_isCacheable = 2; // private cacheable
                    $updated = true;
                } elseif ($ttl == 0) {
                    $this->_isCacheable = 0;
                    $updated = true;
                }
                break;
                
            case 2: // already set private
                if ($ttl < 0 && $ttl > $this->_ttl) { // allow reduce ttl
                    $updated = true; 
                } elseif ($ttl == 0) {
                    $this->_isCacheable = 0;
                    $updated = true;
                }
                break;
                
            case 0: // cannot switch from non-cacheable to cacheable
                break;
        }
        
        if ($updated) {
            $this->_ttl = $ttl;
            $this->helper->setCacheableFlag($this->_isCacheable, $msg, $trace);
        }
        return $updated;
    }

    public function setNotCacheable($reason, $shortReason = '')
    {
        if ($this->setCacheable(0, $reason)) {
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
        return ($this->_isCacheable === 1 && !$this->_isEsiRequest && !$this->_isStaticAssets);
    }

    public function isLoggedIn()
    {
        return ($this->userSession->getCustomerGroupId() > 0);
    }

    public function getEsiUrl($handles, $blockName)
    {
        $url = $this->helper->getUrl(
                'litemage/block/esi',
                [
                    'b' => $blockName,
                    'h' => $this->encodeEsiHandles($handles),
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

    public function debugLog($message)
    {
        $this->helper->debugLog($message);
    }
    
    public function setCacheControlHeaders($response) 
    {
        if (isset($this->_internal['cch'])) {
             $this->helper->debugLog("SetCacheControlHeaders ignored, already set. ($this->_ttl)");
             return;
        }
        $lstags = '';
        $changed = $this->checkCacheVary();

        $responsecode = $response->getHttpResponseCode();
        if ($responsecode == 404) {
            if (strpos($this->request->getRequestUri(), 'checkout') !== false && !$this->_isStaticAssets) {
                $this->setCacheable(0, 'CHECKOUT ALERT 404', true);
            } else {
				$this->addCacheTags('404'); // later can set a 404 ttl, purge 404 pages
			}
        }
        
        if (( $responsecode == 200 || $responsecode == 404) && ($this->_isCacheable > 0)
        ) {
            // cacheable
            $lstags = $this->setCacheTagHeader($response);
            $cache_control = sprintf('%s,max-age=%d', 
                    (($this->_isCacheable == 1) ? 'public':'private'),
                    abs($this->_ttl));
        } else {
			$cache_control = 'no-cache';
		}
        
        if ($this->_hasESI) {
            $cache_control .= ',esi=on';
        }

		$response->setHeader(self::LSHEADER_CACHE_CONTROL, $cache_control);
		if ($cache_control != 'no-cache') {
			$this->helper->debugLog(sprintf('SetCacheControlHeaders: %s %s Tags: %s',
										$cache_control,
										$this->request->getRequestUri(),
										$lstags));
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
            $response->setHeader(self::LSHEADER_DEBUG_CC, $cache_control);
            $response->setHeader(self::LSHEADER_DEBUG_INFO,
                                 substr(htmlspecialchars(str_replace("\n", ' ',
                                                                     $this->_nocacheReason)),
                                                                     0, 256));
            $response->setHeader(self::LSHEADER_DEBUG_VARY, $this->rawVaryString);
        }
        $this->_internal['cch'] = $cache_control;
    }
    
    protected function setCacheTagHeader($response)
    {
		$tags = [];
		if (empty($this->_cacheTags)) {
            $tagsHeader = $response->getHeader('X-Magento-Tags');
            $this->_cacheTags = $tagsHeader ? explode(',', $tagsHeader->getFieldValue() ?? '') : [];
		}
        if (!empty($this->_cacheTags)) {
            $tags = $this->helper->translateFilterTags($this->_cacheTags);

            if (!empty($this->_ignoredTags)) {
                $tag1 = array_diff($tags, $this->_ignoredTags);
                if (count($tag1) != count($tags)) {
                    $this->helper->debugLog("Ignored translated tags " . implode(',', array_diff($tags, $tag1)) );
                    $tags = $tag1;
                }
            }
		}

		if (!in_array('MB', $tags)) {
			array_unshift($tags, 'MB'); // MB is required
		}
		if (!in_array('store', $tags)) {
			array_unshift($tags, 'store'); // MB is required
		}
        
		$lstags = implode(',', $tags);
		$response->setHeader(self::LSHEADER_CACHE_TAG, $lstags);
		$response->clearHeader('X-Magento-Tags');
		if ($this->helper->debugEnabled() == 2) {
			$response->setHeader(self::LSHEADER_DEBUG_Tag, $lstags);
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
            if ($rawdata) {
                $varyString = sha1(json_encode($rawdata));
            }
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
            $rawdata .= ' changed'; // vary changed can be cached
        } else {
            // check vary value
            // $ov = isset($_SERVER['LSCACHE_VARY_VALUE']) ? $_SERVER['LSCACHE_VARY_VALUE'] : '';

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

            if (!empty($this->_ignoredTags)) {
                $tags1 = array_diff($tags, $this->_ignoredTags);
                if (count($tags1) != count($tags)) {
                    $this->helper->debugLog("Ignored tags " . implode(',', array_diff($tags, $tags1)) );
                    $tags = $tags1;
                }
            }

            $this->_cacheTags = array_unique(array_merge($this->_cacheTags, $tags));

        } elseif ($tags && !in_array($tags, $this->_cacheTags) && !in_array($tags, $this->_ignoredTags)) {
            
            $this->helper->debugLog("Added single tag $tags");
            $this->_cacheTags[] = $tags;
        }
    }

    public function addCacheTagsFromIdentityBlock($name, $block)
    {
        if (in_array($name, $this->_ignoredBlocks)) {
            $this->helper->debugLog("Identity block $name ignored");
            return;
        }
        $tags = array_unique($block->getIdentities());
        $cnt = count($tags);
        if ($cnt > 100) {
            $this->helper->debugLog("Identity block $name contains $cnt tags. too many. take first 100 tags only. detail: " . implode(', ', $tags));
            $tags = array_slice($tags, 0, 100); // only sample fist 100
        } else {
            $this->helper->debugLog("Identity block $name contains $cnt tags. detail: " . implode(', ', $tags));
        }
        $this->addCacheTags($tags);
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
