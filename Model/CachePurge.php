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
	private $ignored_cache_tags;
	private $ignored_purge_tags;


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
		$this->ignored_cache_tags = $this->config->getIgnoredTags();
		$this->ignored_purge_tags = $this->config->getIgnoredPurgeTags();
    }

    public function needPurge()
    {
        return ($this->config->moduleEnabled() && !empty($this->_purgeTags));
    }

	public function debugLog($message, $forced=false)
	{
		$this->helper->debugLog($message, $forced);
	}

	public function debugTrace($message, $forced=false)
	{
		$this->helper->debugTrace($message, $forced);
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
            return false;
        }
        $added = false;
        if (in_array('*', $tags)) {
            $this->_isPurgeAll = true;
            $this->_purgeTags = ['*'];
            $added = true;
        } else {
			$filtered = $this->filterPurgeTags($tags, $source);
            $newtags = array_diff($filtered, $this->_purgeTags);
            if (!empty($newtags)) {
                $this->_purgeTags = array_merge($this->_purgeTags, $newtags);
                $added = true;
            }
        }

        if ($added && $this->_debug) {
            $this->helper->debugLog(sprintf('add purge tags by %s : Result=%s',
                                            $source, 
                                            implode(',', $this->_purgeTags)));
            $this->helper->debugTrace($source);
        }
		return $added;
    }


	public function resetPurgeTags($tags, $source)
	{
		if ($this->_isPurgeAll) {
			return;
		}
		if ($this->_debug) {
            $this->helper->debugLog(sprintf('reset purge tags from %s : Before=%s Result=%s',
                                            $source, implode(',', $this->_purgeTags), implode(',', $tags)));
            $this->helper->debugTrace($source);
		}
		$this->_purgeTags = [];
	}

	public function purgeProductIds(array $productIds, $source)
	{
		$tags = [];
		foreach ($productIds as $id) {
			$tags[] = 'P' . $id;
		}
		$this->addPurgeTags($tags, "purgeProductIds $source");

	}

	public function purgeCategoryIds(array $catIds, $source)
	{
		$tags = [];
		foreach ($catIds as $id) {
			$tags[] = 'C_' . $id;
		}
		$this->addPurgeTags($tags, "purgeCategoryIds $source");
	}

    public function setPurgeHeaders($response)
    {
        if (empty($this->_purgeTags))
            return;

        if ($this->_isPurgeAll) {
            $this->setRealPurgeHeaders($response, '*');
            return;
        } 
        
        $tags = array_unique($this->_purgeTags);
        // if contains big list, split to multi-headers
        while (count($tags) > 500) {
            $tags1 = array_slice($tags, 0, 500);
            $purgeTags = implode(',', $tags1);
            $this->setRealPurgeHeaders($response, $purgeTags);
            $tags = array_slice($tags, 500);
        }
        if (count($tags) > 0)  {
            $purgeTags = implode(',', $tags);
            $this->setRealPurgeHeaders($response, $purgeTags);
        }
    }

    protected function setRealPurgeHeaders($response, $purgeTags)
    {
        $response->setHeader(self::LSHEADER_PURGE, $purgeTags);
        if ($this->_debug) {
            $this->helper->debugLog('Set purge header ' . $purgeTags);
            if ($this->_debug == 2) {
                $response->setHeader(self::LSHEADER_DEBUG_Purge, $purgeTags);
            }
        }
    }

	protected function isProductTagOnly()
	{
		if (!$this->_isPurgeAll && !empty($this->_purgeTags)) {
			foreach ($this->_purgeTags as $t) {
				if (substr($t, 0, 1) != 'P') {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	protected function checkIgnoreCats($tags, $source)
	{
		$found_pid = [];
		$found_cats = [];

		foreach($tags as $tag) {
			if (substr($tag, 0, 1) == 'P') {
				$found_pid[] = $tag;
				continue;
			}
			if (substr($tag, 0, 2) == 'C_') {
				$found_cats[] = $tag;
				continue;
			}
			// contains other tag
			return false;
		}

		if (empty($found_cats)) {
			return false;
		}

		$prod_only = false;
		if (!empty($found_pid) || $this->isProductTagOnly()) {
			$this->helper->debugLog('Ignored purge cat tags based on setting ' . implode(',', $found_cats) . " from $source");
			return $found_pid;
		}
		return false;
	}

	protected function checkAginstIgnoredPurgeTags($tag)
	{
		foreach ($this->ignored_purge_tags as $pt) {
			if ($tag == $pt) {
				return true;
			}
			if (substr($pt, -1) == '*') {
				$len = strlen($pt) -1;
				if (strncmp($tag, $pt, $len) == 0) {
					return true;
				}
			}
		}
		return false;
	}

    public function filterPurgeTags($tags, $source)
    {
		$filtered = $this->helper->translateFilterTags($tags);
		if ($this->config->getProdEditNoPurgeCats()) {
			 $res = $this->checkIgnoreCats($filtered, $source);
			 if ($res !== false) {
				// contains only product tags, done.
				return $res;
			 }
		}

		// check ignored
		if (!empty($this->ignored_cache_tags)) {
			$ignored = array_intersect($filtered, $this->ignored_cache_tags);
			if (!empty($ignored)) {
				$this->helper->debugLog('Ignored purge tags based on ignored cache tags ' . implode(',', $ignored) . " from $source");
				$filtered = array_diff($filtered, $ignored);
			}
		}
		if (!empty($this->ignored_purge_tags)) {
			$included = [];
			$ignored = [];
			foreach ($filtered as $tag) {
				if (substr($tag, 0, 1) == 'P' || substr($tag, 0, 2) == 'C_') {
					// always include prod/cats
					$included[] = $tag;
					continue;
				}
				if ($this->checkAginstIgnoredPurgeTags($tag)) {
					$ignored[] = $tag;
				} else {
					$included[] = $tag;
				}
			}
			if (!empty($ignored)) {
				$this->helper->debugLog('Ignored purge tags based on ignored purge tags ' . implode(',', $ignored) . " from $source");
				$filtered = $included;
			}
		}
		return $filtered;
    }

}
