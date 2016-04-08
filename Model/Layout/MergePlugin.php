<?php
/**
 * LiteMage2
 *
 * NOTICE OF LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see https://opensource.org/licenses/GPL-3.0 .
 *
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) 2016 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model\Layout;

/**
 * Class MergePlugin
 */
class MergePlugin
{
    /**
     * @var \Magento\PageCache\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $response;

    protected $_handles;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\ResponseInterface $response
     * @param \Magento\PageCache\Model\Config $config
     */
    public function __construct(
        \Magento\Framework\App\ResponseInterface $response,
        \Litespeed\Litemage\Model\Config $config
    ) {
        $this->response = $response;
        $this->config = $config;
    }


    /*public function afterGetCacheId(\Magento\Framework\View\Layout\ProcessorInterface $subject, $result)
    {
        $this->_handles = $subject->getHandles();
        return $result . '_LITEMAGE';
    }*/
}