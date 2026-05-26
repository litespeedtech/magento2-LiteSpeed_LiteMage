<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\System\Config;

use Litespeed\Litemage\Model\Warmup\QueueVariantConfig;
use Litespeed\Litemage\Model\Warmup\QueueVariantMapBuilder;
use Litespeed\Litemage\Model\Warmup\StoreWarmupPolicyConfig;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Model\StoreManagerInterface;

class StoreWarmupPolicy extends Field
{
    /**
     * @var StoreWarmupPolicyConfig
     */
    private $policyConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var QueueVariantMapBuilder
     */
    private $mapBuilder;

    /**
     * @var SecureHtmlRenderer|null
     */
    private $secureRenderer;

    public function __construct(
        Context $context,
        StoreWarmupPolicyConfig $policyConfig,
        StoreManagerInterface $storeManager,
        QueueVariantMapBuilder $mapBuilder,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        $this->policyConfig = $policyConfig;
        $this->storeManager = $storeManager;
        $this->mapBuilder = $mapBuilder;
        $this->secureRenderer = $secureRenderer;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element = clone $element;
        $element->unsScope()
            ->unsCanUseWebsiteValue()
            ->unsCanUseDefaultValue();

        $html = '<td class="value litemage-swp-field" colspan="4">';
        $html .= $this->_getElementHtml($element);
        if ($element->getComment()) {
            $html .= '<p class="note"><span>' . $this->escapeHtml($element->getComment()) . '</span></p>';
        }
        $html .= '</td>';

        return $this->_decorateRowHtml($element, $html);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $fieldId = $element->getHtmlId();
        $state = $this->buildState();
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $json = json_encode($state, $jsonFlags);
        if ($json === false) {
            $json = '{"version":1,"stores":{}}';
        }

        $html = sprintf(
            '<input type="hidden" id="%s" name="%s" value="%s" />',
            $this->escapeHtmlAttr($fieldId),
            $this->escapeHtmlAttr($element->getName()),
            $this->escapeHtmlAttr($json)
        );
        $html .= $this->renderTable($fieldId, $state);
        $html .= $this->renderStyles($fieldId);
        $html .= $this->renderScript($fieldId);

        return $html;
    }

    private function buildState()
    {
        $policy = $this->policyConfig->getPolicy();
        $stores = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            $storePolicy = $policy['stores'][$storeId] ?? [];
            $stores[$storeId] = [
                'enabled' => !array_key_exists('enabled', $storePolicy) || (bool)$storePolicy['enabled'],
                'priority_offset' => (int)($storePolicy['priority_offset'] ?? 0),
                'variant_policy' => (string)($storePolicy['variant_policy'] ?? StoreWarmupPolicyConfig::VARIANT_POLICY_GLOBAL),
                'variants' => is_array($storePolicy['variants'] ?? null) ? $storePolicy['variants'] : [],
            ];
        }

