<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Warmup\RunnerEventRepository;
use Litespeed\Litemage\Model\Warmup\Worker;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Process extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Litespeed_Litemage::warmup_queue';

    /**
     * @var Worker
     */
    private $worker;

    /**
     * @var RunnerEventRepository
     */
    private $runnerEventRepository;

    public function __construct(
        Context $context,
        Worker $worker,
        RunnerEventRepository $runnerEventRepository
    ) {
        $this->worker = $worker;
        $this->runnerEventRepository = $runnerEventRepository;
        parent::__construct($context);
    }

    public function execute()
    {
        $eventId = $this->runnerEventRepository->start(
            RunnerEventRepository::RUNNER_PROCESS,
            RunnerEventRepository::MODE_ADMIN
        );
        try {
            $stats = $this->worker->process();
            $this->runnerEventRepository->finish($eventId, $this->getEventStatus($stats), $stats);
            if (!empty($stats['disabled'])) {
                $this->messageManager->addWarningMessage(__('LiteMage warmer is disabled.'));
            } elseif (!empty($stats['load_deferred'])) {
                $this->messageManager->addWarningMessage(__(
                    'LiteMage warmer skipped because server load %1 is at or above configured limit %2.',
                    number_format((float)$stats['load_average'], 4),
                    number_format((float)$stats['load_limit'], 4)
                ));
            } else {
                $this->messageManager->addSuccessMessage(__(
                    'LiteMage warmup queue processing complete: claimed %1, warmed %2, skipped %3, failed %4, lane locked %5.',
                    $stats['claimed'],
                    $stats['warmed'],
                    $stats['skipped'],
                    $stats['failed'],
                    $stats['lane_locked'] ?? 0
                ));
            }
        } catch (\Exception $e) {
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_FAILED,
                [],
                $e->getMessage()
            );
            $this->messageManager->addErrorMessage(__('LiteMage warmup queue processing failed: %1', $e->getMessage()));
        }
        return $this->resultRedirectFactory->create()->setPath('litespeed_litemage/warmup/queue');
    }

    private function getEventStatus(array $stats)
    {
        if (!empty($stats['disabled'])) {
            return RunnerEventRepository::STATUS_DISABLED;
        }
        if (!empty($stats['load_deferred'])) {
            return RunnerEventRepository::STATUS_LOAD_SKIPPED;
        }

        return RunnerEventRepository::STATUS_SUCCESS;
    }
}
