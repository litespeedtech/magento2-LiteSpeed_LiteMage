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
     * @var \Litespeed\Litemage\Helper\Data
     */
    protected $helper;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context,
	 * @param \Litespeed\Litemage\Helper\Data $helper,
     * @param array $data
     */
    public function __construct(
			\Magento\Framework\View\Element\Template\Context $context,
			\Litespeed\Litemage\Helper\Data $helper,
			array $data = [])
    {
        parent::__construct($context, $data);
        $this->helper = $helper;
    }

    public function needAjax()
    {
        return $this->helper->needCustVaryAjax();
    }

    public function getCheckUrl()
    {
        $url = $this->getUrl('litemage/block/customVary');
        return $url;
    }
}
