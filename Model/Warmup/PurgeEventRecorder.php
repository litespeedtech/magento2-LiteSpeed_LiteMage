<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Magento\Framework\App\ObjectManager;

class PurgeEventRecorder
{
    private const BROAD_TAGS = ['*', 'store', 'MB', 'topnav'];
    private const DIRECT_PURGE_SOURCE = 'purge_direct';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var PurgeEventRepository
     */
    private $purgeEventRepository;

    /**
     * @var EntityUrlResolver
     */
    private $entityUrlResolver;

    /**
     * @var UrlNormalizer
     */
    private $urlNormalizer;

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var ReverseIndexRepository
     */
    private $reverseIndexRepository;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var QueueVariantConfig
     */
    private $queueVariantConfig;

    /**
     * @var StoreWarmupPolicyConfig
     */
    private $storeWarmupPolicyConfig;

    /**
     * @var RunnerEventRepository
     */
    private $runnerEventRepository;

    public function __construct(
        Config $config,
        PurgeEventRepository $purgeEventRepository,
        EntityUrlResolver $entityUrlResolver,
        UrlNormalizer $urlNormalizer,
        QueueRepository $queueRepository,
        ReverseIndexRepository $reverseIndexRepository,
        VaryProfileResolver $varyProfileResolver,
        QueueVariantConfig $queueVariantConfig,
        ?StoreWarmupPolicyConfig $storeWarmupPolicyConfig = null,
        ?RunnerEventRepository $runnerEventRepository = null
    ) {
        $this->config = $config;
        $this->purgeEventRepository = $purgeEventRepository;
        $this->entityUrlResolver = $entityUrlResolver;
        $this->urlNormalizer = $urlNormalizer;
        $this->queueRepository = $queueRepository;
        $this->reverseIndexRepository = $reverseIndexRepository;
        $this->varyProfileResolver = $varyProfileResolver;
        $this->queueVariantConfig = $queueVariantConfig;
        $this->storeWarmupPolicyConfig = $storeWarmupPolicyConfig
            ?: ObjectManager::getInstance()->get(StoreWarmupPolicyConfig::class);
        $this->runnerEventRepository = $runnerEventRepository
            ?: ObjectManager::getInstance()->get(RunnerEventRepository::class);
    }

