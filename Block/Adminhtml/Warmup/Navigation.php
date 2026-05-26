<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Navigation extends Template
{
    private const CONFIG_FRAGMENT_WARMUP = 'litemage_warmup-link';
    private const CONFIG_FRAGMENT_REVERSE_INDEX = 'litemage_warmup_warmup_reverse_index-link';

    protected $_template = 'Litespeed_Litemage::warmup/navigation.phtml';

    /**
     * @var Config
     */
    protected $config;

    public function __construct(Context $context, Config $config, array $data = [])
    {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    public function isWarmupEnabled()
    {
        return $this->config->isWarmupEnabled();
    }

    public function getStatusUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/status');
    }

    public function getProgressUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/progress');
    }

    public function getQueueUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/queue');
    }

    public function getResultsUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/results');
    }

    public function getPurgeEventsUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/purgeevents');
    }

    public function getConfigUrl()
    {
        return $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'litemage', '_fragment' => self::CONFIG_FRAGMENT_WARMUP]
        );
    }

    public function getReverseIndexConfigUrl()
    {
        return $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => 'litemage', '_fragment' => self::CONFIG_FRAGMENT_REVERSE_INDEX]
        );
    }

    public function getCacheManagementUrl()
    {
        return $this->getUrl('adminhtml/cache/index');
    }

    public function getActiveSection()
    {
        return (string)$this->getData('active_section');
    }

    public function getNavItems()
    {
        return [
            'status' => ['label' => __('Status'), 'url' => $this->getStatusUrl()],
            'progress' => ['label' => __('Progress'), 'url' => $this->getProgressUrl()],
            'queue' => ['label' => __('Warmup Queue'), 'url' => $this->getQueueUrl()],
            'results' => ['label' => __('Results'), 'url' => $this->getResultsUrl()],
            'purge_events' => ['label' => __('Purge Events'), 'url' => $this->getPurgeEventsUrl()],
        ];
    }
}
