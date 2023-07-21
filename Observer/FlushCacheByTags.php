<?php

/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Observer;

class FlushCacheByTags implements \Magento\Framework\Event\ObserverInterface
{

	/**
	 * @var \Litespeed\Litemage\Model\Config
	 */
	protected $config;

	/**
	 * @var \Litespeed\Litemage\Model\CachePurge
	 */
	protected $litemagePurge;

	/** @var \Magento\Framework\Event\ManagerInterface */
	protected $eventManager;
	private $enabled;

	/**
	 * @param \Litespeed\Litemage\Model\Config $config,
	 * @param \Magento\Framework\Event\ManagerInterface $eventManager,
	 */
	public function __construct(\Litespeed\Litemage\Model\Config $config,
			\Litespeed\Litemage\Model\CachePurge $litemagePurge,
			\Magento\Framework\Event\ManagerInterface $eventManager)
	{
		$this->config = $config;
		$this->litemagePurge = $litemagePurge;
		$this->eventManager = $eventManager;
		$this->enabled = $this->config->moduleEnabled();
	}

	/**
	 * Flush Litemage cache by Tags
	 * @param \Magento\Framework\Event\Observer $observer
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		if (!$this->enabled) {
			return;
		}

		$rawtags = [];
		$object = $observer->getEvent()->getObject();
		if ($object instanceof \Magento\Framework\DataObject\IdentityInterface) {
			$rawtags = $object->getIdentities();
		}

		if (empty($rawtags)) {
			return;
		}

		$reason = sprintf('FlushCacheByTags event %s %s',
				$observer->getEvent()->getName(),
				implode(',', $rawtags));

		if (PHP_SAPI == 'cli') {
			// from command line
			$param = ['tags' => $this->litemagePurge->filterPurgeTags($rawtags, 'CLI'),
				'reason' => $reason];
			$this->eventManager->dispatch('litemage_cli_purge', $param);
		} else {
			$this->litemagePurge->addPurgeTags($rawtags, $reason);
		}
	}

}
