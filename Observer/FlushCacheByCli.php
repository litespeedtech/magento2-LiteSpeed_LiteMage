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
    private $_reason;

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
                $this->_shellPurge(['tags' => implode(',', $used)]);
            }
        }
    }

    private function _shellPurge($params)
    {
        $msg = sprintf("FlushCacheByCli %s tags=%s", $this->_reason,
                       print_r($params, 1));

        $server_ip = false; //in future, allow this configurable.
        $uparams = ['_type' => \Magento\Framework\UrlInterface::URL_TYPE_LINK,
            '_secure' => true];
        $base = $this->url->getBaseUrl($uparams);
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
        $params['secret'] = md5(print_r($stat, 1));
        $uri = $base . 'litemage/shell/purge?' . http_build_query($params);
        $result = true;

        try {

            $curl = curl_init();
            // cannot use POST due to csrf validation
            $options = [CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_URL => $uri,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
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

            curl_setopt_array($curl, $options);
            if (!empty($headers)) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }

            $response = curl_exec($curl);
            curl_close($curl);
            $msg .= 'res=' . $response;
        } catch (\Exception $e) {
            $msg .= 'Exception ' . $e->getMessage();
            $result = false;
        }

        $this->helper->debugLog($msg);

        return $result;
    }

}