        return [
            'version' => 1,
            'stores' => $stores,
            'variants' => $this->getVariants(),
        ];
    }

    private function renderTable($fieldId, array $state)
    {
        $html = '<div class="litemage-swp" id="' . $this->escapeHtmlAttr($fieldId) . '_policy">';
        $html .= '<table class="litemage-swp-table"><thead><tr>';
        $html .= '<th>' . $this->escapeHtml(__('Store View')) . '</th>';
        $html .= '<th>' . $this->escapeHtml(__('Enabled')) . '</th>';
        $html .= '<th>' . $this->escapeHtml(__('Priority Offset')) . '</th>';
        $html .= '<th>' . $this->escapeHtml(__('Variant Policy')) . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            $storeState = $state['stores'][$storeId] ?? [
                'enabled' => true,
                'priority_offset' => 0,
                'variant_policy' => StoreWarmupPolicyConfig::VARIANT_POLICY_GLOBAL,
                'variants' => [],
            ];
            $html .= '<tr data-store-id="' . $storeId . '">';
            $html .= '<td><strong>' . $this->escapeHtml($store->getName()) . '</strong>';
            $html .= '<small>' . $this->escapeHtml(sprintf(
                '%s / %s / ID %d',
                $store->getWebsite()->getName(),
                $store->getGroup()->getName(),
                $storeId
            )) . '</small></td>';
            $html .= '<td>' . $this->switchButton($storeId, (bool)$storeState['enabled']) . '</td>';
            $html .= '<td><input class="litemage-swp-offset" type="number" min="0" max="9999" value="' . (int)$storeState['priority_offset'] . '" /></td>';
            $html .= '<td>' . $this->renderVariantPolicy($storeId, $storeState) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function renderVariantPolicy($storeId, array $storeState)
    {
        $policy = (string)($storeState['variant_policy'] ?? StoreWarmupPolicyConfig::VARIANT_POLICY_GLOBAL);
        $html = '<select class="admin__control-select litemage-swp-variant-policy">';
        foreach ([
            StoreWarmupPolicyConfig::VARIANT_POLICY_GLOBAL => __('Use Global Variants'),
            StoreWarmupPolicyConfig::VARIANT_POLICY_GUEST_ONLY => __('Guest Only'),
            StoreWarmupPolicyConfig::VARIANT_POLICY_CUSTOM => __('Custom'),
        ] as $value => $label) {
            $html .= '<option value="' . $this->escapeHtmlAttr($value) . '"' . ($policy === $value ? ' selected="selected"' : '') . '>';
            $html .= $this->escapeHtml($label) . '</option>';
        }
        $html .= '</select>';

        $html .= '<div class="litemage-swp-variants' . ($policy === StoreWarmupPolicyConfig::VARIANT_POLICY_CUSTOM ? '' : ' is-hidden') . '">';
        foreach ($this->getVariants() as $variant) {
            if (!empty($variant['locked']) || $variant['key'] === QueueVariantConfig::PROFILE_GUEST) {
                continue;
            }
            $enabled = !empty($storeState['variants'][$variant['key']]['enabled']);
            $id = 'litemage_swp_' . (int)$storeId . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $variant['key']);
            $html .= '<label for="' . $this->escapeHtmlAttr($id) . '">';
            $html .= '<input id="' . $this->escapeHtmlAttr($id) . '" class="litemage-swp-variant" type="checkbox" value="' . $this->escapeHtmlAttr($variant['key']) . '"' . ($enabled ? ' checked="checked"' : '') . ' />';
            $html .= '<span>' . $this->escapeHtml($variant['label']) . '</span>';
            $html .= '</label>';
        }
        $html .= '</div>';

        return $html;
    }

    private function switchButton($storeId, $enabled)
    {
        $class = 'litemage-swp-switch' . ($enabled ? ' is-on' : '');
        return sprintf(
            '<button type="button" class="%s" data-store-id="%d" aria-pressed="%s"><span>%s</span></button>',
            $this->escapeHtmlAttr($class),
            (int)$storeId,
            $enabled ? 'true' : 'false',
            $this->escapeHtml($enabled ? __('On') : __('Off'))
        );
    }

    private function renderStyles($fieldId)
    {
        $css = <<<CSS
#{$fieldId}_policy .litemage-swp-table{border-collapse:collapse;max-width:72rem;width:100%}
#{$fieldId}_policy .litemage-swp-table th{background:#f5f5f5;border-bottom:1px solid #d6d6d6;padding:.8rem;text-align:left;white-space:nowrap}
#{$fieldId}_policy .litemage-swp-table td{border-top:1px solid #eee;padding:.8rem;vertical-align:middle}
#{$fieldId}_policy .litemage-swp-table small{color:#777;display:block;margin-top:.2rem}
#{$fieldId}_policy .litemage-swp-offset{max-width:7rem}
#{$fieldId}_policy .litemage-swp-variants{display:grid;gap:.3rem;margin-top:.6rem}
#{$fieldId}_policy .litemage-swp-variants.is-hidden{display:none}
#{$fieldId}_policy .litemage-swp-variants label{align-items:center;display:flex;gap:.5rem;margin:0}
#{$fieldId}_policy .litemage-swp-variants span{font-size:1.2rem}
#{$fieldId}_policy .litemage-swp-switch{background:#777;border:0;border-radius:10rem;color:#fff;cursor:pointer;font-size:1.2rem;font-weight:600;line-height:1;padding:.45rem .8rem;min-width:4.8rem}
#{$fieldId}_policy .litemage-swp-switch.is-on{background:#007b3d}
CSS;

        return $this->renderTag('style', [], $css);
    }

    private function renderScript($fieldId)
    {
        $script = <<<JS
(function () {
    var root = document.getElementById('{$fieldId}_policy');
    var input = document.getElementById('{$fieldId}');
    if (!root || !input) {
        return;
    }
    var state = input.value ? JSON.parse(input.value) : {version: 1, stores: {}};
    function clamp(value) {
        value = parseInt(value, 10);
        if (isNaN(value) || value < 0) {
            return 0;
        }
        return value > 9999 ? 9999 : value;
    }
    function ensureStore(storeId) {
        state.stores[storeId] = state.stores[storeId] || {enabled: true, priority_offset: 0, variant_policy: 'global', variants: {}};
        state.stores[storeId].variants = state.stores[storeId].variants || {};
        return state.stores[storeId];
    }
    function writeState() {
        input.value = JSON.stringify(state);
    }
    function updateSwitch(button, enabled) {
        button.classList.toggle('is-on', enabled);
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        button.querySelector('span').textContent = enabled ? 'On' : 'Off';
    }
    root.addEventListener('click', function (event) {
        var button = event.target.closest('.litemage-swp-switch');
        if (!button) {
            return;
        }
        var storeId = button.getAttribute('data-store-id');
        var store = ensureStore(storeId);
        store.enabled = !store.enabled;
        updateSwitch(button, store.enabled);
        writeState();
    });
    root.addEventListener('change', function (event) {
        var row = event.target.closest('tr[data-store-id]');
        if (!row) {
            return;
        }
        var store = ensureStore(row.getAttribute('data-store-id'));
        if (event.target.classList.contains('litemage-swp-offset')) {
            store.priority_offset = clamp(event.target.value);
            event.target.value = store.priority_offset;
        }
        if (event.target.classList.contains('litemage-swp-variant-policy')) {
            store.variant_policy = event.target.value;
            var custom = row.querySelector('.litemage-swp-variants');
            if (custom) {
                custom.classList.toggle('is-hidden', store.variant_policy !== 'custom');
            }
        }
        if (event.target.classList.contains('litemage-swp-variant')) {
            var variantKey = event.target.value;
            store.variants[variantKey] = store.variants[variantKey] || {};
            store.variants[variantKey].enabled = !!event.target.checked;
        }
        writeState();
    });
})();
JS;

        return $this->renderTag('script', [], $script);
    }

    private function renderTag($tag, array $attributes, $content)
    {
        if ($this->secureRenderer) {
            return $this->secureRenderer->renderTag($tag, $attributes, $content, false);
        }

        return '<' . $tag . '>' . $content . '</' . $tag . '>';
    }

    private function getVariants()
    {
        $view = $this->mapBuilder->build();
        return is_array($view['variants'] ?? null) ? $view['variants'] : [];
    }
}
