<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\CrawlerMode;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Litespeed\Litemage\Model\Warmup\QueueStatus;
use Litespeed\Litemage\Model\Warmup\ReverseIndexRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class Queue extends Template
{
    private const DEFAULT_PAGE_SIZE = 100;
    private const PAGE_SIZE_OPTIONS = [20, 50, 100, 200, 500];

    protected $_template = 'Litespeed_Litemage::warmup/queue.phtml';

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var ReverseIndexRepository
     */
    private $reverseIndexRepository;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var array|null
     */
    private $rows;

    /**
     * @var int|null
     */
    private $totalRows;

    /**
     * @var array|null
     */
    private $profileLabels;

    public function __construct(
        Context $context,
        QueueRepository $queueRepository,
        ReverseIndexRepository $reverseIndexRepository,
        Config $config,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->queueRepository = $queueRepository;
        $this->reverseIndexRepository = $reverseIndexRepository;
        $this->config = $config;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    public function isWarmupEnabled()
    {
        return $this->config->isWarmupEnabled();
    }

    public function getRows()
    {
        if ($this->rows === null) {
            $this->rows = $this->queueRepository->getPage($this->getPageSize(), $this->getOffset(), $this->getFilters());
        }
        return $this->rows;
    }

    public function getTotalRows()
    {
        if ($this->totalRows === null) {
            $this->totalRows = $this->queueRepository->getTotalCount($this->getFilters());
        }
        return $this->totalRows;
    }

    public function getCurrentPage()
    {
        $page = (int)$this->getRequest()->getParam('page', 1);
        return max(1, min($page, $this->getTotalPages()));
    }

    public function getPageSize()
    {
        $pageSize = (int)$this->getRequest()->getParam('per_page', self::DEFAULT_PAGE_SIZE);
        return in_array($pageSize, self::PAGE_SIZE_OPTIONS, true) ? $pageSize : self::DEFAULT_PAGE_SIZE;
    }

    public function getPageSizeOptions()
    {
        return self::PAGE_SIZE_OPTIONS;
    }

    public function getTotalPages()
    {
        return max(1, (int)ceil($this->getTotalRows() / $this->getPageSize()));
    }

    public function getOffset()
    {
        return ($this->getCurrentPage() - 1) * $this->getPageSize();
    }

    public function getFirstItemNumber()
    {
        return $this->getTotalRows() ? $this->getOffset() + 1 : 0;
    }

    public function getLastItemNumber()
    {
        return $this->getOffset() + count($this->getRows());
    }

    public function getPagerUrl($page, $pageSize = null)
    {
        return $this->getUrl(
            'litespeed_litemage/warmup/queue',
            $this->getFilters() + [
                'page' => max(1, (int)$page),
                'per_page' => $pageSize === null ? $this->getPageSize() : (int)$pageSize,
            ]
        );
    }

    public function getFilters()
    {
        $filters = [];
        foreach ([
            'store_id',
            'profile_id',
            'mode',
            'status',
            'source_queue',
            'url',
        ] as $key) {
            $value = $this->getRequest()->getParam($key);
            if ($key === 'url') {
                $value = trim((string)$value);
            }
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        if (!empty($filters['url']) && (string)$this->getRequest()->getParam('url_exact', '') === '1') {
            $filters['url_exact'] = '1';
        }

        return $filters;
    }

    public function getStoreFilterOptions()
    {
        $options = $this->emptyFilterOption();
        $seenStoreIds = array_flip(array_map('intval', $this->queueRepository->getDistinctFilterValues('store_id')));
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            if ($seenStoreIds && !isset($seenStoreIds[$storeId])) {
                continue;
            }
            $options[] = [
                'value' => (string)$storeId,
                'label' => sprintf('%s (%d)', $store->getName(), $storeId),
            ];
        }

        return $options;
    }

    public function getProfileFilterOptions()
    {
        return array_merge($this->emptyFilterOption(), $this->queueRepository->getProfileFilterOptions());
    }

    public function getModeFilterOptions()
    {
        return array_merge($this->emptyFilterOption(), [
            ['value' => CrawlerMode::MODE_RUNNER, 'label' => __('Runner')],
            ['value' => CrawlerMode::MODE_WALKER, 'label' => __('Walker')],
        ]);
    }

    public function getStatusFilterOptions()
    {
        return array_merge($this->emptyFilterOption(), [
            ['value' => QueueStatus::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => QueueStatus::STATUS_RUNNING, 'label' => __('Running')],
            ['value' => QueueStatus::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => QueueStatus::STATUS_BLACKLISTED, 'label' => __('Blacklisted')],
        ]);
    }

    public function getSourceQueueFilterOptions()
    {
        $options = $this->emptyFilterOption();
        foreach ($this->queueRepository->getSourceQueueFilterOptions() as $row) {
            $source = (string)($row['source'] ?? '');
            $instance = (string)($row['source_instance_key'] ?? '');
            $options[] = [
                'value' => $source . '|' . $instance,
                'label' => $this->getSourceQueueLabel($source, $instance),
            ];
        }

        return $options;
    }

    public function getSourceWarnings()
    {
        $warnings = [];
        foreach ($this->queueRepository->getDisabledScheduledSourceCounts($this->config->getWarmupSources()) as $source => $rows) {
            $warnings[] = __(
                '%1 queued URL or source membership row(s) still belong to disabled source "%2". The next Build Queue run will remove disabled scheduled-source work automatically.',
                $rows,
                $this->getSourceLabel($source)
            );
        }

        return $warnings;
    }

    public function getWorkActionCounts()
    {
        $counts = $this->queueRepository->getWorkActionCounts();
        $counts['disabled_source'] = array_sum(
            $this->queueRepository->getDisabledScheduledSourceCounts($this->config->getWarmupSources())
        );
        return $counts;
    }

    public function hasDisabledSourceWork()
    {
        return (bool)$this->queueRepository->getDisabledScheduledSourceCounts($this->config->getWarmupSources());
    }

    public function getActiveFilterLabel()
    {
        $filters = $this->getFilters();
        if (!$filters) {
            return '';
        }

        $labels = [
            'store_id' => __('Store'),
            'profile_id' => __('Profile'),
            'mode' => __('Mode'),
            'status' => __('Status'),
            'source_queue' => __('Source / Queue'),
            'url' => __('URL'),
        ];
        $parts = [];
        foreach ($filters as $key => $value) {
            if ($key === 'url_exact') {
                continue;
            }
            $displayValue = (string)$value;
            if ($key === 'source_queue') {
                [$source, $instance] = $this->parseSourceQueueValue($displayValue);
                $displayValue = $this->getSourceQueueLabel($source, $instance);
            } elseif ($key === 'url' && !empty($filters['url_exact'])) {
                $displayValue .= ' (' . (string)__('exact') . ')';
            }
            $parts[] = (string)($labels[$key] ?? str_replace('_', ' ', $key)) . ': ' . $displayValue;
        }

        return implode(', ', $parts);
    }

    public function getFilterValue($key)
    {
        if ($key === 'url') {
            return trim((string)$this->getRequest()->getParam($key, ''));
        }

        return (string)$this->getRequest()->getParam($key, '');
    }

    public function isFilterSelected($key, $value)
    {
        return $this->getFilterValue($key) === (string)$value;
    }

    public function isUrlExactFilter()
    {
        return (string)$this->getRequest()->getParam('url_exact', '') === '1';
    }

    public function getProfileDisplayLabel(array $row)
    {
        $profileId = $row['profile_id'] === null ? 0 : (int)$row['profile_id'];
        if ($this->profileLabels === null) {
            $this->profileLabels = [];
            foreach ($this->getProfileFilterOptions() as $option) {
                if ((string)$option['value'] !== '') {
                    $this->profileLabels[(int)$option['value']] = (string)$option['label'];
                }
            }
        }

        return $this->profileLabels[$profileId] ?? ($profileId === 0 ? (string)__('Guest') : (string)__('Profile %1', $profileId));
    }

    public function getQueueStatusLabel(array $row)
    {
        if (($row['status'] ?? '') === QueueStatus::STATUS_WARMED && !empty($row['next_run_at'])) {
            return (string)__('Pending');
        }

        return (string)($row['status'] ?? '');
    }

    public function getFilterActionUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/queue');
    }

    public function getClearFilterUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/queue');
    }

    public function shorten($value, $length = 120)
    {
        $value = (string)$value;
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, max(0, $length - 3)) . '...';
    }

    public function getEffectiveQueueLabel(array $row)
    {
        return $this->getSourceQueueLabel(
            (string)($row['source'] ?? ''),
            (string)($row['source_instance_key'] ?? '')
        );
    }

    public function getAlsoFoundInLabel(array $row)
    {
        $labels = $this->getAlsoFoundInLabels($row);
        if (!$labels) {
            return '';
        }

        return implode(', ', array_slice($labels, 0, 3))
            . (count($labels) > 3 ? sprintf(' +%d', count($labels) - 3) : '');
    }

    public function getAlsoFoundInTitle(array $row)
    {
        $labels = $this->getAlsoFoundInLabels($row);
        if (!$labels) {
            return '';
        }

        return (string)__(
            'Also found in these lower-priority source memberships. They are covered by this effective work row and do not create separate crawler requests: %1',
            implode(', ', $labels)
        );
    }

    public function getReverseIndexSummary()
    {
        return $this->reverseIndexRepository->getSummary();
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

    public function getGenerateUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/generate');
    }

    public function getProcessUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/process');
    }

    public function getPauseUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/pause');
    }

    public function getResumeUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/resume');
    }

    public function getRetryFailedUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/retryfailed');
    }

    public function getClearDisabledSourcesUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/cleardisabledsources');
    }

    public function getMassActionUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/massaction');
    }

    private function getSourceLabel($source)
    {
        $labels = [
            'manual' => __('Legacy Custom URL'),
            'sitemap' => __('Sitemap'),
            'url_rewrite' => __('Magento URL Rewrites'),
            'text_file' => __('Text/CSV File'),
            'recently_seen' => __('Recently Seen URLs'),
            'purge_entity' => __('Resolved Entity URL'),
            'purge_reverse_index' => __('Reverse Index URL'),
            'purge_broad' => __('Legacy Purge All Work'),
        ];

        return $labels[$source] ?? $source;
    }

    private function getSourceQueueLabel($source, $instance)
    {
        $source = (string)$source;
        $instance = (string)$instance;
        $sourceLabel = (string)$this->getSourceLabel($source);
        if ($instance !== '' && $instance !== $source) {
            return sprintf('%s (%s)', $this->shortInstanceLabel($instance), $sourceLabel);
        }

        return $sourceLabel;
    }

    private function getAlsoFoundInLabels(array $row)
    {
        $labels = [];
        $currentSource = (string)($row['source'] ?? '');
        $currentInstance = (string)($row['source_instance_key'] ?? $currentSource);
        foreach (($row['also_source_memberships'] ?? []) as $membership) {
            $source = (string)($membership['source_code'] ?? '');
            $instance = (string)($membership['source_instance_key'] ?? $source);
            if ($source === $currentSource && $instance === $currentInstance) {
                continue;
            }
            if ($source === $currentSource && strpos($currentSource, 'purge_') === 0) {
                continue;
            }
            if (strpos($source, 'purge_') === 0 && strpos($currentSource, 'purge_') !== 0) {
                continue;
            }
            $labels[] = $this->getSourceQueueLabel($source, $instance);
        }
        return array_values(array_unique($labels));
    }

    private function shortInstanceLabel($instance)
    {
        $instance = trim((string)$instance);
        $labels = [
            'manual' => __('Legacy Custom URL'),
            'url_rewrite' => __('Magento URL Rewrites'),
            'recently_seen' => __('Recently Seen URLs'),
            'purge_direct' => __('Purge Event'),
        ];
        if (isset($labels[$instance])) {
            return (string)$labels[$instance];
        }

        $path = parse_url($instance, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $instance = $path;
        }
        $instance = str_replace('\\', '/', $instance);
        $basename = basename($instance);
        return $this->normalizeStoreSuffix($basename !== '' ? $basename : $instance);
    }

    private function normalizeStoreSuffix($label)
    {
        return preg_replace('/::stores=([0-9]+)$/', '::store=$1', (string)$label);
    }

    private function parseSourceQueueValue($value)
    {
        $parts = explode('|', (string)$value, 2);
        return [
            (string)($parts[0] ?? ''),
            (string)($parts[1] ?? ''),
        ];
    }

    private function emptyFilterOption()
    {
        return [['value' => '', 'label' => __('All')]];
    }
}
