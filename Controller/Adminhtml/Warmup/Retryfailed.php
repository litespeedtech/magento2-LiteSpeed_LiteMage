<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Adminhtml\Warmup;

use Litespeed\Litemage\Model\Warmup\QueueRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Retryfailed extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Litespeed_Litemage::warmup_queue';

    /**
     * @var QueueRepository
     */
    private $queueRepository;

    public function __construct(Context $context, QueueRepository $queueRepository)
    {
        $this->queueRepository = $queueRepository;
        parent::__construct($context);
    }

    public function execute()
    {
        $rows = $this->queueRepository->retryFailed();
        $this->messageManager->addSuccessMessage(__('Marked %1 failed LiteMage queued URL row(s) for retry.', $rows));
        return $this->resultRedirectFactory->create()->setPath('litespeed_litemage/warmup/queue');
    }
}
