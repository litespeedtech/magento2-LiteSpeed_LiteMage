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


namespace Litespeed\Litemage\Observer;

use Magento\Framework\Event\ObserverInterface;

class FlushCacheByCli implements ObserverInterface
{
	protected $config;
	protected $logger;
	protected $url;

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $coreRegistry;

    /**
     * @param \Litespeed\Litemage\Model\Config $config,
	 * @param \Magento\Framework\Registry $coreRegistry,
	 * @param \Magento\Framework\Url $url,
     * @param \Psr\Log\LoggerInterface $logger,
	 * @throws \Magento\Framework\Exception\IntegrationException
     */
    public function __construct(\Litespeed\Litemage\Model\Config $config,
			\Magento\Framework\Registry $coreRegistry,
			\Magento\Framework\Url $url,
			\Psr\Log\LoggerInterface $logger)
    {
		if (PHP_SAPI !== 'cli')	{
			throw new \Magento\Framework\Exception\IntegrationException('Should only invoke from command line');
		}
        $this->config = $config;
		$this->coreRegistry = $coreRegistry;
		$this->url = $url;
		$this->logger = $logger;
    }

    /**
     * Flush All Litemage cache
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
		if (!$this->config->moduleEnabled())
			return;

		$event = $observer->getEvent();
		$tags = $event->getTags();

		if (in_array('*', $tags)) {
			if ($this->coreRegistry->registry('shellPurgeAll') === null) {
				$this->coreRegistry->register('shellPurgeAll', 1);
				$this->_shellPurge(['all' => 1]);
			}
		}
		else {
			$tags = array_unique($tags);
			$used = [];
			foreach ($tags as $tag) {
				if ($this->coreRegistry->registry("shellPurge_{$tag}") === null) {
					$this->coreRegistry->register("shellPurge_{$tag}", 1);
					$used[] = $tag;
				}
			}
			if (!empty($used)) {
				$this->_shellPurge(['tags' => implode(',', $used)]);
			}
		}
    }

	protected function _shellPurge($params)
	{
		$client = new \Magento\Framework\HTTP\ZendClient();

		$clientConfig = ['verifypeer' => 0,
			'timeout' => 180,
			'useragent' => 'litemage_walker'];

		$client->setConfig($clientConfig);
		$client->setMethod(\Zend_Http_Client::POST);
		foreach($params as $k => $v)
		$client->setParameterPost($k, $v);

		$server_ip = false; //in future, allow this configurable.
		$base = $this->url->getBaseUrl();
		if ($server_ip) {
			$pattern = "/:\/\/([^\/^:]+)(\/|:)?/";
			if (preg_match($pattern, $base, $m)) {
				$domain = $m[1];
				$pos = strpos($base, $domain);
				$base = substr($base, 0, $pos) . $server_ip . substr($base, $pos + strlen($domain));
				$client->setHeaders(['Host' => $domain]);
			}
		}

		$uri = $base . 'litemage/shell/purge';
		$client->setUri($uri);
        $client->setUrlEncodeBody(false);
		try {
			$response = $client->request();
			$this->logger->debug($uri . ' ' . $response->getBody());
		} catch (\Zend_Http_Client_Exception $e) {
			$this->logger->critical($uri . ' ' . $e->getMessage());
			return false;
		}

        return true;
	}

}
