<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Litespeed\Litemage\Model\Warmup\ReverseIndexRepository;
use Magento\Backend\Block\Template\Context;

class Dashboard extends Navigation
{
    protected $_template = 'Litespeed_Litemage::warmup/status.phtml';

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var ReverseIndexRepository
     */
    private $reverseIndexRepository;

    public function __construct(
        Context $context,
        Config $config,
        QueueRepository $queueRepository,
        ReverseIndexRepository $reverseIndexRepository,
        array $data = []
    ) {
        $this->queueRepository = $queueRepository;
        $this->reverseIndexRepository = $reverseIndexRepository;
        parent::__construct($context, $config, $data);
    }

    public function getQueueSummary()
    {
        return $this->queueRepository->getStatusSummary();
    }

    public function getReverseIndexSummary()
    {
        return $this->reverseIndexRepository->getSummary();
    }

    public function getWarmupCronSchedule()
    {
        return $this->getWarmupProcessCronSchedule();
    }

    public function getWarmupProcessCronSchedule()
    {
        return $this->config->getWarmupProcessCronSchedule();
    }

    public function getWarmupGenerateCronSchedule()
    {
        return $this->config->getWarmupGenerateCronSchedule();
    }

    public function formatBytes($bytes)
    {
        $bytes = (int)$bytes;
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
