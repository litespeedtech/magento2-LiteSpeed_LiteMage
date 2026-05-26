<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Litespeed\Litemage\Model\Config;

class QueueVariantMapBuilder
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var QueueVariantConfig
     */
    private $queueVariantConfig;

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var VaryProfileResolver
     */
    private $varyProfileResolver;

    public function __construct(
        Config $config,
        QueueVariantConfig $queueVariantConfig,
        QueueRepository $queueRepository,
        VaryProfileResolver $varyProfileResolver
    ) {
        $this->config = $config;
        $this->queueVariantConfig = $queueVariantConfig;
        $this->queueRepository = $queueRepository;
        $this->varyProfileResolver = $varyProfileResolver;
    }

    public function build()
    {
        $profiles = $this->getProfiles();
        $variants = $this->buildVariants($profiles);
        $counts = $this->queueRepository->getQueueInstanceSummaries();
        $queues = [];

        foreach ($this->getQueueInstances() as $queue) {
            $queueKey = $this->queueVariantConfig->getQueueKey($queue['source'], $queue['source_instance_key']);
            $queueConfig = $this->queueVariantConfig->getQueueConfig(
                $queue['source'],
                $queue['source_instance_key'],
                $queue['default_priority']
            );
            $queueCounts = $this->getQueueCounts($counts, $queue);
            $row = [
                'key' => $queueKey,
                'source' => $queue['source'],
                'source_instance_key' => $queue['source_instance_key'],
                'label' => $queue['label'],
                'enabled' => $queueConfig['enabled'],
                'priority' => $queueConfig['priority'],
                'default_priority' => $queue['default_priority'],
                'rows' => (int)$queueCounts['rows'],
                'profile_count' => (int)$queueCounts['profiles'],
                'variants' => [],
            ];

            foreach ($variants as $variant) {
                $profile = $profiles[$variant['key']] ?? [];
                $variantConfig = $this->queueVariantConfig->getVariantConfig($queueConfig, $profile);
                $row['variants'][$variant['key']] = [
                    'enabled' => $variantConfig['enabled'],
                    'offset' => $variantConfig['offset'],
                ];
            }

            $queues[] = $row;
        }

        usort($queues, function (array $left, array $right) {
            if ($left['priority'] === $right['priority']) {
                return strcmp($left['label'], $right['label']);
            }
            return $left['priority'] < $right['priority'] ? -1 : 1;
        });

        return [
            'version' => 1,
            'queues' => $queues,
            'variants' => $variants,
            'summary' => [
                'queues' => count($queues),
                'enabled_queues' => count(array_filter($queues, function ($queue) {
                    return !empty($queue['enabled']);
                })),
                'variants' => count($variants),
                'rows' => array_sum(array_map(function ($queue) {
                    return (int)$queue['rows'];
                }, $queues)),
            ],
        ];
    }

    private function getProfiles()
    {
        try {
            $profiles = $this->varyProfileResolver->getConfiguredProfiles(
                [VaryProfileResolver::PROFILE_GUEST],
                $this->config->getWarmupProfileLimit(),
                $this->config->getWarmupCurrencyCodes(),
                $this->config->getWarmupCustomerIds()
            );
        } catch (\Exception $e) {
            $profiles = [$this->varyProfileResolver->getDefaultProfile()];
        }

        $indexed = [];
        foreach ($profiles as $profile) {
            $indexed[$this->queueVariantConfig->getProfileVariantKey($profile)] = $profile;
        }

        return $indexed;
    }

    private function buildVariants(array $profiles)
    {
        $variants = [];
        foreach ($profiles as $key => $profile) {
            $variants[] = [
                'key' => $key,
                'label' => $this->formatVariantLabel($profile, $key),
                'type' => (string)($profile['type'] ?? ''),
                'type_label' => $this->formatVariantTypeLabel($profile['type'] ?? ''),
                'detail' => $this->formatVariantDetail($profile),
                'locked' => $key === QueueVariantConfig::PROFILE_GUEST,
                'default_offset' => $this->queueVariantConfig->getDefaultVariantOffset($profile),
            ];
        }

        usort($variants, function (array $left, array $right) {
            $leftOrder = $this->getVariantTypeOrder($left['type']);
            $rightOrder = $this->getVariantTypeOrder($right['type']);
            if ($leftOrder !== $rightOrder) {
                return $leftOrder < $rightOrder ? -1 : 1;
            }
            return strcmp($left['label'], $right['label']);
        });

        return $variants;
    }

    private function getQueueInstances()
    {
        $instances = [];
        $enabled = array_fill_keys($this->config->getWarmupSources(), true);

        if ($this->config->isWarmupDeltaEnabled()) {
            $instances[] = $this->queue(
                'purge_direct',
                'purge_direct',
                'Purge Event',
                $this->config->getWarmupPurgeEntitySourcePriority()
            );
        }

        if (isset($enabled['sitemap'])) {
            foreach ($this->config->getWarmupSitemapPaths() as $row) {
                $columns = str_getcsv(trim((string)$row), ',', '"', '\\');
                $path = trim((string)($columns[0] ?? ''));
                if ($path === '') {
                    continue;
                }
                $priority = isset($columns[2]) && trim((string)$columns[2]) !== ''
                    ? (int)$columns[2]
                    : $this->config->getWarmupSitemapSourcePriority();
                $instances[] = $this->queue('sitemap', $path, $this->shortLabel($path), $priority);
            }
        }

        if (isset($enabled['text_file'])) {
            foreach ($this->config->getWarmupTextFilePaths() as $row) {
                $columns = str_getcsv(trim((string)$row), ',', '"', '\\');
                $path = trim((string)($columns[0] ?? ''));
                if ($path === '') {
                    continue;
                }
                $priorityColumn = (isset($columns[2]) || strpos((string)($columns[1] ?? ''), '|') !== false)
                    ? ($columns[2] ?? '')
                    : ($columns[1] ?? '');
                $priority = trim((string)$priorityColumn) !== ''
                    ? (int)$priorityColumn
                    : $this->config->getWarmupTextFileSourcePriority();
                $instances[] = $this->queue('text_file', $path, $this->shortLabel($path), $priority);
            }
        }

        if (isset($enabled['url_rewrite'])) {
            $instances[] = $this->queue(
                'url_rewrite',
                'url_rewrite',
                'Magento URL Rewrites',
                $this->config->getWarmupUrlRewriteSourcePriority()
            );
        }

        if ($this->config->isWarmupRecentlySeenEnabled()) {
            $instances[] = $this->queue(
                'recently_seen',
                'recently_seen',
                'Recently Seen URLs',
                $this->config->getWarmupRecentlySeenSourcePriority()
            );
        }

        return $instances;
    }

    private function queue($source, $sourceInstanceKey, $label, $priority)
    {
        return [
            'source' => (string)$source,
            'source_instance_key' => (string)$sourceInstanceKey,
            'label' => (string)$label,
            'default_priority' => max(Config::WARMUP_PRIORITY_MIN, min(Config::WARMUP_PRIORITY_MAX, (int)$priority)),
        ];
    }

    private function getQueueCounts(array $counts, array $queue)
    {
        if ($queue['source'] !== 'purge_direct') {
            $countKey = $queue['source'] . '|' . $queue['source_instance_key'];
            return $counts[$countKey] ?? ['rows' => 0, 'profiles' => 0];
        }

        $rows = 0;
        $profiles = 0;
        foreach ([
            'purge_entity|purge_direct',
            'purge_reverse_index|purge_direct',
            'purge_entity|purge_entity',
            'purge_reverse_index|purge_reverse_index',
        ] as $countKey) {
            $rows += (int)($counts[$countKey]['rows'] ?? 0);
            $profiles = max($profiles, (int)($counts[$countKey]['profiles'] ?? 0));
        }

        return ['rows' => $rows, 'profiles' => $profiles];
    }

    private function shortLabel($value)
    {
        $path = parse_url((string)$value, PHP_URL_PATH);
        $value = is_string($path) && $path !== '' ? $path : (string)$value;
        $value = str_replace('\\', '/', $value);
        $basename = basename($value);
        return $basename !== '' ? $basename : $value;
    }

    private function getVariantTypeOrder($type)
    {
        switch ((string)$type) {
            case 'guest':
                return 0;
            case 'currency':
                return 10;
            case 'customer':
                return 20;
            case 'customer_currency':
                return 30;
            default:
                return 40;
        }
    }

    private function formatVariantLabel(array $profile, $key)
    {
        $label = trim((string)($profile['label'] ?? $key));
        if ($label === '') {
            $label = (string)$key;
        }

        switch ((string)($profile['type'] ?? '')) {
            case 'guest':
                return $label;
            case 'currency':
                return 'Guest + ' . $label;
            case 'customer':
                return $this->formatCustomerGroupVariantLabel($profile);
            case 'customer_currency':
                $currency = strtoupper(trim((string)($profile['currency'] ?? '')));
                $label = $this->formatCustomerGroupVariantLabel($profile);
                return $currency === '' ? $label : $label . ' + Currency ' . $currency;
            default:
                return $label;
        }
    }

    private function formatCustomerGroupVariantLabel(array $profile)
    {
        $groupCode = trim((string)($profile['customer_group_code'] ?? ''));
        if ($groupCode !== '') {
            return 'Customer Group ' . $groupCode;
        }

        if (isset($profile['customer_group_id']) && $profile['customer_group_id'] !== '') {
            return 'Customer Group #' . (int)$profile['customer_group_id'];
        }

        $label = trim((string)($profile['label'] ?? ''));
        return $label !== '' ? $label : 'Customer Group';
    }

    private function formatVariantDetail(array $profile)
    {
        switch ((string)($profile['type'] ?? '')) {
            case 'customer':
            case 'customer_currency':
                $customerId = (int)($profile['customer_id'] ?? 0);
                return $customerId > 0 ? sprintf('(running as user #%d)', $customerId) : '';
            default:
                return '';
        }
    }

    private function formatVariantTypeLabel($type)
    {
        switch ((string)$type) {
            case 'guest':
                return 'Default';
            case 'currency':
                return 'Currency';
            case 'customer':
                return 'Customer';
            case 'customer_currency':
                return 'Customer + Currency';
            default:
                return '';
        }
    }
}
