<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Logger;

/**
 * Log handler
 */
class Handler extends \Magento\Framework\Logger\Handler\Base
{

    /**
     * @var string
     */
    protected $fileName = '/var/log/litemage.log';

    /**
     * @var int
     */
    protected $loggerType = \Monolog\Logger::NOTICE;

    /**
     * @param \Magento\Framework\Filesystem\DriverInterface $filesystem
     * @param string $filePath
     */
    public function __construct(\Magento\Framework\Filesystem\DriverInterface $filesystem,
                                $filePath = null)
    {
        parent::__construct($filesystem, $filePath);
        $logTag = $this->getLogTag();
        $this->setFormatter(new \Monolog\Formatter\LineFormatter("[%datetime%] $logTag %message%\n",
                                                                 null, true));
    }

    private function getLogTag()
    {
        $tag = 'LiteMage ';
        //$cronUserAgent = \Litespeed\Litemage\Model\Cron::USER_AGENT;

        if (isset($_SERVER['REMOTE_ADDR'])) {
            // from server http request
            /* if ($this->_httpHeader->getHttpUserAgent() == $cronUserAgent) {
              $this->_debugTag .= $cronUserAgent . ':';
              } */
            $msec = microtime();
            $msec1 = substr($msec, 2, strpos($msec, ' ') - 2);
            $tag .= sprintf('[%s:%s:%s]', $_SERVER['REMOTE_ADDR'],
                            $_SERVER['REMOTE_PORT'], $msec1);
        }
        return $tag;
    }

}
