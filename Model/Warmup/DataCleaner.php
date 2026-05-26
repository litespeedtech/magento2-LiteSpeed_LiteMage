<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Warmup;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;

class DataCleaner
{
    public const TABLES = [
        'litemage_warm_lane_lock',
        'litemage_warm_runner_event',
        'litemage_warm_result',
        'litemage_warm_queue',
        'litemage_warm_tag_url',
        'litemage_warm_purge_event',
        'litemage_warm_url_source',
        'litemage_warm_url',
        'litemage_warm_profile',
    ];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    public function __construct(
        ResourceConnection $resource,
        DirectoryList $directoryList
    ) {
        $this->resource = $resource;
        $this->directoryList = $directoryList;
    }

    public function truncateAll()
    {
        $connection = $this->resource->getConnection();
        $counts = [];

        foreach (self::TABLES as $tableName) {
            $table = $this->resource->getTableName($tableName);
            if (!$connection->isTableExists($table)) {
                continue;
            }

            $counts[$tableName] = (int)$connection->fetchOne(
                $connection->select()->from($table, new \Zend_Db_Expr('COUNT(*)'))
            );
            $connection->truncateTable($table);
        }

        return [
            'tables' => $counts,
            'files' => $this->deleteTemporaryFiles(),
        ];
    }

    public function deleteTemporaryFiles()
    {
        $files = [];
        foreach ($this->temporaryFilePatterns() as $pattern) {
            foreach ($this->glob($pattern) as $file) {
                if (is_file($file) && @unlink($file)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function temporaryFilePatterns()
    {
        $varDir = rtrim($this->directoryList->getPath(DirectoryList::VAR_DIR), '/');
        $tempDir = rtrim(sys_get_temp_dir(), '/');

        return [
            $tempDir . '/litemage_warm_cookie_*',
            $tempDir . '/litemage-warm-customer-*.cookies',
        ];
    }

    private function glob($pattern)
    {
        $files = glob($pattern);
        return is_array($files) ? $files : [];
    }
}
