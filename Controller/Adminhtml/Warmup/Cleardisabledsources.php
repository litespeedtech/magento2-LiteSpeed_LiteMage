<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Config;
use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Cleardisabledsources extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Litespeed_Litemage::warmup_queue';

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    /**
     * @var Config
     */
    private $config;

    public function __construct(Context $context, QueueRepository $queueRepository, Config $config)
    {
        $this->queueRepository = $queueRepository;
        $this->config = $config;
        parent::__construct($context);
    }

    public function execute()
    {
        $rows = $this->queueRepository->deleteDisabledScheduledSourceWork($this->config->getWarmupSources());
        if ($rows > 0) {
            $this->messageManager->addSuccessMessage(__('Removed %1 queued URL or source membership row(s) from disabled scheduled sources.', $rows));
        } else {
            $this->messageManager->addNoticeMessage(__('No disabled scheduled-source work was found.'));
        }

        return $this->resultRedirectFactory->create()->setPath('litespeed_litemage/warmup/queue');
    }
}
