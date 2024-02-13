<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;


/**
 * Command for flush litemage cache by tags
 */
abstract class AbstractLitemageCommand extends Command
{
    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;

	/**
	 *
	 * @var string 
	 */
	protected $type;
	/**
	 *
	 * @var array
	 */
	protected $tags;
	/**
	 *
	 * @var string
	 */
	protected $tag_format;

	/**
     * @param EventManagerInterface $eventManager
     */
    public function __construct(EventManagerInterface $eventManager)
	{
        $this->eventManager = $eventManager;
        parent::__construct();
    }

	/**
     * {@inheritdoc}
     */
    protected function configure()
    {
		parent::configure();
	}

    /**
     * Perform a cache management action on cache types
     *
     * @return void
     *
    abstract protected function performAction(); */

    /**
     * Get display message
     *
     * @return string
     */
    abstract protected function getDisplayMessage();
	
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->getInputList($input);
		$param = ['tags' => $this->tags,
				'reason' => 'cli command: ' . $this->getName()];
		$this->eventManager->dispatch('litemage_cli_purge', $param);
        $output->writeln($this->getDisplayMessage());
	}

	protected function getInputList(InputInterface $input)
	{
        $list = $input->getArgument($this->type);
		if (!empty($list)) {
            $list = array_filter(array_map('trim', $list), 'strlen');
        }
		if (empty($list)) {
			throw new \InvalidArgumentException(
				"Missing the required space-separated list");
		}
		foreach ($list as $tag) {
			if (!preg_match($this->tag_format, $tag)) {
				throw new \InvalidArgumentException(
				"Tag [$tag] contains invalid characters.");
			}
		}
		$this->tags = $list;
	}

}
