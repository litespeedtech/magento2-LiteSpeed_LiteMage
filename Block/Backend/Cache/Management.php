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
 * @copyright  Copyright (c) 2017 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
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
     * @param \Magento\Backend\Block\Template\Context $context
	 * @param \Litespeed\Litemage\Model\Config $config,
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
    }

    /**
     * Get clean cache url
     *
     * @return string
     */
    public function getPurgeUrl($type)
    {
		if ($type == 'Refresh') {
			return $this->getUrl('*/*/cache/index');
		}
		else {
	        $types = array('All', 'Tag', 'Url');

			if (in_array($type, $types)) {
				return $this->getUrl('*/litemageCache/purge' . $type);
			}
			else {
				return $this->getUrl('*/litemageCache/purgeAll');
			}
		}
    }

	public function getCacheStatistics()
	{
		$statUri = '/__LSCACHE/STATS';
        $base = $this->getUrl();
		if ((stripos($base, 'http') !== false) && ($pos = strpos($base, '://'))) {
			$pos2 = strpos($base, '/', $pos+ 4);
			if ($pos === false) {
				$statBase = $base;
			}
			else {
				$statBase = substr($base, 0, $pos2);
				if (substr($base, $pos2+1, 1) == '~') {
					if ($pos3 = strpos($base, '/', $pos2+1)) {
						$statBase = substr($base, 0, $pos3);
					}
				}
			}
		}
		$statUri = $statBase . $statUri;

		try {
    		$client = $this->httpClientFactory->create();
            $client->setOption(CURLOPT_SSL_VERIFYHOST, 0);
            $client->setOption(CURLOPT_SSL_VERIFYPEER, 0);
			$client->get($statUri) ;
			$data = trim($client->getBody());
			if ($data{0} !== '{') {
				return null;
			}

			$data1 = json_decode($data, true);
			$data2 = array_values($data1);
			if (count($data2)) {
				$stats = $data2[0];
				switch ($stats['LITEMAGE_PLAN']) {
					case 11: $stats['plan'] = 'LiteMage Standard';
						$stats['plan_desc'] = 'up to 25000 publicly cached objects';
						break;
					case 3: $stats['plan'] = 'LiteMage Unlimited';
						$stats['plan_desc'] = 'unlimited publicly cached objects';
						break;
					case 9:
					default:
						$stats['plan'] = 'LiteMage Starter';
						$stats['plan_desc'] = 'up to 1500 publicly cached objects';
				}
				return $stats;
			}
		} catch ( Exception $e ) {
		}
		return null;
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


}
