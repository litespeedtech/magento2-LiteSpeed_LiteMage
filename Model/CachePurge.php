<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

/**
 * Class CachePurge
 *
 */
class CachePurge
{
    /*
     * Cache related headers only for LiteSpeed Web Server
     */

    private const LSHEADER_PURGE = 'X-LiteSpeed-Purge';
    private const LSHEADER_DEBUG_Purge = 'X-LiteMage-Debug-Purge';

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /**
     * @var \Litespeed\Litemage\Helper\Data
     */
    protected $helper;
    private $_purgeTags = [];
    private $_isPurgeAll = false;
    private $_debug;

    /**
     * constructor
     *
     * @param \Litespeed\Litemage\Model\Config $config,
     * @param \Litespeed\Litemage\Helper\Data $helper
     */
    public function __construct(
            \Litespeed\Litemage\Model\Config $config,
            \Litespeed\Litemage\Helper\Data $helper
    )
    {
        $this->config = $config;
        $this->helper = $helper;
        $this->_debug = $this->helper->debugEnabled();
    }

    public function needPurge()
    {
        return ($this->config->moduleEnabled() && !empty($this->_purgeTags));
    }

    /**
     * Add Purge tags
     * @param array $tags
     * @param string $source
     *
     */
    public function addPurgeTags($tags, $source)
    {
        if ($this->_isPurgeAll || empty($tags)) {
            return;
        }
        $changed = false;
        if (in_array('*', $tags)) {
            $this->_isPurgeAll = true;
            $this->_purgeTags = ['*'];
            $changed = true;
        } else {
            $newtags = array_diff($tags, $this->_purgeTags);
            if (!empty($newtags)) {
                $this->_purgeTags = array_merge($this->_purgeTags, $newtags);
                $changed = true;
            }
        }

        if ($changed && $this->_debug) {
            $this->helper->debugLog(sprintf('add purge tags from %s : %s Result=%s',
                                            $source, implode(',', $tags),
                                            implode(',', $this->_purgeTags)));
            $this->helper->debugTrace($source);
        }
    }

    public function setPurgeHeaders($response)
    {
        if (empty($this->_purgeTags))
            return;

        if ($this->_isPurgeAll) {
            $purgeTags = '*';
        } else {
            $purgeTags = 'tag=' . implode(',tag=', $this->helper->translateFilterTags($this->_purgeTags));
        }
        $response->setHeader(self::LSHEADER_PURGE, $purgeTags);
        if ($this->_debug) {
            $this->helper->debugLog('Set purge header ' . $purgeTags);
            if ($this->_debug == 2) {
                $response->setHeader(self::LSHEADER_DEBUG_Purge, $purgeTags);
            }
        }
    }

}
