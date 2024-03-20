<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class FlushCacheByCli implements \Magento\Framework\Event\ObserverInterface
{

	/**
	 * @var \Litespeed\Litemage\Model\Config
	 */
	protected $config;

	/**
	 * @var \Litespeed\Litemage\Model
	 */
	protected $cachePurge;

	/**
	 * @var \Magento\Framework\Url
	 */
	protected $url;
	/**
	 *
	 * @var string
	 */
	private $_reason;

	/**
	 *
	 * @var bool
	 */
	private $enabled;
    
    private $curl_options;
    
    private const BATCH_SIZE = 60;
    
	/**
	 * @param \Litespeed\Litemage\Model\Config $config,
	 * @param \Magento\Framework\Url $url,
	 * @param \Litespeed\Litemage\Model\CachePurge $cachePurge
	 * @throws \Magento\Framework\Exception\IntegrationException
	 */
	public function __construct(\Litespeed\Litemage\Model\Config $config,
			\Magento\Framework\Url $url,
			\Litespeed\Litemage\Model\CachePurge $cachePurge)
	{
		if (PHP_SAPI !== 'cli') {
			throw new \Magento\Framework\Exception\IntegrationException('Should only invoke from command line');
		}
		$this->config = $config;
		$this->url = $url;
		$this->cachePurge = $cachePurge;
		$this->enabled = $this->config->moduleEnabled() && !$this->config->isCliPurgeDisabled();
	}

    /**
     * Only send purge request during destruct, allow bulk purge
     */
    function __destruct()
    {
        $this->sendPurgeRequest();
    }
    
	/**
	 * Flush All Litemage cache
	 * @param \Magento\Framework\Event\Observer $observer
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		if (!$this->enabled) {
			return;
		}

		$event = $observer->getEvent();
        $this->cachePurge->addPurgeTags($event->getTags(), 'CLI ' . $event->getReason());
        if ($this->cachePurge->getPurgeTagsCount() >= self::BATCH_SIZE) {
            $this->sendPurgeRequest();
        }
        // remaining will be handled by destructor
	}
    
    private function sendPurgeRequest()
    {
        while ($this->cachePurge->getPurgeTagsCount() >= self::BATCH_SIZE) {
            $tags = $this->cachePurge->grabPurgeTags(self::BATCH_SIZE);
    		$list = array_chunk($tags, self::BATCH_SIZE); // split to avoid url too long
            foreach ($list as $l) {
                $this->_shellPurge(['tags' => implode(',', $l)]);
            }
		}
    }

	private function _shellPurge($params)
	{
		$msg = 'FlushCacheByCli ';
		try {
            $options = $this->getCurlOptions($params);
			$msg .= sprintf('%s url=%s ', $this->_reason, $options[CURLOPT_URL]);
			$result = 'OK';

			$ch = curl_init();
			// cannot use POST due to csrf validation
			curl_setopt_array($ch, $options);

			$data = curl_exec($ch);
			if (!curl_errno($ch)) {
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if ($http_code == 200) {
					$result = 'OK' . "\n$data";
				} else {
					$result = ' Unexpected HTTP code: ' . $http_code;
					if ($http_code == 503) {
						if (strpos($data, 'maintenance')) {
							$result .= "\n*** Failed to send LiteMage flush request, please add your backend server IP to maintenance allowed ips.";
						}
					} elseif ($http_code == 401) {
						$result .= "\n*** If you have Basic Authentication enabled, make sure it is properly set in LiteMage config - Developer Settings - Basic Authentication.";
					}
				}
			} else {
				$result = curl_error($ch);
			}
			curl_close($ch);
			$msg .= 'res=' . $result;
		} catch (\Exception $e) {
			$result = $e->getMessage();
			$msg .= 'Exception ' . $result;
		}

		$this->cachePurge->debugLog($msg);
		$this->cachePurge->debugTrace('shellpurge');
		echo "Flush LiteMage Cache by CLI - $result \n";
	}
    
    private function getCurlOptions($params)
    {
        if ($this->curl_options == null) {
			// cannot use POST due to csrf validation
			$options = [CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => false,
				CURLOPT_TIMEOUT => 180,
				CURLOPT_USERAGENT => 'litemage_walker',
			];

            $server_ip = $this->config->getServerIp();
            $storeId = $this->config->getFrontStoreId();
            $base = $this->url->getUrl('litemage/shell/purge', ['_scope' => $storeId, '_nosid' => true]);
            if ($server_ip) {
                $pattern = "/:\/\/([^\/^:]+)(\/|:)?/";
                if (preg_match($pattern, $base, $m)) {
                    $domain = $m[1];
                    $pos = strpos($base, $domain);
                    $base = substr($base, 0, $pos) . $server_ip
                            . substr($base, $pos + strlen($domain));
    				$options[CURLOPT_HTTPHEADER] = ["Host: $domain"];
                }
            }
            
            $options[CURLOPT_URL] = $base;
            
			if (strpos($base, 'https://') !== false) {
				$options[CURLOPT_SSL_VERIFYHOST] = 0;
				$options[CURLOPT_SSL_VERIFYPEER] = 0;
				if (defined('CURLOPT_SSL_VERIFYSTATUS')) { // not avail in old lib
					$options[CURLOPT_SSL_VERIFYSTATUS] = false;
				}
			}
            
			$auth = $this->config->getBasicAuth();
			if ($auth) {
				$options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
				$options[CURLOPT_USERPWD] = $auth;
			}

            $this->curl_options = $options;
        } else {
            $options = $this->curl_options;
        }
        
        $stat = stat(__FILE__);
        $stat[] = date('l jS F Y h');
        $params['secret'] = md5(print_r($stat, true));
        $base = $this->curl_options[CURLOPT_URL];
        
        //$uri = $base . 'litemage/shell/purge?' . http_build_query($params);
        $uri = $base . '?' . http_build_query($params);
        
        $options[CURLOPT_URL] = $uri; // each time uri is different
        return $options;
    }

}
