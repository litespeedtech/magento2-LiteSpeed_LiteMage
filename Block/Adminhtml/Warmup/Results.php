<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Warmup\ResultRepository;
use Litespeed\Litemage\Model\Warmup\QueueStatus;
use Litespeed\Litemage\Model\Warmup\QueueWorkType;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class Results extends Template
{
    private const DEFAULT_PAGE_SIZE = 100;
    private const PAGE_SIZE_OPTIONS = [20, 50, 100, 200, 500];
    private const XML_PATH_RESULT_RETENTION_DAYS = 'litemage/warmup/result_retention_days';

    protected $_template = 'Litespeed_Litemage::warmup/results.phtml';

    /**
     * @var ResultRepository
     */
    private $resultRepository;

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
        ResultRepository $resultRepository,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->resultRepository = $resultRepository;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    public function getRows()
    {
        if ($this->rows === null) {
            $this->rows = $this->resultRepository->getPage($this->getPageSize(), $this->getOffset(), $this->getFilters());
        }
        return $this->rows;
    }

    public function getTotalRows()
    {
        if ($this->totalRows === null) {
            $this->totalRows = $this->resultRepository->getTotalCount($this->getFilters());
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
            'litespeed_litemage/warmup/results',
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
            'url',
            'store_id',
            'profile_id',
            'work_type',
            'status',
            'lane',
            'http_status',
            'cache_status',
            'error_text',
            'date_from',
            'date_to',
        ] as $key) {
            $value = $key === 'lane'
                ? $this->getFilterValue('lane')
                : $this->getRequest()->getParam($key);
            if (in_array($key, ['url', 'error_text'], true)) {
                $value = trim((string)$value);
            } elseif (in_array($key, ['date_from', 'date_to'], true)) {
                $value = $this->normalizeDateFilterValue($value);
            }
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    public function getStoreFilterOptions()
    {
        $options = $this->emptyFilterOption();
        $seenStoreIds = array_flip(array_map('intval', $this->resultRepository->getDistinctFilterValues('store_id')));
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

        return $this->appendCurrentFilterOption('store_id', $options);
    }

    public function getProfileFilterOptions()
    {
        return $this->appendCurrentFilterOption(
            'profile_id',
            array_merge($this->emptyFilterOption(), $this->resultRepository->getProfileFilterOptions())
        );
    }

    public function getStatusFilterOptions()
    {
        return $this->appendCurrentFilterOption('status', array_merge($this->emptyFilterOption(), [
            ['value' => QueueStatus::STATUS_WARMED, 'label' => __('Warmed')],
            ['value' => QueueStatus::STATUS_SKIPPED, 'label' => __('Skipped')],
            ['value' => QueueStatus::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => QueueStatus::STATUS_BLACKLISTED, 'label' => __('Blacklisted')],
        ]));
    }

    public function getWorkTypeFilterOptions()
    {
        return $this->appendCurrentFilterOption('work_type', array_merge($this->emptyFilterOption(), [
            ['value' => QueueWorkType::TYPE_SCHEDULED, 'label' => __('Scheduled')],
            ['value' => QueueWorkType::TYPE_DELTA, 'label' => __('Purge Delta')],
        ]));
    }

    public function getLaneFilterOptions()
    {
        $options = $this->emptyFilterOption();
        foreach ($this->resultRepository->getDistinctLaneFilterValues() as $value) {
            $options[] = [
                'value' => $value,
                'label' => $this->shorten($this->getLaneLabel($value), 90),
            ];
        }

        return $this->appendCurrentFilterOption('lane', $options);
    }

    public function getHttpStatusFilterOptions()
    {
        $options = $this->emptyFilterOption();
        if ($this->resultRepository->hasEmptyFilterValue('http_status')) {
            $options[] = ['value' => ResultRepository::FILTER_EMPTY, 'label' => __('No response')];
        }
        foreach ($this->resultRepository->getDistinctFilterValues('http_status') as $value) {
            $options[] = [
                'value' => (string)(int)$value,
                'label' => (string)(int)$value,
            ];
        }

        return $this->appendCurrentFilterOption('http_status', $options);
    }

    public function getCacheStatusFilterOptions()
    {
        $options = $this->emptyFilterOption();
        if ($this->resultRepository->hasEmptyFilterValue('cache_status')) {
            $options[] = ['value' => ResultRepository::FILTER_EMPTY, 'label' => __('Not cacheable')];
        }
        foreach ($this->resultRepository->getDistinctFilterValues('cache_status') as $value) {
            $options[] = [
                'value' => (string)$value,
                'label' => $this->getCacheDisplay($value),
            ];
        }

        return $this->appendCurrentFilterOption('cache_status', $options);
    }

    public function getActiveFilterLabel()
    {
        $filters = $this->getFilters();
        if (!$filters) {
            return '';
        }

        $labels = [
            'url' => __('URL'),
            'store_id' => __('Store'),
            'profile_id' => __('Profile'),
            'work_type' => __('Work Type'),
            'status' => __('Result Status'),
            'lane' => __('Lane'),
            'http_status' => __('HTTP Code'),
            'cache_status' => __('Cache'),
            'error_text' => __('Error'),
            'date_from' => __('From'),
            'date_to' => __('To'),
        ];
        $parts = [];
        foreach ($filters as $key => $value) {
            $parts[] = (string)($labels[$key] ?? str_replace('_', ' ', $key)) . ': ' . $this->getFilterDisplayValue($key, $value);
        }

        return implode(', ', $parts);
    }

    public function getFilterValue($key)
    {
        if ($key === 'lane') {
            $lane = trim((string)$this->getRequest()->getParam('lane', ''));
            if ($lane !== '') {
                return $lane;
            }
            return trim((string)$this->getRequest()->getParam('source_instance_key', ''));
        }
        if (in_array($key, ['url', 'error_text'], true)) {
            return trim((string)$this->getRequest()->getParam($key, ''));
        }
        if (in_array($key, ['date_from', 'date_to'], true)) {
            return $this->normalizeDateFilterValue($this->getRequest()->getParam($key, ''));
        }

        return (string)$this->getRequest()->getParam($key, '');
    }

    public function isFilterSelected($key, $value)
    {
        return $this->getFilterValue($key) === (string)$value;
    }

    public function getFilterActionUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/results');
    }

    public function getClearFilterUrl()
    {
        return $this->getUrl('litespeed_litemage/warmup/results');
    }

    public function getMinResultDate()
    {
        $days = $this->getResultRetentionDays();
        return date('Y-m-d', strtotime('-' . $days . ' days'));
    }

    public function getMaxResultDate()
    {
        return date('Y-m-d');
    }

    public function shorten($value, $length = 120)
    {
        $value = (string)$value;
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, max(0, $length - 3)) . '...';
    }

    public function getCacheDisplay($cacheStatus)
    {
        $normalized = $this->normalizeCacheStatus($cacheStatus);
        if ($normalized === '') {
            return __('Not cacheable');
        }

        if (strpos($normalized, 'hit') !== false) {
            return __('Cache Hit');
        }
        if ($normalized === 'miss' || strpos($normalized, 'miss') !== false) {
            return __('Cache Refreshed');
        }

        return trim((string)$cacheStatus);
    }

    public function getCacheClass($cacheStatus)
    {
        $normalized = $this->normalizeCacheStatus($cacheStatus);
        if ($normalized === '') {
            return 'litemage-cache-status litemage-cache-status-none';
        }

        if (strpos($normalized, 'hit') !== false) {
            return 'litemage-cache-status litemage-cache-status-hit';
        }
        if ($normalized === 'miss' || strpos($normalized, 'miss') !== false) {
            return 'litemage-cache-status litemage-cache-status-miss';
        }

        return 'litemage-cache-status';
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

    public function getWorkTypeLabel($workType)
    {
        return (string)$workType === QueueWorkType::TYPE_DELTA
            ? __('Purge Delta')
            : __('Scheduled');
    }

    public function getLaneDisplayLabel(array $row)
    {
        $source = (string)($row['source'] ?? '');
        $instance = (string)($row['source_instance_key'] ?? '');
        if ($this->isPurgeUrlSource($source)) {
            return $this->getSourceLabel($source);
        }
        if ($instance !== '') {
            return $this->getLaneLabel($instance);
        }

        return $this->getSourceLabel($source);
    }

    public function getAlsoFoundSourceLabels(array $row)
    {
        $source = (string)($row['source'] ?? '');
        $labels = [];
        foreach (explode(',', (string)($row['source_flags'] ?? '')) as $flag) {
            $flag = trim($flag);
            if ($flag === '' || $flag === $source) {
                continue;
            }
            $labels[] = (string)$this->getSourceLabel($flag);
        }

        return array_values(array_unique($labels));
    }

    private function normalizeCacheStatus($cacheStatus)
    {
        return strtolower(str_replace(' ', '', trim((string)$cacheStatus)));
    }

    private function normalizeDateFilterValue($value)
    {
        $value = trim((string)$value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return max($this->getMinResultDate(), min($value, $this->getMaxResultDate()));
    }

    private function getResultRetentionDays()
    {
        $days = (int)$this->_scopeConfig->getValue(self::XML_PATH_RESULT_RETENTION_DAYS);
        return max(1, $days ?: 30);
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

    private function getSourceInstanceLabel($instance)
    {
        $instance = trim((string)$instance);
        $labels = [
            'manual' => __('Legacy Custom URL'),
            'url_rewrite' => __('Magento URL Rewrites'),
            'recently_seen' => __('Recently Seen URLs'),
            'purge_direct' => __('Purge Event'),
            'purge_entity' => __('Resolved Entity URL'),
            'purge_reverse_index' => __('Reverse Index URL'),
            'purge_broad' => __('Legacy Purge All Work'),
        ];
        if (isset($labels[$instance])) {
            return (string)$labels[$instance];
        }

        $path = parse_url($instance, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $instance = $path;
        }
        $instance = str_replace('\\', '/', $instance);
        if (strpos($instance, 'var/litemage/warmup/') === 0) {
            $instance = substr($instance, strlen('var/litemage/warmup/'));
        }

        $base = basename($instance);
        return $this->normalizeStoreSuffix($base !== '' ? $base : $instance);
    }

    private function normalizeStoreSuffix($label)
    {
        return preg_replace('/::stores=([0-9]+)$/', '::store=$1', (string)$label);
    }

    private function getLaneLabel($value)
    {
        $value = (string)$value;
        if ($this->isKnownSource($value)) {
            return $this->getSourceLabel($value);
        }

        return $this->getSourceInstanceLabel($value);
    }

    private function getFilterDisplayValue($key, $value)
    {
        if ($value === ResultRepository::FILTER_EMPTY) {
            if ($key === 'http_status') {
                return (string)__('No response');
            }
            if ($key === 'cache_status') {
                return (string)__('Not cacheable');
            }
        }

        if ($key === 'lane') {
            return (string)$this->getLaneLabel($value);
        }
        if ($key === 'work_type') {
            return (string)$this->getWorkTypeLabel($value);
        }
        if ($key === 'cache_status') {
            return (string)$this->getCacheDisplay($value);
        }

        return (string)$value;
    }

    private function isKnownSource($source)
    {
        return in_array((string)$source, [
            'manual',
            'sitemap',
            'url_rewrite',
            'text_file',
            'recently_seen',
            'purge_entity',
            'purge_reverse_index',
            'purge_broad',
        ], true);
    }

    private function isPurgeUrlSource($source)
    {
        return in_array((string)$source, ['purge_entity', 'purge_reverse_index', 'purge_broad'], true);
    }

    private function emptyFilterOption()
    {
        return [['value' => '', 'label' => __('All')]];
    }

    private function appendCurrentFilterOption($key, array $options)
    {
        $value = $this->getFilterValue($key);
        if ($value === '') {
            return $options;
        }

        foreach ($options as $option) {
            if ((string)$option['value'] === $value) {
                return $options;
            }
        }

        $options[] = [
            'value' => $value,
            'label' => $this->getFilterDisplayValue($key, $value),
        ];

        return $options;
    }
}
