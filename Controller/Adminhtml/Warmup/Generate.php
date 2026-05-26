<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Warmup\QueueGenerator;
use Litespeed\Litemage\Model\Warmup\RunnerEventRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Litespeed_Litemage::warmup_queue';

    /**
     * @var QueueGenerator
     */
    private $queueGenerator;

    /**
     * @var RunnerEventRepository
     */
    private $runnerEventRepository;

    public function __construct(
        Context $context,
        QueueGenerator $queueGenerator,
        RunnerEventRepository $runnerEventRepository
    ) {
        $this->queueGenerator = $queueGenerator;
        $this->runnerEventRepository = $runnerEventRepository;
        parent::__construct($context);
    }

    public function execute()
    {
        $eventId = $this->runnerEventRepository->start(
            RunnerEventRepository::RUNNER_GENERATE,
            RunnerEventRepository::MODE_ADMIN
        );
        try {
            $stats = $this->queueGenerator->generate();
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_SUCCESS,
                $stats,
                !empty($stats['errors']) ? implode("\n", array_slice($stats['errors'], 0, 10)) : null
            );
            if (!empty($stats['errors'])) {
                $this->messageManager->addErrorMessage(implode(' ', array_slice($stats['errors'], 0, 3)));
            }
            foreach (($stats['source_stats'] ?? []) as $sourceStats) {
                $this->messageManager->addNoticeMessage(__(
                    'Source %1: rows seen %2, generated %3, skipped %4, errors %5.',
                    $sourceStats['source'] ?? 'unknown',
                    (int)($sourceStats['rows_seen'] ?? 0),
                    (int)($sourceStats['generated'] ?? 0),
                    (int)($sourceStats['skipped'] ?? 0),
                    count($sourceStats['errors'] ?? [])
                ));
            }
            $this->messageManager->addSuccessMessage(__(
                'LiteMage warmup queue build complete: seen %1, updated %2, skipped %3.',
                $stats['seen'],
                $stats['enqueued'],
                $stats['skipped']
            ));
        } catch (\Exception $e) {
            $this->runnerEventRepository->finish(
                $eventId,
                RunnerEventRepository::STATUS_FAILED,
                [],
                $e->getMessage()
            );
            $this->messageManager->addErrorMessage(__('LiteMage warmup queue build failed: %1', $e->getMessage()));
        }
        return $this->resultRedirectFactory->create()->setPath('litespeed_litemage/warmup/queue');
    }
}
