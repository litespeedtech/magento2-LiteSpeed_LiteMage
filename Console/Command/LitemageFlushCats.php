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
 * Command for flush litemage cache by category IDs
 */
class LitemageFlushCats extends AbstractLitemageCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
		$this->type = 'category_id';
		$this->tag_format = '/^[\d+]+$/';
        $this->setName('cache:litemage:flush:cats');
        $this->setDescription('Flushes LiteMage cache by a list of category IDs');
        $this->addArgument(
            $this->type,
            InputArgument::IS_ARRAY,
            'Space-separated list of category IDs (integer).'
        );
		parent::configure();
	}

	protected function getInputList(InputInterface $input)
	{
		parent::getInputList($input);
		$this->tags = array_map(function($value) {
			return 'C_' . $value;
		}, $this->tags);
	}

	protected function getDisplayMessage()
	{
		return 'Flushed LiteMage cache by category IDs.';
	}
}
