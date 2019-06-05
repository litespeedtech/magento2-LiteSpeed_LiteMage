<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Inject;

class CustomVary extends \Magento\Framework\View\Element\Template
{

    /**
     * @var \Litespeed\Litemage\Model\CacheControl
     */
    protected $litemageCache;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
	 * @param \Litespeed\Litemage\Model\Config $config,
     * @param array $data
     */
    public function __construct(
			\Magento\Backend\Block\Template\Context $context,
			\Litespeed\Litemage\Model\CacheControl $litemageCache,
			array $data = [])
    {
        parent::__construct($context, $data);
        $this->litemageCache = $litemageCache;
    }


    public function needAjax()
    {
        return $this->litemageCache->needCustVaryAjax();
    }

}
