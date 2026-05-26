<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Controller\Adminhtml\Warmup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

class Resume extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Litespeed_Litemage::warmup_queue';

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var ReinitableConfigInterface|null
     */
    private $reinitableConfig;

    public function __construct(
        Context $context,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ?ReinitableConfigInterface $reinitableConfig = null
    ) {
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
        parent::__construct($context);
    }

    public function execute()
    {
        $this->configWriter->save('litemage/warmup/enabled', 1);
        $this->cacheTypeList->cleanType('config');
        if ($this->reinitableConfig) {
            $this->reinitableConfig->reinit();
        }
        $this->messageManager->addSuccessMessage(__('LiteMage cache warmer enabled.'));
        return $this->resultRedirectFactory->create()->setPath($this->getReturnPath());
    }

    private function getReturnPath()
    {
        return (string)$this->getRequest()->getParam('return') === 'progress'
            ? 'litespeed_litemage/warmup/progress'
            : 'litespeed_litemage/warmup/queue';
    }
}
