<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

/**
 * Signs and validates generated ESI block URLs.
 */
class EsiRequestAuth
{
    private const PARAM_SIGNATURE = 'sig';

    /**
     * @var \Magento\Framework\App\DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @param \Magento\Framework\App\DeploymentConfig $deploymentConfig
     */
    public function __construct(
        \Magento\Framework\App\DeploymentConfig $deploymentConfig
    ) {
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * @param string $blockName
     * @return array
     */
    public function signParams(array $params)
    {
        $params = $this->normalizeUrlParams($params);
        $params[self::PARAM_SIGNATURE] = $this->createSignature($params);
        return $params;
    }

    /**
     * @param string $handles
     * @return string|null
     */
    public function validateParams(array $params)
    {
        $signature = $params[self::PARAM_SIGNATURE] ?? '';
        $signature = is_scalar($signature) ? (string)$signature : '';

        if ($signature === '' || empty($params['b']) || empty($params['h'])) {
            return 'Invalid ESI request';
        }

        try {
            $expected = $this->createSignature($params);
        } catch (\RuntimeException $e) {
            return 'Invalid ESI request';
        }

        if (!hash_equals($expected, $signature)) {
            return 'Invalid ESI token';
        }

        return null;
    }

    /**
     * @param array $params
     * @return bool
     */
    public function validateBlockName($blockName)
    {
        return is_string($blockName)
            && $blockName !== ''
            && strlen($blockName) <= 128
            && preg_match('/^[A-Za-z0-9_.-]+$/', $blockName);
    }

    /**
     * @param array $params
     * @return bool
     */
    public function validateEncodedHandles($handles)
    {
        return is_string($handles)
            && $handles !== ''
            && strlen($handles) <= 2048
            && preg_match('/^[A-Za-z0-9_,.-]+$/', $handles);
    }

    /**
     * @param array $params
     * @return string
     */
    private function createSignature(array $params)
    {
        unset($params[self::PARAM_SIGNATURE]);
        $payload = $this->normalizeParams($params);
        return hash_hmac('sha256', 'litemage_esi:' . $payload, $this->getSecret());
    }

    /**
     * @param array $params
     * @return string
     */
    private function normalizeParams(array $params)
    {
        $payload = [];
        foreach (['b', 'h'] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = is_scalar($params[$key]) ? (string)$params[$key] : '';
            }
        }
        ksort($payload);
        return http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array $params
     * @return array
     */
    private function normalizeUrlParams(array $params)
    {
        unset($params[self::PARAM_SIGNATURE]);

        $ordered = [];
        foreach (['b', 'h'] as $key) {
            if (array_key_exists($key, $params)) {
                $ordered[$key] = $params[$key];
                unset($params[$key]);
            }
        }

        if (!empty($params)) {
            ksort($params);
            foreach ($params as $key => $value) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    /**
     * @return string
     */
    private function getSecret()
    {
        $secret = (string)$this->deploymentConfig->get('crypt/key');
        if ($secret === '') {
            throw new \RuntimeException('Missing Magento crypt key for LiteMage ESI signing.');
        }
        return $secret;
    }
}
