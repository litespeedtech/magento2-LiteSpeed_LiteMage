<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Logger;

/**
 * Class Logger
 */
class Logger extends \Monolog\Logger
{

    public function __construct($name, array $handlers = [],
                                array $processors = [])
    {
        $this->name = $name;
        $this->handlers = $handlers;
        $this->processors = $processors;
    }

}
