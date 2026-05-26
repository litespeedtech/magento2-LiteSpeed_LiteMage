<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

class CrawlerMode
{
    public const MODE_RUNNER = 'runner';
    public const MODE_WALKER = 'walker';

    private const USER_AGENTS = [
        self::MODE_RUNNER => 'litemage_runner',
        self::MODE_WALKER => 'litemage_walker',
    ];

    public function normalize($mode)
    {
        $mode = strtolower(trim((string)$mode));
        if (!isset(self::USER_AGENTS[$mode])) {
            throw new \InvalidArgumentException(sprintf('Unsupported LiteMage crawler mode "%s".', $mode));
        }
        return $mode;
    }

    public function getUserAgent($mode)
    {
        return self::USER_AGENTS[$this->normalize($mode)];
    }
}
