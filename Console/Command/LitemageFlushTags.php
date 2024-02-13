<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Symfony\Component\Console\Input\InputArgument;


/**
 * Command for flush litemage cache by tags
 */
class LitemageFlushTags extends AbstractLitemageCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
		$this->type = 'tags';
		$this->tag_format = '/^[a-zA-Z\d_-]+$/';
        $this->setName('cache:litemage:flush:tags');
        $this->setDescription('Flushes LiteMage cache by a list of tags');
        $this->addArgument(
            'tags',
            InputArgument::IS_ARRAY,
            'Space-separated list of cache tags.'
        );
		parent::configure();
	}

	protected function getDisplayMessage()
	{
		return 'Flushed LiteMage cache by tags.';
	}
}
