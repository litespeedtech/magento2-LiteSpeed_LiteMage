<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

use Magento\Customer\Model\Context as CustomerContext;
use Litespeed\Litemage\Model\Warmup\CrawlerMode;

/**
 * Class Config
 *
 */
class Config
{
    private const WARMUP_GENERATION_SOURCES = ['sitemap', 'url_rewrite', 'text_file', 'recently_seen'];

    public const WARMUP_PRIORITY_MIN = 0;
    public const WARMUP_PRIORITY_MAX = 9999;
    public const WARMUP_PRIORITY_TEXT_FILE_DEFAULT = 20;
    public const WARMUP_PRIORITY_PURGE_ENTITY_DEFAULT = 10;
    public const WARMUP_PRIORITY_SITEMAP_DEFAULT = 100;
    public const WARMUP_PRIORITY_URL_REWRITE_DEFAULT = 200;
    public const WARMUP_PRIORITY_RECENTLY_SEEN_DEFAULT = 300;

    /**
     * Cache types, it requires INT value
     */
    const LITEMAGE = 168;

    private const CFGXML_DEFAULTLM = 'litemage' ;

	private const STOREXML_PUBLICTTL = 'system/full_page_cache/ttl';

	// general settings
    private const CFG_CONTEXTBYPASS = 'contextbypass';
    private const CFG_CUSTOMVARY = 'custom_vary';
    private const CFG_IGNORED_BLOCKS = 'ignored_blocks';
    private const CFG_IGNORED_TAGS = 'ignored_tags';

	// purge settings
	private const CFG_PROD_EDIT_NO_PURGE_CATS = 'prod_edit_no_purge_cats';
	private const CFG_PURGE_PROD_AFTER_ORDER = 'purge_prod_after_order';
	private const CFG_PURGE_PARENT_PROD_AFTER_ORDER = 'purge_parent_prod_after_order';
	private const CFG_IGNORED_PURGE_TAGS = 'ignored_purge_tags';
    private const CFG_DISABLE_CLI_PURGE = 'disable_cli_purge';

    // warmup settings
    private const CFG_WARMUP_ENABLED = 'enabled';
    private const CFG_WARMUP_SOURCES = 'sources';
    private const CFG_WARMUP_CRON_SCHEDULE = 'cron_schedule';
    private const CFG_WARMUP_PROCESS_CRON_SCHEDULE = 'process_cron_schedule';
    private const CFG_WARMUP_GENERATE_CRON_SCHEDULE = 'generate_cron_schedule';
    private const CFG_WARMUP_BATCH_SIZE = 'batch_size';
    private const CFG_WARMUP_CONCURRENCY = 'concurrency';
    private const CFG_WARMUP_REQUEST_TIMEOUT = 'request_timeout';
    private const CFG_WARMUP_CRAWL_DELAY_MS = 'crawl_delay_ms';
    private const CFG_WARMUP_MAX_RUNTIME = 'max_runtime';
    private const CFG_WARMUP_MAX_LOAD_AVERAGE = 'max_load_average';
    private const CFG_WARMUP_MAX_ATTEMPTS = 'max_attempts';
    private const CFG_WARMUP_QUEUE_LIMIT_PER_STORE = 'queue_limit_per_store';
    private const CFG_WARMUP_RECRAWL_INTERVAL_SECONDS = 'recrawl_interval_seconds';
    private const CFG_WARMUP_RESULT_RETENTION_DAYS = 'result_retention_days';
    private const CFG_WARMUP_DEFAULT_FULL_MODE = 'default_full_mode';
    private const CFG_WARMUP_DELTA_ENABLED = 'delta_enabled';
    private const CFG_WARMUP_SITEMAP_PATHS = 'sitemap_paths';
    private const CFG_WARMUP_TEXT_FILE_PATHS = 'text_file_paths';
    private const CFG_WARMUP_TEXT_FILE_SOURCE_PRIORITY = 'text_file_source_priority';
    private const CFG_WARMUP_SITEMAP_SOURCE_PRIORITY = 'sitemap_source_priority';
    private const CFG_WARMUP_URL_REWRITE_SOURCE_PRIORITY = 'url_rewrite_source_priority';
    private const CFG_WARMUP_PURGE_ENTITY_SOURCE_PRIORITY = 'purge_entity_source_priority';
    private const CFG_WARMUP_ALLOWED_QUERY_PARAMS = 'allowed_query_params';
    private const CFG_WARMUP_URL_REWRITE_ENTITY_TYPES = 'url_rewrite_entity_types';
    private const CFG_WARMUP_CURRENCY_CODES = 'currency_codes';
    private const CFG_WARMUP_CUSTOMER_IDS = 'customer_ids';
    private const CFG_WARMUP_PROFILE_LIMIT = 'profile_limit';
    private const CFG_WARMUP_REVERSE_INDEX_ENABLED = 'reverse_index_enabled';
    private const CFG_WARMUP_RECENTLY_SEEN_ENABLED = 'recently_seen_enabled';
    private const CFG_WARMUP_RECENTLY_SEEN_LIMIT = 'recently_seen_limit';
    private const CFG_WARMUP_RECENTLY_SEEN_SOURCE_PRIORITY = 'recently_seen_source_priority';
    private const CFG_WARMUP_REVERSE_INDEX_MAX_TAGS_PER_URL = 'reverse_index_max_tags_per_url';
    private const CFG_WARMUP_REVERSE_INDEX_MAX_URLS_PER_TAG = 'reverse_index_max_urls_per_tag';
    private const CFG_WARMUP_REVERSE_INDEX_TTL_DAYS = 'reverse_index_ttl_days';

