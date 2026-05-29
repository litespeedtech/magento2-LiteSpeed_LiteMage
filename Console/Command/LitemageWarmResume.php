<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Console\Command;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LitemageWarmResume extends Command
{
    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    public function __construct(WriterInterface $configWriter, TypeListInterface $cacheTypeList)
    {
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cache:litemage:warm:resume');
        $this->setDescription('Enable LiteMage cache warmer processing.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->configWriter->save('litemage/warmup/enabled', 1);
        $this->cacheTypeList->cleanType('config');
        $output->writeln('LiteMage cache warmer enabled.');
        return Cli::RETURN_SUCCESS;
    }
}
