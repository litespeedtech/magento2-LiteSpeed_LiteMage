<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Logger\WarmupLogger;
use Litespeed\Litemage\Model\Warmup\Source\SitemapUrlSource;
use Litespeed\Litemage\Model\Warmup\Source\TextFileUrlSource;
use Litespeed\Litemage\Model\Warmup\Source\UrlRewriteSource;
use Magento\Framework\App\ObjectManager;

class QueueGenerator
{
    private const EVENT_DRIVEN_SOURCES = ['purge_entity'];
    private const GENERATION_SOURCES = ['sitemap', 'url_rewrite', 'text_file', 'recently_seen'];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var UrlNormalizer
     */
    private $urlNormalizer;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    /**
     * @var SitemapUrlSource
     */
    private $sitemapUrlSource;

    /**
     * @var UrlRewriteSource
     */
    private $urlRewriteSource;

    /**
     * @var TextFileUrlSource
     */
    private $textFileUrlSource;

    /**
     * @var ReverseIndexRepository
     */
    private $reverseIndexRepository;

    /**
     * @var WarmupLogger
     */
    private $logger;

    /**
     * @var QueueVariantConfig
     */
    private $queueVariantConfig;

    /**
     * @var StoreWarmupPolicyConfig
     */
    private $storeWarmupPolicyConfig;

    public function __construct(
        Config $config,
        QueueRepository $queueRepository,
        UrlNormalizer $urlNormalizer,
        VaryProfileResolver $varyProfileResolver,
        SitemapUrlSource $sitemapUrlSource,
        UrlRewriteSource $urlRewriteSource,
        TextFileUrlSource $textFileUrlSource,
        ReverseIndexRepository $reverseIndexRepository,
        WarmupLogger $logger,
        QueueVariantConfig $queueVariantConfig,
        ?StoreWarmupPolicyConfig $storeWarmupPolicyConfig = null
    ) {
        $this->config = $config;
        $this->queueRepository = $queueRepository;
        $this->urlNormalizer = $urlNormalizer;
        $this->varyProfileResolver = $varyProfileResolver;
        $this->sitemapUrlSource = $sitemapUrlSource;
        $this->urlRewriteSource = $urlRewriteSource;
        $this->textFileUrlSource = $textFileUrlSource;
        $this->reverseIndexRepository = $reverseIndexRepository;
        $this->logger = $logger;
        $this->queueVariantConfig = $queueVariantConfig;
        $this->storeWarmupPolicyConfig = $storeWarmupPolicyConfig
            ?: ObjectManager::getInstance()->get(StoreWarmupPolicyConfig::class);
    }