	// dev
	private const CFG_DEBUGON = 'debug' ;
    private const CFG_FRONT_STORE_ID = 'frontend_store_id';
    private const CFG_SERVER_IP = 'server_ip';
	private const CFG_BASIC_AUTH = 'basic_auth';
    private const PROTECTED_VARY_CONTEXT = [
        CustomerContext::CONTEXT_AUTH,
        CustomerContext::CONTEXT_GROUP,
    ];

    //const CFG_ADMINIPS = 'admin_ips';
    private const CFG_PUBLICTTL = 'public_ttl';
    private const LITEMAGE_GENERAL_CACHE_TAG = 'LITESPEED_LITEMAGE' ;

    // config items
    protected $_conf = [];
    protected $_userModuleEnabled = -2 ; // -2: not set, true, false
    protected $_esiTag;
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

    /**
     *
     * @var \Magento\PageCache\Model\Config
     */
    protected $pagecacheConfig;

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
        $this->pagecacheConfig = $pagecacheConfig;

		if ($this->licenseEnabled()) {
            $this->_moduleStatus |= 1;
		}
        if ($pagecacheConfig->isEnabled()) {
			$this->_moduleStatus |= 2;
		}
        if ( $pagecacheConfig->getType() == self::LITEMAGE) {
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

    public function getModuleStatus()
    {
        return $this->pagecacheConfig->getType() . ':' . $this->_moduleStatus;
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
        return isset($_SERVER['X-LITEMAGE']) && $_SERVER['X-LITEMAGE'];
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
            'catalog_product_view_type_configurable' => 'LPVTC',
            'catalog_category_view' => 'LCV',
            'catalog_category_view_type_default' => 'LCVTD',
            'catalog_category_view_type_layered' => 'LCVTL',
            'catalog_category_view_type_layered_without_children' => 'LCVTLOC',
            'cms_page_view' => 'MPV',
            'cms_index_index_id_home' => 'MIIIH',
            'cms_noroute_index' => 'MNI',
            'cms_noroute_index_id_no-route' => 'MNIINR',
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
    
    public function filterPurgeTags($rawtags)
    {
        // can make it configurable in future. these tags will never has a cache tag, waste to store for purge tags.
        $ignored = [
            'compare_item',
            'wishlist',
        ];
        $tags = [];
        foreach ($rawtags as $tag) {
            foreach ($ignored as $i) {
                if (strpos($tag, $i) !== false) {
                    continue 2;
                }
            }
            $tags[] = $tag;
        }
        return $tags;
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
            $this->_initConf() ;
        }

        if ( $type == '' ) {
            return $this->_conf[$name] ;
		} elseif ( $name == '' ) {
            return $this->_conf[$type] ;
		} else {
            return $this->_conf[$type][$name] ;
		}
    }

    public function getBypassedContext()
    {
        return array_diff($this->getConf(self::CFG_CONTEXTBYPASS), self::PROTECTED_VARY_CONTEXT);
    }

    public function getIgnoredTags()
    {
        return array_diff($this->getConf(self::CFG_IGNORED_TAGS), ['MB','store']);
    }

    public function getIgnoredBlocks()
    {
        return $this->getConf(self::CFG_IGNORED_BLOCKS);
    }

    public function getIgnoredPurgeTags()
    {
        return $this->getConf(self::CFG_IGNORED_PURGE_TAGS);
    }
    
    public function isCliPurgeDisabled()
    {
        return ($this->getConf(self::CFG_DISABLE_CLI_PURGE) == 1);
    }

    public function getProdEditNoPurgeCats()
    {
        return $this->getConf(self::CFG_PROD_EDIT_NO_PURGE_CATS);
    }

    public function getPurgeProdAfterOrder()
    {
        return $this->getConf(self::CFG_PURGE_PROD_AFTER_ORDER);
    }

    public function getCustomVaryMode()
    {
        return $this->getConf(self::CFG_CUSTOMVARY);
    }

    public function isWarmupEnabled()
    {
        return ($this->getConf(self::CFG_WARMUP_ENABLED) == 1);
    }

    public function getWarmupSources()
    {
        $sources = array_values(array_intersect($this->getConf(self::CFG_WARMUP_SOURCES), self::WARMUP_GENERATION_SOURCES));
        if ($this->isWarmupRecentlySeenEnabled()) {
            $sources[] = 'recently_seen';
        }
        return array_values(array_unique($sources));
    }

    public function getWarmupCronSchedule()
    {
        return $this->getWarmupProcessCronSchedule();
    }

    public function getWarmupProcessCronSchedule()
    {
        return $this->getConf(self::CFG_WARMUP_PROCESS_CRON_SCHEDULE);
    }

    public function getWarmupGenerateCronSchedule()
    {
        return $this->getConf(self::CFG_WARMUP_GENERATE_CRON_SCHEDULE);
    }

    public function getWarmupBatchSize()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_BATCH_SIZE, 50, 1, 1000);
    }

    public function getWarmupConcurrency()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_CONCURRENCY, 2, 1, 32);
    }

    public function getWarmupRequestTimeout()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_REQUEST_TIMEOUT, 30, 1, 600);
    }

    public function getWarmupCrawlDelayMs()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_CRAWL_DELAY_MS, 250, 0, 60000);
    }

    public function getWarmupMaxRuntime()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_MAX_RUNTIME, 240, 1, 86400);
    }

    public function getWarmupMaxLoadAverage()
    {
        return $this->getWarmupFloatConf(self::CFG_WARMUP_MAX_LOAD_AVERAGE, 0.0, 0.0, 100000.0);
    }

    public function getWarmupMaxAttempts()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_MAX_ATTEMPTS, 3, 1, 100);
    }

    public function getWarmupQueueLimitPerStore()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_QUEUE_LIMIT_PER_STORE, 10000, 1, 10000000);
    }

    public function getWarmupRecrawlIntervalSeconds()
    {
        $configured = trim((string)$this->getConf(self::CFG_WARMUP_RECRAWL_INTERVAL_SECONDS));
        if ($configured !== '') {
            $interval = (int)$configured;
            if ($interval < 0) {
                return 0;
            }
            if ($interval > 0) {
                return min(31536000, max(300, $interval));
            }
        }

        $ttl = (int)$this->getTtl();
        if ($ttl <= 0) {
            return 0;
        }

        if ($this->getWarmupDefaultFullMode() === CrawlerMode::MODE_RUNNER) {
            return max(300, $ttl);
        }

        $leadSeconds = min(3600, max(300, (int)ceil($ttl * 0.1)));
        return max(300, $ttl - $leadSeconds);
    }

    public function getWarmupResultRetentionDays()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_RESULT_RETENTION_DAYS, 30, 1, 3650);
    }

    public function getWarmupDefaultFullMode()
    {
        return $this->getConf(self::CFG_WARMUP_DEFAULT_FULL_MODE);
    }

    public function getWarmupDefaultDeltaMode()
    {
        return CrawlerMode::MODE_RUNNER;
    }

    public function isWarmupDeltaEnabled()
    {
        return ($this->getConf(self::CFG_WARMUP_DELTA_ENABLED) == 1);
    }

    public function getWarmupSitemapPaths()
    {
        return $this->getConf(self::CFG_WARMUP_SITEMAP_PATHS);
    }

    public function getWarmupTextFilePaths()
    {
        return $this->getConf(self::CFG_WARMUP_TEXT_FILE_PATHS);
    }

    public function getWarmupTextFileSourcePriority()
    {
        return $this->getWarmupPriorityConf(
            self::CFG_WARMUP_TEXT_FILE_SOURCE_PRIORITY,
            self::WARMUP_PRIORITY_TEXT_FILE_DEFAULT
        );
    }

    public function getWarmupSitemapSourcePriority()
    {
        return $this->getWarmupPriorityConf(
            self::CFG_WARMUP_SITEMAP_SOURCE_PRIORITY,
            self::WARMUP_PRIORITY_SITEMAP_DEFAULT
        );
    }

    public function getWarmupUrlRewriteSourcePriority()
    {
        return $this->getWarmupPriorityConf(
            self::CFG_WARMUP_URL_REWRITE_SOURCE_PRIORITY,
            self::WARMUP_PRIORITY_URL_REWRITE_DEFAULT
        );
    }

    public function getWarmupPurgeEntitySourcePriority()
    {
        return $this->getWarmupPriorityConf(
            self::CFG_WARMUP_PURGE_ENTITY_SOURCE_PRIORITY,
            self::WARMUP_PRIORITY_PURGE_ENTITY_DEFAULT
        );
    }

    public function getWarmupSourcePriority($source)
    {
        switch ((string)$source) {
            case 'text_file':
                return $this->getWarmupTextFileSourcePriority();
            case 'sitemap':
                return $this->getWarmupSitemapSourcePriority();
            case 'url_rewrite':
                return $this->getWarmupUrlRewriteSourcePriority();
            case 'recently_seen':
                return $this->getWarmupRecentlySeenSourcePriority();
            case 'purge_entity':
            case 'purge':
            case 'purge_reverse_index':
                return $this->getWarmupPurgeEntitySourcePriority();
        }

        return 100;
    }

    public function getWarmupAllowedQueryParams()
    {
        return array_values(array_filter(array_map('trim', $this->getConf(self::CFG_WARMUP_ALLOWED_QUERY_PARAMS))));
    }

    public function getWarmupUrlRewriteEntityTypes()
    {
        $types = $this->getConf(self::CFG_WARMUP_URL_REWRITE_ENTITY_TYPES);
        return $types ?: ['product', 'category', 'cms-page'];
    }

    public function getWarmupCurrencyCodes()
    {
        return array_values(array_filter(array_map('strtoupper', $this->getConf(self::CFG_WARMUP_CURRENCY_CODES))));
    }

    public function getWarmupCustomerIds()
    {
        $ids = [];
        foreach ($this->getConf(self::CFG_WARMUP_CUSTOMER_IDS) as $customerId) {
            $customerId = (int)$customerId;
            if ($customerId > 0) {
                $ids[$customerId] = $customerId;
            }
        }
        return array_values($ids);
    }

    public function getWarmupProfileLimit()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_PROFILE_LIMIT, 10, 1, 100);
    }

    public function isWarmupReverseIndexEnabled()
    {
        return ($this->getConf(self::CFG_WARMUP_REVERSE_INDEX_ENABLED) == 1);
    }

    public function isWarmupRecentlySeenEnabled()
    {
        return $this->isWarmupReverseIndexEnabled()
            && ($this->getConf(self::CFG_WARMUP_RECENTLY_SEEN_ENABLED) == 1);
    }

    public function getWarmupRecentlySeenLimit()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_RECENTLY_SEEN_LIMIT, 1000, 1, 100000);
    }

    public function getWarmupRecentlySeenSourcePriority()
    {
        return $this->getWarmupPriorityConf(
            self::CFG_WARMUP_RECENTLY_SEEN_SOURCE_PRIORITY,
            self::WARMUP_PRIORITY_RECENTLY_SEEN_DEFAULT
        );
    }

    public function getWarmupReverseIndexMaxTagsPerUrl()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_REVERSE_INDEX_MAX_TAGS_PER_URL, 20, 1, 1000);
    }

    public function getWarmupReverseIndexMaxUrlsPerTag()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_REVERSE_INDEX_MAX_URLS_PER_TAG, 500, 1, 100000);
    }

    public function getWarmupReverseIndexTtlDays()
    {
        return $this->getWarmupIntConf(self::CFG_WARMUP_REVERSE_INDEX_TTL_DAYS, 7, 1, 365);
    }

    public function getFrontStoreId()
    {
        return $this->getConf(self::CFG_FRONT_STORE_ID);
    }

    public function getServerIp()
    {
        return $this->getConf(self::CFG_SERVER_IP);
    }

	public function getBasicAuth()
	{
		$auth = $this->getConf(self::CFG_BASIC_AUTH);
		if ($auth) {
			if (!strpos($auth, ':')) {
				throw new \Exception("Invalid Basic Authentication format. Please use \"user:password\" format in LiteMage config - Developer Settings.");
			}
		}
		return $auth;
	}

    protected function _initConf()
    {
        if ( isset($this->_conf['defaultlm']) ) {
            return;
        }
        $this->_conf['defaultlm'] = $this->scopeConfig->getValue(self::CFGXML_DEFAULTLM) ;
        $lm = $this->_conf['defaultlm'];
        $pattern = "/[\s,]+/" ;

        $debugon = $lm['dev'][self::CFG_DEBUGON] ?? 0;
        if ($debugon && isset($lm['dev']['debug_ips'])) {
            // check ips
            $debugips = trim($lm['dev']['debug_ips']);
            if (PHP_SAPI !== 'cli' && $debugips) {
                $ips = array_unique(preg_split($pattern, $debugips, 0, PREG_SPLIT_NO_EMPTY));
                if (!empty($ips)) {
                    $ip = $this->_getIp();
                    if (!in_array($ip, $ips)) {
                        $debugon = 0;
                    }
                }
            }
        }

        $this->_conf[self::CFG_DEBUGON] = $debugon ;
        if ($debugon) {
            $this->_debug_trace = $lm['dev']['debug_trace'] ?? 0;
        }

        $this->_conf[self::CFG_FRONT_STORE_ID] = $lm['dev'][self::CFG_FRONT_STORE_ID] ?? 1; // default is store 1
        $this->_conf[self::CFG_SERVER_IP] = $lm['dev'][self::CFG_SERVER_IP] ?? '';
        $this->_conf[self::CFG_BASIC_AUTH] = $lm['dev'][self::CFG_BASIC_AUTH] ?? '';
        $this->_conf[self::CFG_PUBLICTTL] = $this->scopeConfig->getValue(self::STOREXML_PUBLICTTL);

        $this->load_conf_field_array(self::CFG_CONTEXTBYPASS, $lm['general']);
        $this->load_conf_field_array(self::CFG_IGNORED_BLOCKS, $lm['general']);
        $this->load_conf_field_array(self::CFG_IGNORED_TAGS, $lm['general']);

        $this->_conf[self::CFG_PROD_EDIT_NO_PURGE_CATS] = $lm['purge'][self::CFG_PROD_EDIT_NO_PURGE_CATS] ?? 0;
        $purge_prod = $lm['purge'][self::CFG_PURGE_PROD_AFTER_ORDER] ?? 1; // 0:no, 1:when out of stock, 2: always
        $purge_parent = $lm['purge'][self::CFG_PURGE_PARENT_PROD_AFTER_ORDER] ?? 0;
        if ($purge_prod && $purge_parent) {
            $purge_prod |= 4;
        }
        $this->_conf[self::CFG_PURGE_PROD_AFTER_ORDER] = $purge_prod;

        $this->load_conf_field_array(self::CFG_IGNORED_PURGE_TAGS, $lm['purge'] ?? []);
        $this->_conf[self::CFG_DISABLE_CLI_PURGE] = $lm['purge'][self::CFG_DISABLE_CLI_PURGE] ?? 0;

        $warmup = $lm['warmup'] ?? [];
        $this->_conf[self::CFG_WARMUP_ENABLED] = $warmup[self::CFG_WARMUP_ENABLED] ?? 0;
        $this->load_conf_field_array(self::CFG_WARMUP_SOURCES, $warmup);
        $this->_conf[self::CFG_WARMUP_CRON_SCHEDULE] = $warmup[self::CFG_WARMUP_CRON_SCHEDULE] ?? '*/5 * * * *';
        $this->_conf[self::CFG_WARMUP_PROCESS_CRON_SCHEDULE] = $warmup[self::CFG_WARMUP_PROCESS_CRON_SCHEDULE]
            ?? $this->_conf[self::CFG_WARMUP_CRON_SCHEDULE];
        $this->_conf[self::CFG_WARMUP_GENERATE_CRON_SCHEDULE] = $warmup[self::CFG_WARMUP_GENERATE_CRON_SCHEDULE]
            ?? '0 3 * * *';
        $this->_conf[self::CFG_WARMUP_BATCH_SIZE] = $warmup[self::CFG_WARMUP_BATCH_SIZE] ?? 50;
        $this->_conf[self::CFG_WARMUP_CONCURRENCY] = $warmup[self::CFG_WARMUP_CONCURRENCY] ?? 2;
        $this->_conf[self::CFG_WARMUP_REQUEST_TIMEOUT] = $warmup[self::CFG_WARMUP_REQUEST_TIMEOUT] ?? 30;
        $this->_conf[self::CFG_WARMUP_CRAWL_DELAY_MS] = $warmup[self::CFG_WARMUP_CRAWL_DELAY_MS] ?? 250;
        $this->_conf[self::CFG_WARMUP_MAX_RUNTIME] = $warmup[self::CFG_WARMUP_MAX_RUNTIME] ?? 240;
        $this->_conf[self::CFG_WARMUP_MAX_LOAD_AVERAGE] = $warmup[self::CFG_WARMUP_MAX_LOAD_AVERAGE] ?? 0;
        $this->_conf[self::CFG_WARMUP_MAX_ATTEMPTS] = $warmup[self::CFG_WARMUP_MAX_ATTEMPTS] ?? 3;
        $this->_conf[self::CFG_WARMUP_QUEUE_LIMIT_PER_STORE] = $warmup[self::CFG_WARMUP_QUEUE_LIMIT_PER_STORE] ?? 10000;
        $this->_conf[self::CFG_WARMUP_RECRAWL_INTERVAL_SECONDS] = $warmup[self::CFG_WARMUP_RECRAWL_INTERVAL_SECONDS] ?? 0;
        $this->_conf[self::CFG_WARMUP_RESULT_RETENTION_DAYS] = $warmup[self::CFG_WARMUP_RESULT_RETENTION_DAYS] ?? 30;
        $this->_conf[self::CFG_WARMUP_DEFAULT_FULL_MODE] = $warmup[self::CFG_WARMUP_DEFAULT_FULL_MODE] ?? CrawlerMode::MODE_RUNNER;
        $this->_conf[self::CFG_WARMUP_DELTA_ENABLED] = $warmup[self::CFG_WARMUP_DELTA_ENABLED] ?? 1;
        $this->load_conf_field_lines(self::CFG_WARMUP_SITEMAP_PATHS, $warmup);
        $this->load_conf_field_lines(self::CFG_WARMUP_TEXT_FILE_PATHS, $warmup);
        $this->_conf[self::CFG_WARMUP_TEXT_FILE_SOURCE_PRIORITY] = $warmup[self::CFG_WARMUP_TEXT_FILE_SOURCE_PRIORITY]
            ?? self::WARMUP_PRIORITY_TEXT_FILE_DEFAULT;
        $this->_conf[self::CFG_WARMUP_SITEMAP_SOURCE_PRIORITY] = $warmup[self::CFG_WARMUP_SITEMAP_SOURCE_PRIORITY]
            ?? self::WARMUP_PRIORITY_SITEMAP_DEFAULT;
        $this->_conf[self::CFG_WARMUP_URL_REWRITE_SOURCE_PRIORITY] = $warmup[self::CFG_WARMUP_URL_REWRITE_SOURCE_PRIORITY]
            ?? self::WARMUP_PRIORITY_URL_REWRITE_DEFAULT;
        $this->_conf[self::CFG_WARMUP_PURGE_ENTITY_SOURCE_PRIORITY] = $warmup[self::CFG_WARMUP_PURGE_ENTITY_SOURCE_PRIORITY]
            ?? self::WARMUP_PRIORITY_PURGE_ENTITY_DEFAULT;
        $this->load_conf_field_array(self::CFG_WARMUP_ALLOWED_QUERY_PARAMS, $warmup);
        $this->load_conf_field_array(self::CFG_WARMUP_URL_REWRITE_ENTITY_TYPES, $warmup);
        $this->load_conf_field_array(self::CFG_WARMUP_CURRENCY_CODES, $warmup);
        $this->load_conf_field_array(self::CFG_WARMUP_CUSTOMER_IDS, $warmup);
        $this->_conf[self::CFG_WARMUP_PROFILE_LIMIT] = $warmup[self::CFG_WARMUP_PROFILE_LIMIT] ?? 10;
        $this->_conf[self::CFG_WARMUP_REVERSE_INDEX_ENABLED] = $warmup[self::CFG_WARMUP_REVERSE_INDEX_ENABLED] ?? 0;
        $this->_conf[self::CFG_WARMUP_RECENTLY_SEEN_ENABLED] = $warmup[self::CFG_WARMUP_RECENTLY_SEEN_ENABLED] ?? 0;
        $this->_conf[self::CFG_WARMUP_RECENTLY_SEEN_LIMIT] = $warmup[self::CFG_WARMUP_RECENTLY_SEEN_LIMIT] ?? 1000;
        $this->_conf[self::CFG_WARMUP_RECENTLY_SEEN_SOURCE_PRIORITY] = $warmup[self::CFG_WARMUP_RECENTLY_SEEN_SOURCE_PRIORITY]
            ?? self::WARMUP_PRIORITY_RECENTLY_SEEN_DEFAULT;
        $this->_conf[self::CFG_WARMUP_REVERSE_INDEX_MAX_TAGS_PER_URL] = $warmup[self::CFG_WARMUP_REVERSE_INDEX_MAX_TAGS_PER_URL] ?? 20;
        $this->_conf[self::CFG_WARMUP_REVERSE_INDEX_MAX_URLS_PER_TAG] = $warmup[self::CFG_WARMUP_REVERSE_INDEX_MAX_URLS_PER_TAG] ?? 500;
        $this->_conf[self::CFG_WARMUP_REVERSE_INDEX_TTL_DAYS] = $warmup[self::CFG_WARMUP_REVERSE_INDEX_TTL_DAYS] ?? 7;

        $this->_conf[self::CFG_CUSTOMVARY] = $lm['general'][self::CFG_CUSTOMVARY] ?? 0;
        $this->_esiTag = array('include' => 'esi:include', 'inline' => 'esi:inline', 'remove' => 'esi:remove');
    }

    private function load_conf_field_array($field_name, $holder)
    {
        $value = $holder[$field_name] ?? '';
        if ($value) {
            $this->_conf[$field_name] = array_unique(preg_split("/[\s,]+/", $value, 0, PREG_SPLIT_NO_EMPTY));
        } else {
            $this->_conf[$field_name] = [];
        }
    }

    private function load_conf_field_lines($field_name, $holder)
    {
        $value = $holder[$field_name] ?? '';
        if ($value) {
            $this->_conf[$field_name] = array_values(array_unique(array_filter(array_map('trim', preg_split("/\\r\\n|\\r|\\n/", $value)))));
        } else {
            $this->_conf[$field_name] = [];
        }
    }

    private function getWarmupIntConf($name, $default, $min, $max)
    {
        $value = (int)$this->getConf($name);
        if ($value < $min || $value > $max) {
            return $default;
        }
        return $value;
    }

    private function getWarmupFloatConf($name, $default, $min, $max)
    {
        $value = (float)$this->getConf($name);
        if ($value < $min || $value > $max) {
            return $default;
        }
        return $value;
    }

    private function getWarmupPriorityConf($name, $default)
    {
        return $this->getWarmupIntConf($name, $default, self::WARMUP_PRIORITY_MIN, self::WARMUP_PRIORITY_MAX);
    }

    protected function _getIp()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

}
