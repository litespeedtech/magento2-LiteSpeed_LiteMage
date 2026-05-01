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

	private $purgeBaseUrl;
	private $hostHeader;

	/**
	 * @var \Litespeed\Litemage\Model\ShellPurgeAuth
	 */
	private $shellPurgeAuth;

	/**
	 * @var \Magento\Framework\HTTP\Client\CurlFactory
	 */
	private $curlFactory;

	private const BATCH_SIZE = 60;
	private const ERROR_BODY_LIMIT = 1024;

	/**
	 * @param \Litespeed\Litemage\Model\Config $config,
	 * @param \Magento\Framework\Url $url,
	 * @param \Litespeed\Litemage\Model\CachePurge $cachePurge
	 * @param \Litespeed\Litemage\Model\ShellPurgeAuth $shellPurgeAuth
	 * @param \Magento\Framework\HTTP\Client\CurlFactory $curlFactory
	 * @throws \Magento\Framework\Exception\IntegrationException
	 */
	public function __construct(\Litespeed\Litemage\Model\Config $config,
			\Magento\Framework\Url $url,
			\Litespeed\Litemage\Model\CachePurge $cachePurge,
			\Litespeed\Litemage\Model\ShellPurgeAuth $shellPurgeAuth,
			\Magento\Framework\HTTP\Client\CurlFactory $curlFactory)
	{
		if (PHP_SAPI !== 'cli') {
			throw new \Magento\Framework\Exception\IntegrationException('Should only invoke from command line');
		}
		$this->config = $config;
		$this->url = $url;
		$this->cachePurge = $cachePurge;
		$this->shellPurgeAuth = $shellPurgeAuth;
		$this->curlFactory = $curlFactory;
		$this->enabled = $this->config->moduleEnabled() && !$this->config->isCliPurgeDisabled();
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
		$this->sendPurgeRequest();
	}

	private function sendPurgeRequest()
	{
		while (($count = $this->cachePurge->getPurgeTagsCount()) > 0) {
			$limit = min($count, self::BATCH_SIZE);
			$tags = $this->cachePurge->grabPurgeTags($limit);
			$this->_shellPurge(['tags' => implode(',', $tags)]);
		}
	}

	private function _shellPurge($params)
	{
		$msg = 'FlushCacheByCli ';
		try {
			$uri = $this->getPurgeUrl($params);
			$msg .= sprintf('%s url=%s ', $this->_reason, $uri);
			$result = 'OK';

			$client = $this->curlFactory->create();
			$client->setTimeout(180);
			$client->addHeader('User-Agent', 'litemage_walker');
			if ($this->hostHeader) {
				$client->addHeader('Host', $this->hostHeader);
			}
			if ($auth = $this->config->getBasicAuth()) {
				$credentials = explode(':', $auth, 2);
				$client->setCredentials($credentials[0], $credentials[1]);
			}

			$client->get($uri);
			$data = $client->getBody();
			$http_code = $client->getStatus();
			if ($http_code == 200 || $http_code == 201) {
				$result = 'OK' . "\n$data";
			} else {
				$result = ' Unexpected HTTP code: ' . $http_code
						. $this->formatResponseBodyForLog($data);
				if ($http_code == 503) {
					if (strpos($data, 'maintenance') !== false) {
						$result .= "\n*** Failed to send LiteMage flush request, please add your backend server IP to maintenance allowed ips.";
					}
				} elseif ($http_code == 401) {
					$result .= "\n*** If you have Basic Authentication enabled, make sure it is properly set in LiteMage config - Developer Settings - Basic Authentication.";
				}
			}
			$msg .= 'res=' . $result;
		} catch (\Exception $e) {
			$result = $e->getMessage();
			$msg .= 'Exception ' . $result;
		}

		$this->cachePurge->debugLog($msg);
		$this->cachePurge->debugTrace('shellpurge');
		echo "Flush LiteMage Cache by CLI - $result \n";
	}
	    
	private function getPurgeUrl($params)
	{
		if ($this->purgeBaseUrl == null) {
			$server_ip = $this->config->getServerIp();
			$storeId = $this->config->getFrontStoreId();
			try {
				$base = $this->url->getUrl('litemage/shell/purge', ['_scope' => $storeId, '_nosid' => true]);
			} catch (\Exception $e) {
				$base = $this->url->getUrl('litemage/shell/purge', ['_nosid' => true]);
			}
			if ($server_ip) {
				$pattern = "/:\/\/([^\/^:]+)(\/|:)?/";
				if (preg_match($pattern, $base, $m)) {
					$domain = $m[1];
					$pos = strpos($base, $domain);
					$base = substr($base, 0, $pos) . $server_ip
							. substr($base, $pos + strlen($domain));
					$this->hostHeader = $domain;
				}
			}

			$this->purgeBaseUrl = $base;
		}

		$params = $this->shellPurgeAuth->signParams($params);
		$separator = (strpos($this->purgeBaseUrl, '?') === false) ? '?' : '&';
		return $this->purgeBaseUrl . $separator . http_build_query($params);
	}

	private function formatResponseBodyForLog($data)
	{
		$data = (string)$data;
		if ($data === '') {
			return '';
		}

		$body = substr($data, 0, self::ERROR_BODY_LIMIT);
		if (strlen($data) > self::ERROR_BODY_LIMIT) {
			$body .= '...';
		}

		return ' Body: ' . $body;
	}

}
