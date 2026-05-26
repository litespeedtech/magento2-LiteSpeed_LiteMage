<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\HttpWarmupClient;
use Litespeed\Litemage\Model\Warmup\LaneLockRepository;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Litespeed\Litemage\Model\Warmup\QueueStatus;
use Litespeed\Litemage\Model\Warmup\ResultRepository;
use Litespeed\Litemage\Model\Warmup\VaryProfileResolver;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Massaction extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Litespeed_Litemage::warmup_queue';

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var ResultRepository
     */
    private $resultRepository;

    /**
     * @var HttpWarmupClient
     */
    private $httpWarmupClient;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var LaneLockRepository
     */
    private $laneLockRepository;

    /**
     * @var Config
     */
    private $config;

    public function __construct(
        Context $context,
        QueueRepository $queueRepository,
        ResultRepository $resultRepository,
        HttpWarmupClient $httpWarmupClient,
        VaryProfileResolver $varyProfileResolver,
        LaneLockRepository $laneLockRepository,
        Config $config
    ) {
        $this->queueRepository = $queueRepository;
        $this->resultRepository = $resultRepository;
        $this->httpWarmupClient = $httpWarmupClient;
        $this->varyProfileResolver = $varyProfileResolver;
        $this->laneLockRepository = $laneLockRepository;
        $this->config = $config;
        parent::__construct($context);
    }

    public function execute()
    {
        $action = (string)$this->getRequest()->getParam('mass_action');
        $rows = $this->queueRepository->getByIds((array)$this->getRequest()->getParam('selected', []));
        if (!$action || !$rows) {
            $this->messageManager->addWarningMessage(__('Select queue rows and an action.'));
            return $this->redirectToQueue();
        }

        try {
            switch ($action) {
                case 'blacklist':
                    $this->setBlacklisted($rows, true);
                    break;
                case 'unblacklist':
                    $this->setBlacklisted($rows, false);
                    break;
                case 'warm':
                    $this->warmRows($rows);
                    break;
                case 'curl':
                    $this->showCurlCommands($rows);
                    break;
                default:
                    $this->messageManager->addErrorMessage(__('Unsupported warmup queue action.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->redirectToQueue();
    }

    private function setBlacklisted(array $rows, $blacklisted)
    {
        foreach ($rows as $row) {
            $this->queueRepository->setBlacklisted($row, $blacklisted);
        }

        $this->messageManager->addSuccessMessage(__(
            '%1 selected LiteMage warmup URL(s) %2.',
            count($rows),
            $blacklisted ? __('blacklisted') : __('unblacklisted')
        ));
    }

    private function warmRows(array $rows)
    {
        $groups = [];
        $stats = ['warmed' => 0, 'skipped' => 0, 'failed' => 0, 'lane_locked' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === QueueStatus::STATUS_BLACKLISTED) {
                $stats['skipped']++;
                continue;
            }
            $row['_profile'] = $this->resolveProfile($row);
            $groups[$this->getLaneKey($row)][] = $row;
        }

        $lockOwner = $this->getLockOwner();
        foreach ($groups as $groupRows) {
            $requiresLaneLock = $this->requiresExecutionLaneLock($groupRows);
            $lane = $this->getLane($groupRows);
            $laneLocked = false;
            if ($requiresLaneLock) {
                $laneLocked = $this->laneLockRepository->acquire(
                    $lane['profile_id'],
                    $lane['mode'],
                    $lane['store_id'],
                    $lockOwner,
                    max(300, $this->config->getWarmupMaxRuntime() * 2)
                );
                if (!$laneLocked) {
                    $stats['lane_locked'] += count($groupRows);
                    continue;
                }
            }

            try {
                $results = $this->httpWarmupClient->warmBatch(
                    $groupRows,
                    $requiresLaneLock ? 1 : $this->config->getWarmupConcurrency()
                );
                $this->recordWarmResults($groupRows, $results, $stats);
            } finally {
                if ($laneLocked) {
                    $this->laneLockRepository->release(
                        $lane['profile_id'],
                        $lane['mode'],
                        $lane['store_id'],
                        $lockOwner
                    );
                }
            }
        }

        $this->messageManager->addSuccessMessage(__(
            'Selected warmup complete: warmed %1, skipped %2, failed %3, lane locked %4.',
            $stats['warmed'],
            $stats['skipped'],
            $stats['failed'],
            $stats['lane_locked']
        ));
    }

    private function recordWarmResults(array $rows, array $results, array &$stats)
    {
        foreach ($rows as $row) {
            $result = $results[$row['queue_id']] ?? [
                'status' => QueueStatus::STATUS_FAILED,
                'http_status' => null,
                'response_time_ms' => null,
                'cache_status' => null,
                'final_url' => $row['url'],
                'headers_summary' => null,
                'error' => 'Warmup batch did not return a result.',
            ];
            $resultId = $this->resultRepository->create($row, $result);

            if ((int)($result['http_status'] ?? 0) === 404) {
                $this->queueRepository->markGone(
                    $row['queue_id'],
                    $resultId,
                    $result['error'] ?? 'HTTP 404; URL deactivated.'
                );
                $stats['skipped']++;
            } elseif ($result['status'] === QueueStatus::STATUS_WARMED) {
                $this->queueRepository->markSuccess($row['queue_id'], $resultId, $result['cache_status'] ?? null);
                $stats['warmed']++;
            } elseif ($result['status'] === QueueStatus::STATUS_SKIPPED) {
                $this->queueRepository->markSkipped($row['queue_id'], $resultId, $result['error'] ?? 'Skipped');
                $stats['skipped']++;
            } else {
                $this->queueRepository->markFailure($row['queue_id'], $resultId, $result['error'] ?? 'Warmup failed');
                $stats['failed']++;
            }
        }
    }

    private function showCurlCommands(array $rows)
    {
        $commands = [];
        $skipped = 0;
        foreach (array_slice($rows, 0, 5) as $row) {
            try {
                $commands[] = $this->httpWarmupClient->buildCurlCommand(
                    $row['url'],
                    $row['mode'],
                    $this->resolveProfile($row),
                    $row['store_id'] ?? null
                );
            } catch (\RuntimeException $e) {
                $skipped++;
            }
        }

        if ($commands) {
            $this->messageManager->addNoticeMessage(implode("\n", $commands));
        }
        if ($skipped > 0) {
            $this->messageManager->addWarningMessage(__(
                'Skipped %1 cURL command(s) because representative customer session profiles contain signed login tokens.',
                $skipped
            ));
        }
        if (count($rows) > 5) {
            $this->messageManager->addNoticeMessage(__('Only the first 5 cURL commands are shown.'));
        }
    }

    private function resolveProfile(array $row)
    {
        return $this->varyProfileResolver->resolve(empty($row['profile_id']) ? null : (int)$row['profile_id']);
    }

    private function getLaneKey(array $row)
    {
        return implode(':', [
            isset($row['profile_id']) ? (int)$row['profile_id'] : 0,
            (string)$row['mode'],
            (int)$row['store_id'],
        ]);
    }

    private function getLane(array $rows)
    {
        $first = reset($rows);
        return [
            'profile_id' => isset($first['profile_id']) ? (int)$first['profile_id'] : 0,
            'mode' => (string)$first['mode'],
            'store_id' => (int)$first['store_id'],
        ];
    }

    private function requiresExecutionLaneLock(array $rows)
    {
        foreach ($rows as $row) {
            $profile = $row['_profile'] ?? [];
            if (!empty($profile['customer_id']) && !empty($profile['customer_session'])) {
                return true;
            }
        }
        return false;
    }

    private function getLockOwner()
    {
        return gethostname() . ':admin:' . getmypid();
    }

    private function redirectToQueue()
    {
        return $this->resultRedirectFactory->create()->setPath('litespeed_litemage/warmup/queue');
    }
}
