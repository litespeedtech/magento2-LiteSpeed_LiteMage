<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Warmup\PurgeEventRepository;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class PurgeEvents extends Template
{
    private const DEFAULT_PAGE_SIZE = 100;
    private const PAGE_SIZE_OPTIONS = [20, 50, 100, 200, 500];

    /**
     * @var string
     */
    protected $_template = 'Litespeed_Litemage::warmup/purge_events.phtml';

    /**
     * @var PurgeEventRepository
     */
    private $purgeEventRepository;

    /**
     * @var array|null
     */
    private $events;

    /**
     * @var int|null
     */
    private $totalRows;

    public function __construct(
        Context $context,
        PurgeEventRepository $purgeEventRepository,
        array $data = []
    ) {
        $this->purgeEventRepository = $purgeEventRepository;
        parent::__construct($context, $data);
    }

    public function getEvents()
    {
        if ($this->events === null) {
            $this->events = $this->purgeEventRepository->getPage($this->getPageSize(), $this->getOffset());
        }
        return $this->events;
    }

    public function getTotalRows()
    {
        if ($this->totalRows === null) {
            $this->totalRows = $this->purgeEventRepository->getTotalCount();
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
        return $this->getOffset() + count($this->getEvents());
    }

    public function getPagerUrl($page, $pageSize = null)
    {
        return $this->getUrl(
            'litespeed_litemage/warmup/purgeevents',
            [
                'page' => max(1, (int)$page),
                'per_page' => $pageSize === null ? $this->getPageSize() : (int)$pageSize,
            ]
        );
    }

    public function shorten($value, $length = 160)
    {
        $value = (string)$value;
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, max(0, $length - 3)) . '...';
    }

    public function isLong($value, $length = 160)
    {
        return strlen((string)$value) > $length;
    }

    public function getWarmupWorkLines(array $event)
    {
        $lines = [];
        $queued = (int)($event['queued_count'] ?? 0);
        $entityQueued = (int)($event['entity_queued_count'] ?? 0);
        $reverseQueued = (int)($event['reverse_index_queued_count'] ?? 0);
        $restartMatched = (int)($event['restart_matched_count'] ?? ($event['restarted_count'] ?? 0));
        $restartChanged = (int)($event['restart_changed_count'] ?? ($event['restarted_count'] ?? 0));
        $clearedDelta = (int)($event['cleared_delta_count'] ?? 0);

        if ((int)($event['is_broad'] ?? 0) || (int)($event['is_purge_all'] ?? 0)) {
            $lines[] = ['label' => __('Covered'), 'value' => $restartMatched];
            if ($restartChanged > 0) {
                $lines[] = ['label' => __('Reset'), 'value' => $restartChanged];
            }
            if ($clearedDelta > 0) {
                $lines[] = ['label' => __('Delta cleared'), 'value' => $clearedDelta];
            }
            return $lines;
        }

        $lines[] = ['label' => __('Queued'), 'value' => $queued];
        if ($entityQueued > 0) {
            $lines[] = ['label' => __('Direct'), 'value' => $entityQueued];
        }
        if ($reverseQueued > 0) {
            $lines[] = ['label' => __('Related'), 'value' => $reverseQueued];
        }

        return $lines;
    }
}
