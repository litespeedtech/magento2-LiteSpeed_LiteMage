<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Setup;

use Litespeed\Litemage\Model\Warmup\DataCleaner;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        try {
            $connection = $setup->getConnection();

            foreach (DataCleaner::TABLES as $tableName) {
                $connection->dropTable($setup->getTable($tableName));
            }

            $this->deleteTemporaryFiles();
        } finally {
            $setup->endSetup();
        }
    }

    private function deleteTemporaryFiles()
    {
        $tempDir = rtrim(sys_get_temp_dir(), '/');
        $patterns = [
            $tempDir . '/litemage_warm_cookie_*',
            $tempDir . '/litemage-warm-customer-*.cookies',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
