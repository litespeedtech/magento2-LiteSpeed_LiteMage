<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;


/**
 * Command for flush litemage cache by product IDs
 */
class LitemageFlushProds extends AbstractLitemageCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
		$this->type = 'prods';
		$this->tag_format = '/^[\d+]+$/';
        $this->setName('cache:litemage:flush:prods');
        $this->setDescription('Flushes LiteMage cache by a list of product IDs');
        $this->addArgument(
            'prods',
            InputArgument::IS_ARRAY,
            'Space-separated list of product IDs (integer).'
        );
		parent::configure();
	}

	protected function getInputList(InputInterface $input)
	{
		parent::getInputList($input);
		$this->tags = array_map(function($value) {
			return 'P' . $value;
		}, $this->tags);

	}

	protected function getDisplayMessage()
	{
		return 'Flushed LiteMage cache by product IDs.';
	}
}
