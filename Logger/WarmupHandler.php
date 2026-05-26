<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger as MonologLogger;

class WarmupHandler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/litemage-crawler.log';

    /**
     * @var int
     */
    protected $loggerType = MonologLogger::NOTICE;

    public function __construct(\Magento\Framework\Filesystem\DriverInterface $filesystem, $filePath = null)
    {
        parent::__construct($filesystem, $filePath);
        $this->setFormatter(new LineFormatter("[%datetime%] LiteMage Warmup %message%\n", null, true));
    }
}
