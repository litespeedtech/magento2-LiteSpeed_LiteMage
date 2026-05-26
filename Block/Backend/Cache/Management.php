<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Backend\Cache;

class Management extends \Magento\Backend\Block\Template
{

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\HTTP\ClientFactory
     */
    protected $httpClientFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * 
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Litespeed\Litemage\Model\Config $config
     * @param \Magento\Framework\HTTP\ClientFactory $httpClientFactory
     * @param array $data
     */
    public function __construct(
            \Magento\Backend\Block\Template\Context $context,
            \Litespeed\Litemage\Model\Config $config,
            \Magento\Framework\HTTP\ClientFactory $httpClientFactory,
            array $data = [])
    {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->httpClientFactory = $httpClientFactory;
        $this->logger = $context->getLogger();
    }

    public function getCacheStatistics()
    {
        $statBase = $this->getStatisticsBaseUrl();
        if ($statBase === null) {
            $this->logger->debug('LiteMage statistics skipped: unable to resolve admin base URL.');
            return null;
        }
        $statUri = $statBase . '/__LSCACHE/STATS';

        try {
            $client = $this->httpClientFactory->create();
            $client->get($statUri);
            $data = trim($client->getBody());
            if ($data == '' || substr($data, 0, 1) !== '{') {
                $this->logger->debug('LiteMage statistics skipped: empty or invalid response body.');
                return null;
            }

            $data1 = json_decode($data, true);
            if (!is_array($data1) || json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->debug('LiteMage statistics skipped: invalid JSON response.');
                return null;
            }

            $data2 = array_values($data1);
            if (!count($data2) || !is_array($data2[0])) {
                $this->logger->debug('LiteMage statistics skipped: missing statistics payload.');
                return null;
            }

            $stats = $data2[0];
            foreach (['LITEMAGE_PLAN', 'LITEMAGE_LIMITED', 'PUB_HITS', 'LITEMAGE_OBJS'] as $key) {
                if (!array_key_exists($key, $stats)) {
                    $this->logger->debug(sprintf('LiteMage statistics skipped: missing "%s" value.', $key));
                    return null;
                }
            }

            switch ((int)$stats['LITEMAGE_PLAN']) {
                case 11:
                    $stats['plan'] = 'LiteMage Standard';
                    $stats['plan_desc'] = 'up to 25000 publicly cached objects';
                    break;
                case 3:
                    $stats['plan'] = 'LiteMage Unlimited';
                    $stats['plan_desc'] = 'unlimited publicly cached objects';
                    break;
                case 9:
                default:
                    $stats['plan'] = 'LiteMage Starter';
                    $stats['plan_desc'] = 'up to 1500 publicly cached objects';
            }

            return $stats;
        } catch (\Exception $e) {
            $this->logger->warning('LiteMage statistics request failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * @return string|null
     */
    private function getStatisticsBaseUrl()
    {
        $base = $this->getUrl();
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $statBase = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $statBase .= ':' . $parts['port'];
        }

        $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
        if (strpos($path, '~') === 0) {
            $segments = explode('/', $path);
            $statBase .= '/' . $segments[0];
        }

        return $statBase;
    }

    /**
     * Check if block can be displayed
     *
     * @return bool
     */
    public function canShowButton()
    {
        return $this->config->moduleEnabled();
    }

    public function isWarmupEnabled()
    {
        return $this->config->isWarmupEnabled();
    }

    public function getWarmupStatusUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/status');
    }

    public function getWarmupConfigUrl()
    {
        return $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'litemage', '_fragment' => 'litemage_warmup-link']
        );
    }

}
