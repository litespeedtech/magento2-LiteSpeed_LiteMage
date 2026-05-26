<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

class RequestProfileBuilder
{
    private const MOBILE_USER_AGENT = 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
    private const DESKTOP_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /**
     * @var CrawlerMode
     */
    private $crawlerMode;

    /**
     * @var CustomerGroupContextSigner
     */
    private $customerGroupContextSigner;

    public function __construct(
        CrawlerMode $crawlerMode,
        CustomerGroupContextSigner $customerGroupContextSigner
    )
    {
        $this->crawlerMode = $crawlerMode;
        $this->customerGroupContextSigner = $customerGroupContextSigner;
    }

    public function build($mode, array $profile = [])
    {
        $headers = [
            'User-Agent' => $this->crawlerMode->getUserAgent($mode),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];

        if (!empty($profile['device'])) {
            if ($profile['device'] === 'mobile') {
                $headers['User-Agent'] = self::MOBILE_USER_AGENT . ' ' . $headers['User-Agent'];
            } elseif ($profile['device'] === 'desktop') {
                $headers['User-Agent'] = self::DESKTOP_USER_AGENT . ' ' . $headers['User-Agent'];
            }
        }

        if (!empty($profile['webp'])) {
            $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
        }

        if (!empty($profile['language'])) {
            $headers['Accept-Language'] = $profile['language'];
        }

        if (isset($profile['customer_group_id'])) {
            $headers = array_merge(
                $headers,
                $this->customerGroupContextSigner->buildHeaders(
                    (int)$profile['customer_group_id'],
                    !empty($profile['customer_group_logged_in'])
                )
            );
        }

        $cookies = [];
        if (!empty($profile['currency'])) {
            $cookies['currency'] = strtoupper((string)$profile['currency']);
        }
        if (!empty($profile['cookies']) && is_array($profile['cookies'])) {
            foreach ($profile['cookies'] as $name => $value) {
                $name = trim((string)$name);
                if ($name !== '') {
                    $cookies[$name] = (string)$value;
                }
            }
        }

        return [
            'headers' => $headers,
            'cookies' => $cookies,
        ];
    }
}
