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
	 * @var \Litespeed\Litemage\Helper\Data
	 */
	protected $helper;

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
	 * Core registry
	 *
	 * @var \Magento\Framework\Registry
	 */
	protected $coreRegistry;
	/**
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * @param \Litespeed\Litemage\Model\Config $config,
	 * @param \Magento\Framework\Registry $coreRegistry,
	 * @param \Magento\Store\Model\StoreManagerInterface $storeManager,
	 * @param \Magento\Framework\Url $url,
	 * @param \Litespeed\Litemage\Helper\Data $helper
	 * @throws \Magento\Framework\Exception\IntegrationException
	 */
	public function __construct(\Litespeed\Litemage\Model\Config $config,
			\Magento\Framework\Registry $coreRegistry,
			\Magento\Framework\Url $url,
			\Litespeed\Litemage\Helper\Data $helper)
	{
		if (PHP_SAPI !== 'cli') {
			throw new \Magento\Framework\Exception\IntegrationException('Should only invoke from command line');
		}
		$this->config = $config;
		$this->coreRegistry = $coreRegistry;
		$this->url = $url;
		$this->helper = $helper;
		$this->enabled = $this->config->moduleEnabled();
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
		$tags = $event->getTags();
		$this->_reason = $event->getReason();

		if (in_array('*', $tags)) {
			if ($this->coreRegistry->registry('shellPurgeAll') === null) {
				$this->coreRegistry->register('shellPurgeAll', 1);
				$this->_shellPurge(['all' => 1]);
			}
		} else {
			$tags = array_unique($tags);
			$used = [];
			foreach ($tags as $tag) {
				if ($this->coreRegistry->registry("shellPurge_{$tag}") === null) {
					$this->coreRegistry->register("shellPurge_{$tag}", 1);
					$used[] = $tag;
				}
			}
			if (!empty($used)) {
				$list = array_chunk($used, 50); // split to avoid url too long
				foreach ($list as $l) {
					$this->_shellPurge(['tags' => implode(',', $l)]);
				}
			}
		}
	}

	private function _shellPurge($params)
	{
		$msg = 'FlushCacheByCli ';
		try {
			$server_ip = $this->config->getServerIp();
			$storeId = $this->config->getFrontStoreId();
			$base = $this->url->getUrl('litemage/shell/purge', ['_scope' => $storeId, '_nosid' => true]);
			$headers = [];
			if ($server_ip) {
				$pattern = "/:\/\/([^\/^:]+)(\/|:)?/";
				if (preg_match($pattern, $base, $m)) {
					$domain = $m[1];
					$pos = strpos($base, $domain);
					$base = substr($base, 0, $pos) . $server_ip
							. substr($base, $pos + strlen($domain));
					$headers[] = "Host: $domain";
				}
			}
			$stat = stat(__FILE__);
			$stat[] = date('l jS F Y h');
			$params['secret'] = md5(print_r($stat, true));
			//$uri = $base . 'litemage/shell/purge?' . http_build_query($params);
			$uri = $base . '?' . http_build_query($params);
			$msg .= sprintf('%s url=%s ', $this->_reason, $uri);

			$result = 'OK';


			$ch = curl_init();
			// cannot use POST due to csrf validation
			$options = [CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_URL => $uri,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => false,
				CURLOPT_TIMEOUT => 180,
				CURLOPT_USERAGENT => 'litemage_walker',
			];
			if (strpos($uri, 'https://') !== false) {
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

			curl_setopt_array($ch, $options);
			if (!empty($headers)) {
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			}

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

		$this->helper->debugLog($msg);
		$this->helper->debugTrace('shellpurge');
		echo "Flush LiteMage Cache by cli - $result \n";
	}

}