    public function generate($sourceCode = null, $dryRun = false, array $storeIds = [])
    {
        $stats = [
            'seen' => 0,
            'enqueued' => 0,
            'skipped' => 0,
            'deleted_stale' => 0,
            'errors' => [],
            'source_stats' => [],
        ];
        if ($sourceCode && in_array((string)$sourceCode, self::EVENT_DRIVEN_SOURCES, true)) {
            $stats['errors'][] = sprintf(
                'Source "%s" is event-driven. Enable purge-driven delta warmup instead of generating it manually.',
                $sourceCode
            );
            $this->logger->notice($stats['errors'][0]);
            return $stats;
        }
        if ($sourceCode && !in_array((string)$sourceCode, self::GENERATION_SOURCES, true)) {
            $stats['errors'][] = sprintf('Unsupported URL source "%s".', $sourceCode);
            $this->logger->notice($stats['errors'][0]);
            return $stats;
        }

        $storeIds = $this->storeWarmupPolicyConfig->getAllowedStoreIds($storeIds);
        if (!$storeIds) {
            $stats['errors'][] = 'No store views are enabled by Store Warmup Policy.';
            $this->logger->notice($stats['errors'][0]);
            return $stats;
        }

        $sources = $sourceCode ? [(string)$sourceCode] : $this->getGenerationSources();
        if (!$dryRun && $sourceCode === null) {
            $stats['deleted_stale'] += $this->queueRepository->deleteDisabledScheduledSourceWork(
                $this->config->getWarmupSources(),
                $storeIds
            );
        }
        $perStoreCounts = [];
        $limitPerStore = $this->config->getWarmupQueueLimitPerStore();
        $storeFilter = array_fill_keys(array_map('intval', $storeIds), true);
        try {
            $profiles = $this->varyProfileResolver->getConfiguredProfiles(
                [VaryProfileResolver::PROFILE_GUEST],
                $this->config->getWarmupProfileLimit(),
                $this->config->getWarmupCurrencyCodes(),
                $this->config->getWarmupCustomerIds()
            );
        } catch (\Exception $e) {
            $stats['errors'][] = $e->getMessage();
            $this->logger->notice('Queue generation failed before source collection: ' . $e->getMessage());
            return $stats;
        }

        foreach ($sources as $source) {
            $items = $this->collect($source, $storeIds);
            $sourceStats = $this->getLastSourceStats($source);
            if ($sourceStats) {
                $stats['source_stats'][] = $sourceStats;
                foreach (($sourceStats['errors'] ?? []) as $error) {
                    $stats['errors'][] = $error;
                }
            }
            if (!$dryRun && !$items && $source === 'recently_seen' && $this->config->isWarmupRecentlySeenEnabled()) {
                $stats['deleted_stale'] += $this->queueRepository->deleteSourceInstanceWork(
                    $source,
                    'recently_seen',
                    $storeIds
                );
            }
            $activeSourceInstances = [];
            $activeProfilesByInstanceStore = [];
            $disabledSourceInstances = [];
            foreach ($items as $item) {
                $sourceInstanceKey = $this->getSourceInstanceKey($source, $item);
                $sourcePriority = isset($item['source_priority'])
                    ? (int)$item['source_priority']
                    : $this->config->getWarmupSourcePriority($source);
                $queueConfig = $this->queueVariantConfig->getQueueConfig($source, $sourceInstanceKey, $sourcePriority);
                $activeSourceInstances[$sourceInstanceKey] = true;
                if (!$queueConfig['enabled']) {
                    $disabledSourceInstances[$sourceInstanceKey] = true;
                }
                $stats['seen']++;
                try {
                    if (!$queueConfig['enabled']) {
                        $stats['skipped']++;
                        continue;
                    }
                    $normalized = $this->urlNormalizer->normalize($item['url'], $item['store_id'] ?? null);
                    $storeId = (int)$normalized['store_id'];
                    if ($storeFilter && !isset($storeFilter[$storeId])) {
                        $stats['skipped']++;
                        continue;
                    }
                    if ($this->queueRepository->isBlacklisted($normalized)) {
                        $stats['skipped']++;
                        continue;
                    }
                    $sourceKey = $this->getSourceLimitKey($source, $item);
                    $perStoreCounts[$storeId] = $perStoreCounts[$storeId] ?? [];
                    $perStoreCounts[$storeId][$sourceKey] = $perStoreCounts[$storeId][$sourceKey] ?? 0;
                    if ($perStoreCounts[$storeId][$sourceKey] >= $limitPerStore) {
                        $stats['skipped']++;
                        continue;
                    }

                    foreach ($profiles as $profile) {
                        if (!$this->queueVariantConfig->isProfileApplicableToStore($profile, $storeId)) {
                            continue;
                        }
                        if (!$this->storeWarmupPolicyConfig->isProfileAllowedForStore($profile, $storeId)) {
                            continue;
                        }
                        $variantConfig = $this->queueVariantConfig->getVariantConfig($queueConfig, $profile);
                        if (!$variantConfig['enabled']) {
                            continue;
                        }
                        $profileId = $this->queueVariantConfig->getProfileId($profile);
                        $activeProfilesByInstanceStore[$sourceInstanceKey][$storeId][$profileId] = true;
                        $urlPriority = array_key_exists('url_priority', $item)
                            ? $item['url_priority']
                            : ($item['priority'] ?? null);
                        $effectivePriority = $this->queueVariantConfig->calculateEffectivePriority(
                            $queueConfig['priority'],
                            $urlPriority,
                            $variantConfig['offset'],
                            $this->storeWarmupPolicyConfig->getPriorityOffset($storeId)
                        );
                        $intervalSeconds = array_key_exists('interval_seconds', $item)
                            && $item['interval_seconds'] !== null
                            ? (int)$item['interval_seconds']
                            : $this->config->getWarmupRecrawlIntervalSeconds();
                        if (!$dryRun) {
                            $this->queueRepository->enqueue(
                                $normalized + [
                                    'source' => $source,
                                    'page_type' => $item['page_type'] ?? null,
                                    'entity_type' => $item['entity_type'] ?? null,
                                    'entity_id' => $item['entity_id'] ?? null,
                                    'source_instance_key' => $sourceInstanceKey,
                                    'source_priority' => $queueConfig['priority'],
                                    'url_priority' => $urlPriority,
                                    'effective_priority' => $effectivePriority,
                                    'priority' => $effectivePriority,
                                    'interval_seconds' => $intervalSeconds,
                                ],
                                $this->config->getWarmupDefaultFullMode(),
                                $source,
                                $profileId,
                                $effectivePriority
                            );
                        }
                        $stats['enqueued']++;
                    }
                    $perStoreCounts[$storeId][$sourceKey]++;
                } catch (\Exception $e) {
                    $stats['skipped']++;
                    $stats['errors'][] = $e->getMessage();
                }
            }
            if (!$dryRun && $activeSourceInstances) {
                foreach (array_keys($disabledSourceInstances) as $sourceInstanceKey) {
                    $stats['deleted_stale'] += $this->queueRepository->deleteSourceInstanceWork(
                        $source,
                        $sourceInstanceKey,
                        $storeIds
                    );
                }
                foreach ($activeProfilesByInstanceStore as $sourceInstanceKey => $profilesByStore) {
                    foreach ($profilesByStore as $storeId => $profileIds) {
                        $stats['deleted_stale'] += $this->queueRepository->deleteStaleSourceInstanceVariantWork(
                            $source,
                            $sourceInstanceKey,
                            array_keys($profileIds),
                            [(int)$storeId]
                        );
                    }
                }
                $stats['deleted_stale'] += $this->queueRepository->deleteStaleSourceInstanceWork(
                    $source,
                    array_keys($activeSourceInstances),
                    $storeIds
                );
            }
        }

        $this->logger->notice(sprintf(
            'Queue generation%s source=%s stores=%s seen=%d updated=%d skipped=%d deleted_stale=%d errors=%d',
            $dryRun ? ' dry-run' : '',
            $sourceCode ?: 'configured',
            $storeIds ? implode(',', $storeIds) : 'all',
            $stats['seen'],
            $stats['enqueued'],
            $stats['skipped'],
            $stats['deleted_stale'],
            count($stats['errors'])
        ));

        return $stats;
    }

