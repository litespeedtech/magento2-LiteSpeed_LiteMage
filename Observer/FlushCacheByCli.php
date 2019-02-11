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
	protected $config;
	protected $logger;
	protected $url;
    protected $_reason;

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
     * @param \Litespeed\Litemage\Logger\Logger $logger
	 * @throws \Magento\Framework\Exception\IntegrationException
     */
    public function __construct(\Litespeed\Litemage\Model\Config $config,
			\Magento\Framework\Registry $coreRegistry,
			\Magento\Framework\Url $url,
            \Litespeed\Litemage\Logger\Logger $logger)
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
        $this->_reason = $event->getReason();

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
		$server_ip = false; //in future, allow this configurable.
        $uparams = ['_type'   => \Magento\Framework\UrlInterface::URL_TYPE_LINK,
            '_secure' => true];
        $base = $this->url->getBaseUrl($uparams);
        $headers = [];
		if ($server_ip) {
			$pattern = "/:\/\/([^\/^:]+)(\/|:)?/";
			if (preg_match($pattern, $base, $m)) {
				$domain = $m[1];
				$pos = strpos($base, $domain);
				$base = substr($base, 0, $pos) . $server_ip . substr($base, $pos + strlen($domain));
                $headers[] = "Host: $domain";
			}
		}

		$uri = $base . 'litemage/shell/purge';
        $result = true;
        $msg = "FlushCacheByCli {$this->_reason}\n URI = $uri tags=" . print_r($params, 1);

		try {

            $curl = curl_init();

            $options = [CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_URL            => $uri,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYSTATUS => false,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_USERAGENT      => 'litemage_walker',
                CURLOPT_POSTFIELDS     => $params,
            ];

            curl_setopt_array($curl, $options);
            if (!empty($headers)) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }

            $response = curl_exec($curl);
            curl_close($curl);
            $msg .= $response;
        } catch (\Exception $e) {
            $msg .= 'Exception ' . $e->getMessage();
            $result = false;
        }

        if ($this->config->debugEnabled()) {
            $this->logger->notice($msg);
        }
        return $result;
    }

}