    public function record(array $tags, $reason, $isPurgeAll = false)
    {
        if (!$this->config->isWarmupEnabled()) {
            return;
        }

        $isBroad = $isPurgeAll || $this->hasBroadTags($tags);
        if (!$this->config->isWarmupDeltaEnabled() && !$isBroad) {
            return;
        }

        $entities = $this->extractEntities($tags);
        $profiles = $isBroad ? [] : $this->getPurgeProfiles();
        $queueConfig = $this->queueVariantConfig->getQueueConfig(
            self::DIRECT_PURGE_SOURCE,
            self::DIRECT_PURGE_SOURCE,
            $this->config->getWarmupPurgeEntitySourcePriority()
        );
        $queued = 0;
        $queuedKeys = [];
        $clearedDelta = 0;
        $entityQueued = 0;
        $reverseQueued = 0;
        $restarted = 0;
        $restartMatched = 0;
        $restartChanged = 0;
        if ($isBroad) {
            // Purge All supersedes transient delta work, including rows already claimed by a worker.
            $clearedDelta = $this->queueRepository->clearDeltaWork(true);
            $restartStats = $this->queueRepository->restartScheduledWork(
                $this->storeWarmupPolicyConfig->getAllowedStoreIds()
            );
            if (is_array($restartStats)) {
                $restartMatched = (int)($restartStats['matched'] ?? 0);
                $restartChanged = (int)($restartStats['changed'] ?? 0);
            } else {
                $restartMatched = (int)$restartStats;
                $restartChanged = (int)$restartStats;
            }
            $restarted = $restartChanged;
            $queued += $restartMatched;
            $this->runnerEventRepository->record(
                RunnerEventRepository::RUNNER_PURGE_ALL,
                RunnerEventRepository::MODE_CRON,
                RunnerEventRepository::STATUS_SUCCESS,
                [
                    'restarted_regular_urls' => $restartMatched,
                    'changed_regular_urls' => $restartChanged,
                    'cleared_delta_urls' => $clearedDelta,
                    'purge_all' => $isPurgeAll ? 1 : 0,
                ]
            );
        } elseif ($queueConfig['enabled']) {
            foreach ($this->entityUrlResolver->resolve($entities) as $item) {
                try {
                    $normalized = $this->urlNormalizer->normalize($item['url'], $item['store_id']);
                    if (!$this->storeWarmupPolicyConfig->isStoreEnabled((int)$normalized['store_id'])) {
                        continue;
                    }
                    foreach ($profiles as $profile) {
                        if (!$this->queueVariantConfig->isProfileApplicableToStore($profile, (int)$normalized['store_id'])) {
                            continue;
                        }
                        if (!$this->storeWarmupPolicyConfig->isProfileAllowedForStore($profile, (int)$normalized['store_id'])) {
                            continue;
                        }
                        $variantConfig = $this->queueVariantConfig->getVariantConfig($queueConfig, $profile);
                        if (!$variantConfig['enabled']) {
                            continue;
                        }
                        $priority = $this->queueVariantConfig->calculateEffectivePriority(
                            $queueConfig['priority'],
                            null,
                            $variantConfig['offset'],
                            $this->storeWarmupPolicyConfig->getPriorityOffset((int)$normalized['store_id'])
                        );
                        $queueId = $this->queueRepository->enqueue(
                            $normalized + [
                                'source' => 'purge_entity',
                                'page_type' => $item['page_type'],
                                'entity_type' => $item['entity_type'],
                                'entity_id' => $item['entity_id'],
                                'source_instance_key' => self::DIRECT_PURGE_SOURCE,
                                'source_priority' => $queueConfig['priority'],
                                'url_priority' => null,
                                'effective_priority' => $priority,
                                'priority' => $priority,
                                'is_urgent' => 1,
                                'work_type' => QueueWorkType::TYPE_DELTA,
                            ],
                            $this->config->getWarmupDefaultDeltaMode(),
                            'purge_entity',
                            $this->queueVariantConfig->getProfileId($profile),
                            $priority
                        );
                        if ($queueId) {
                            $queued++;
                            $entityQueued++;
                            $queuedKeys[$this->getUrlKey($normalized, $profile)] = true;
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore individual URL failures; the purge event still records the tag set.
                }
            }

            $reverseQueued = $this->enqueueReverseIndexUrls($tags, $queuedKeys, $profiles, $queueConfig);
            $queued += $reverseQueued;
        }

        $this->purgeEventRepository->create([
            'source' => 'purge',
            'reason' => $reason,
            'tags' => $tags,
            'is_broad' => $isBroad ? 1 : 0,
            'is_purge_all' => $isPurgeAll ? 1 : 0,
            'entity_count' => $this->countEntities($entities),
            'queued_count' => $queued,
            'entity_queued_count' => $entityQueued,
            'reverse_index_queued_count' => $reverseQueued,
            'restarted_count' => $restarted,
            'restart_matched_count' => $restartMatched,
            'restart_changed_count' => $restartChanged,
            'cleared_delta_count' => $clearedDelta,
        ]);
    }

    private function enqueueReverseIndexUrls(array $tags, array $queuedKeys, array $profiles, array $queueConfig)
    {
        if (!$this->config->isWarmupReverseIndexEnabled()) {
            return 0;
        }

        $limit = $this->config->getWarmupReverseIndexMaxUrlsPerTag();
        if ($limit <= 0) {
            return 0;
        }

        $queued = 0;
        foreach ($this->reverseIndexRepository->getUrlsByTags($tags, $limit) as $row) {
            foreach ($profiles as $profile) {
                if (!$this->storeWarmupPolicyConfig->isStoreEnabled((int)$row['store_id'])) {
                    continue;
                }
                if (!$this->queueVariantConfig->isProfileApplicableToStore($profile, (int)$row['store_id'])) {
                    continue;
                }
                if (!$this->storeWarmupPolicyConfig->isProfileAllowedForStore($profile, (int)$row['store_id'])) {
                    continue;
                }
                $variantConfig = $this->queueVariantConfig->getVariantConfig($queueConfig, $profile);
                if (!$variantConfig['enabled']) {
                    continue;
                }
                $key = $this->getUrlKey($row, $profile);
                if (isset($queuedKeys[$key])) {
                    continue;
                }

                try {
                    $urlPriority = isset($row['priority']) ? (int)$row['priority'] : null;
                    $priority = $this->queueVariantConfig->calculateEffectivePriority(
                        $queueConfig['priority'],
                        $urlPriority,
                        $variantConfig['offset'],
                        $this->storeWarmupPolicyConfig->getPriorityOffset((int)$row['store_id'])
                    );
                    $urlData = array_merge($row, [
                        'source' => 'purge_reverse_index',
                        'source_instance_key' => self::DIRECT_PURGE_SOURCE,
                        'source_priority' => $queueConfig['priority'],
                        'url_priority' => $urlPriority,
                        'effective_priority' => $priority,
                        'priority' => $priority,
                        'is_urgent' => 1,
                        'work_type' => QueueWorkType::TYPE_DELTA,
                    ]);
                    $queueId = $this->queueRepository->enqueue(
                        $urlData,
                        $this->config->getWarmupDefaultDeltaMode(),
                        'purge_reverse_index',
                        $this->queueVariantConfig->getProfileId($profile),
                        $priority
                    );
                    if ($queueId) {
                        $queued++;
                        $queuedKeys[$key] = true;
                    }
                } catch (\Exception $e) {
                    // Skip individual stale or invalid indexed URLs.
                }
            }
        }

        return $queued;
    }

    private function getPurgeProfiles()
    {
        try {
            return $this->varyProfileResolver->getConfiguredProfiles(
                [VaryProfileResolver::PROFILE_GUEST],
                $this->config->getWarmupProfileLimit(),
                $this->config->getWarmupCurrencyCodes(),
                $this->config->getWarmupCustomerIds()
            );
        } catch (\Exception $e) {
            return [$this->varyProfileResolver->getDefaultProfile()];
        }
    }

    private function extractEntities(array $tags)
    {
        $entities = ['product' => [], 'category' => [], 'cms-page' => []];
        foreach ($tags as $tag) {
            if (preg_match('/^P(\d+)$/', $tag, $match)) {
                $entities['product'][] = (int)$match[1];
            } elseif (preg_match('/^C_(\d+)$/', $tag, $match)) {
                $entities['category'][] = (int)$match[1];
            } elseif (preg_match('/^MG_(\d+)$/', $tag, $match)) {
                $entities['cms-page'][] = (int)$match[1];
            }
        }
        return $entities;
    }

    private function countEntities(array $entities)
    {
        $count = 0;
        foreach ($entities as $entityIds) {
            $count += count($entityIds);
        }
        return $count;
    }

    private function hasBroadTags(array $tags)
    {
        foreach ($tags as $tag) {
            if (in_array($tag, self::BROAD_TAGS, true) || substr((string)$tag, -1) === '*') {
                return true;
            }
        }
        return false;
    }

    private function getUrlKey(array $row, array $profile)
    {
        return (int)$row['store_id'] . ':' . (string)$row['url_hash'] . ':' . (int)($profile['profile_id'] ?? 0);
    }
}