    private function getGenerationSources()
    {
        return array_values(array_filter($this->config->getWarmupSources(), function ($source) {
            return in_array((string)$source, self::GENERATION_SOURCES, true);
        }));
    }

    private function collect($source, array $storeIds = [])
    {
        switch ($source) {
            case 'sitemap':
                return $this->sitemapUrlSource->collect($storeIds);
            case 'url_rewrite':
                return $this->urlRewriteSource->collect($storeIds);
            case 'text_file':
                return $this->textFileUrlSource->collect();
            case 'recently_seen':
                return $this->config->isWarmupRecentlySeenEnabled()
                    ? $this->reverseIndexRepository->getRecentlySeenUrls(
                        $this->config->getWarmupRecentlySeenLimit(),
                        $storeIds
                    )
                    : [];
            default:
                return [];
        }
    }

    private function getLastSourceStats($source)
    {
        switch ($source) {
            case 'sitemap':
                return $this->sitemapUrlSource->getLastStats();
            case 'text_file':
                return $this->textFileUrlSource->getLastStats();
            default:
                return [];
        }
    }

    private function getSourceLimitKey($source, array $item)
    {
        return (string)$source . '|' . $this->getSourceInstanceKey($source, $item);
    }

    private function getSourceInstanceKey($source, array $item)
    {
        return (string)($item['source_instance_key'] ?? $source);
    }
}
