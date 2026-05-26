<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class SectionTabs extends Template implements RendererInterface
{
    /**
     * @var SecureHtmlRenderer|null
     */
    private $secureRenderer;

    public function __construct(
        Context $context,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        $this->secureRenderer = $secureRenderer;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $html = '<div class="litemage-config-tabs" data-litemage-config-tabs>';
        $html .= '<button type="button" class="litemage-config-tab is-active" data-litemage-tab="general">'
            . $this->escapeHtml(__('General')) . '</button>';
        $html .= '<button type="button" class="litemage-config-tab" data-litemage-tab="warmup">'
            . $this->escapeHtml(__('Cache Warmer')) . '</button>';
        $html .= '</div>';

        $css = <<<CSS
.litemage-config-tabs{align-items:center;border-bottom:1px solid #d6d6d6;display:flex;gap:.4rem;margin:0 0 1.4rem;padding:0 0 .6rem}
.litemage-config-tab{background:#f5f5f5;border:1px solid #adadad;border-bottom-color:#adadad;color:#303030;cursor:pointer;font-weight:600;padding:.7rem 1.4rem}
.litemage-config-tab:hover{background:#fff}
.litemage-config-tab.is-active{background:#fff;border-color:#eb5202;color:#000}
#litemage_warmup select.admin__control-multiselect,
#litemage_warmup select[multiple]{height:8.4rem;min-height:6.4rem}
CSS;

        $script = <<<JS
(function () {
    function boot() {
        var tabs = document.querySelector('[data-litemage-config-tabs]');
        if (!tabs) {
            return;
        }
        var groups = {
            general: ['litemage_general', 'litemage_purge', 'litemage_dev'],
            warmup: ['litemage_warmup']
        };
        function getGroupShell(groupId) {
            var fieldset = document.getElementById(groupId);
            if (!fieldset) {
                return null;
            }
            var shell = fieldset.closest('.section-config');
            return shell || fieldset;
        }
        function setActive(tab) {
            tab = tab === 'warmup' ? 'warmup' : 'general';
            Object.keys(groups).forEach(function (name) {
                groups[name].forEach(function (groupId) {
                    var shell = getGroupShell(groupId);
                    if (shell) {
                        shell.style.display = name === tab ? '' : 'none';
                    }
                });
            });
            Array.prototype.slice.call(tabs.querySelectorAll('[data-litemage-tab]')).forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-litemage-tab') === tab);
            });
            try {
                window.localStorage.setItem('litemage.config.activeTab', tab);
            } catch (error) {
            }
        }
        function tabFromHash() {
            return window.location.hash.indexOf('litemage_warmup') !== -1 ? 'warmup' : '';
        }
        tabs.addEventListener('click', function (event) {
            var button = event.target.closest('[data-litemage-tab]');
            if (button) {
                setActive(button.getAttribute('data-litemage-tab'));
            }
        });
        var initial = tabFromHash();
        if (!initial) {
            try {
                initial = window.localStorage.getItem('litemage.config.activeTab') || '';
            } catch (error) {
            }
        }
        setActive(initial || 'general');
        window.addEventListener('hashchange', function () {
            var tab = tabFromHash();
            if (tab) {
                setActive(tab);
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
JS;

        return $html
            . $this->renderTag('style', [], $css)
            . $this->renderTag('script', [], $script);
    }

    private function renderTag($tag, array $attributes, $content)
    {
        if ($this->secureRenderer) {
            return $this->secureRenderer->renderTag($tag, $attributes, $content, false);
        }

        return '<' . $tag . '>' . $content . '</' . $tag . '>';
    }
}
