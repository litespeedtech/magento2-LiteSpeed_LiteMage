<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\View\Helper\Js;

class WarmupStatusFieldset extends Fieldset
{
    /**
     * @var SecureHtmlRenderer
     */
    private $litemageSecureRenderer;

    public function __construct(
        Context $context,
        Session $authSession,
        Js $jsHelper,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        $this->litemageSecureRenderer = $secureRenderer ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(SecureHtmlRenderer::class);
        parent::__construct($context, $authSession, $jsHelper, $data, $secureRenderer);
    }

    protected function _getHeaderTitleHtml($element)
    {
        $url = $this->_urlBuilder->getUrl('litespeed_litemage/warmup/status');
        $buttonId = $element->getHtmlId() . '-status-action';

        return '<a id="' .
            $element->getHtmlId() .
            '-head" href="#' .
            $element->getHtmlId() .
            '-link">' . $element->getLegend() . '</a>' .
            '<button type="button" id="' . $buttonId . '" class="action-secondary litemage-warmup-status-head-action">' .
            $this->escapeHtml(__('Open Status')) . '</button>' .
            $this->renderStatusHeaderStyle() .
            $this->litemageSecureRenderer->renderEventListenerAsTag(
                'onclick',
                "setLocation('" . $this->escapeJs($url) . "'); return false;",
                'button#' . $buttonId
            ) .
            /* @noEscape */ $this->litemageSecureRenderer->renderEventListenerAsTag(
                'onclick',
                'event.preventDefault();' .
                "Fieldset.toggleCollapse('" . $element->getHtmlId() . "', '" .
                 $this->_urlBuilder->getUrl('*/*/state') . "'); return false;",
                'a#' . $element->getHtmlId() . '-head'
            );
    }

    private function renderStatusHeaderStyle()
    {
        $css = <<<CSS
#litemage_warmup_warmup_status-head{display:inline-block;vertical-align:middle}
.litemage-warmup-status-head-action{display:inline-block;font-size:1.1rem;line-height:1;margin-left:1rem;padding:.35rem .7rem;vertical-align:middle}
CSS;
        return $this->litemageSecureRenderer->renderTag('style', [], $css, false);
    }
}
