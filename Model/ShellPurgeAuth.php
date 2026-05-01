<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

/**
 * Signs and validates internal shell purge requests.
 */
class ShellPurgeAuth
{
    private const MAX_AGE = 300;
    private const PARAM_NONCE = 'nonce';
    private const PARAM_SIGNATURE = 'signature';
    private const PARAM_TIMESTAMP = 'ts';

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
     * @param array $params
     * @return array
     * @throws \Exception
     */
    public function signParams(array $params)
    {
        $params[self::PARAM_TIMESTAMP] = (string)time();
        $params[self::PARAM_NONCE] = bin2hex(random_bytes(16));
        $params[self::PARAM_SIGNATURE] = $this->createSignature($params);
        return $params;
    }

    /**
     * @param array $params
     * @return string|null
     */
    public function validateParams(array $params)
    {
        $signature = $params[self::PARAM_SIGNATURE] ?? '';
        $signature = is_scalar($signature) ? (string)$signature : '';

        if ($signature === ''
            || empty($params[self::PARAM_TIMESTAMP])
            || empty($params[self::PARAM_NONCE])
        ) {
            return 'Invalid request';
        }

        $timestamp = (int)$params[self::PARAM_TIMESTAMP];
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_AGE) {
            return 'Expired token';
        }

        try {
            $expected = $this->createSignature($params);
        } catch (\RuntimeException $e) {
            return 'Invalid request';
        }

        if (!hash_equals($expected, $signature)) {
            return 'Invalid token';
        }

        return null;
    }

    /**
     * @param array $params
     * @return string
     */
    private function createSignature(array $params)
    {
        unset($params[self::PARAM_SIGNATURE]);
        $payload = $this->normalizeParams($params);
        return hash_hmac('sha256', $payload, $this->getSecret());
    }

    /**
     * @param array $params
     * @return string
     */
    private function normalizeParams(array $params)
    {
        $payload = [];
        foreach (['all', 'tags', self::PARAM_TIMESTAMP, self::PARAM_NONCE] as $key) {
            if (isset($params[$key])) {
                $payload[$key] = is_scalar($params[$key]) ? (string)$params[$key] : '';
            }
        }
        ksort($payload);
        return http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return string
     */
    private function getSecret()
    {
        $secret = (string)$this->deploymentConfig->get('crypt/key');
        if ($secret === '') {
            throw new \RuntimeException('Missing Magento crypt key for LiteMage shell purge signing.');
        }
        return $secret;
    }
}
