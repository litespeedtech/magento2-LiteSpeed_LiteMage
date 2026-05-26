<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\System\Config\Backend;

use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\CronException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

class WarmupCronSchedule extends Value
{
    private const PROCESS_MIN_INTERVAL_SECONDS = 300;
    private const BUILD_MAX_PER_DAY = 1;
    private const SCAN_DAYS = 400;
    private const SCAN_START = '2028-01-01 00:00:00 UTC';

    /**
     * @var ScheduleFactory
     */
    private $scheduleFactory;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ScheduleFactory $scheduleFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->scheduleFactory = $scheduleFactory;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $expression = preg_replace('/\s+/', ' ', trim((string)$this->getValue()));
        if ($expression === '') {
            throw new LocalizedException(__('LiteMage warmup cron schedule must not be empty.'));
        }

        $parts = explode(' ', $expression);
        if (count($parts) !== 5) {
            throw new LocalizedException(__(
                'LiteMage warmup cron schedule must use five fields, for example "%1".',
                $this->isBuildSchedule() ? '0 3 * * *' : '*/5 * * * *'
            ));
        }

        $schedule = $this->scheduleFactory->create();
        $this->validateFieldMatches($schedule, $parts, $expression);
        $this->validateFrequency($schedule, $parts, $expression);

        $this->setValue($expression);
        return parent::beforeSave();
    }

    private function validateFieldMatches($schedule, array $parts, $expression)
    {
        $ranges = [
            [0, 59, 'minute'],
            [0, 23, 'hour'],
            [1, 31, 'day of month'],
            [1, 12, 'month'],
            [0, 6, 'day of week'],
        ];

        foreach ($parts as $index => $part) {
            [$min, $max, $label] = $ranges[$index];
            $hasMatch = false;
            for ($value = $min; $value <= $max; $value++) {
                try {
                    if ($schedule->matchCronExpression($part, $value)) {
                        $hasMatch = true;
                        break;
                    }
                } catch (CronException $e) {
                    throw new LocalizedException(__(
                        'Invalid LiteMage warmup cron schedule "%1": %2',
                        $expression,
                        $e->getMessage()
                    ));
                }
            }
            if (!$hasMatch) {
                throw new LocalizedException(__(
                    'Invalid LiteMage warmup cron schedule "%1": the %2 field does not match any supported value.',
                    $expression,
                    $label
                ));
            }
        }
    }

    private function validateFrequency($schedule, array $parts, $expression)
    {
        $start = strtotime(self::SCAN_START);
        $end = $start + (self::SCAN_DAYS * 86400);
        $lastMatch = null;
        $matches = 0;
        $matchesByDay = [];

        for ($timestamp = $start; $timestamp < $end; $timestamp += 60) {
            if (!$this->matchesTimestamp($schedule, $parts, $timestamp, $expression)) {
                continue;
            }

            $matches++;
            if ($this->isBuildSchedule()) {
                $day = gmdate('Y-m-d', $timestamp);
                $matchesByDay[$day] = ($matchesByDay[$day] ?? 0) + 1;
                if ($matchesByDay[$day] > self::BUILD_MAX_PER_DAY) {
                    throw new LocalizedException(__(
                        'Queue Build Cron Schedule must not run more than once per day.'
                    ));
                }
            } elseif ($lastMatch !== null && ($timestamp - $lastMatch) < self::PROCESS_MIN_INTERVAL_SECONDS) {
                throw new LocalizedException(__(
                    'Queue Process Cron Schedule must not run more often than every five minutes.'
                ));
            }
            $lastMatch = $timestamp;
        }

        if ($matches === 0) {
            throw new LocalizedException(__(
                'LiteMage warmup cron schedule "%1" does not match any run time in the validation window.',
                $expression
            ));
        }
    }

    private function matchesTimestamp($schedule, array $parts, $timestamp, $expression)
    {
        try {
            return $schedule->matchCronExpression($parts[0], (int)gmdate('i', $timestamp))
                && $schedule->matchCronExpression($parts[1], (int)gmdate('H', $timestamp))
                && $schedule->matchCronExpression($parts[2], (int)gmdate('d', $timestamp))
                && $schedule->matchCronExpression($parts[3], (int)gmdate('m', $timestamp))
                && $schedule->matchCronExpression($parts[4], (int)gmdate('w', $timestamp));
        } catch (CronException $e) {
            throw new LocalizedException(__(
                'Invalid LiteMage warmup cron schedule "%1": %2',
                $expression,
                $e->getMessage()
            ));
        }
    }

    private function isBuildSchedule()
    {
        return strpos((string)$this->getPath(), 'generate_cron_schedule') !== false;
    }
}
