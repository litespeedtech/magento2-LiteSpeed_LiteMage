<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license   https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Block\Adminhtml\System\Config;

use Litespeed\Litemage\Model\Warmup\QueueVariantMapBuilder;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Backend\Block\Template\Context;

class QueueVariantMap extends Field
{
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
        QueueVariantMapBuilder $mapBuilder,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
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

        $html = '<td class="value litemage-qvm-field" colspan="4">';
        $html .= $this->_getElementHtml($element);
        if ($element->getComment()) {
            $html .= '<p class="note"><span>' . $this->escapeHtml($element->getComment()) . '</span></p>';
        }
        $html .= '</td>';

        return $this->_decorateRowHtml($element, $html);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $view = $this->mapBuilder->build();
        $state = $this->buildState($view);
        $fieldId = $element->getHtmlId();
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $json = json_encode($state, $jsonFlags);
        $viewJson = json_encode($view, $jsonFlags);
        if ($json === false) {
            $json = '{"version":1,"queues":{}}';
        }
        if ($viewJson === false) {
            $viewJson = '{"version":1,"queues":[],"variants":[],"summary":{}}';
        }

        $html = sprintf(
            '<input type="hidden" id="%s" name="%s" value="%s" />',
            $this->escapeHtmlAttr($fieldId),
            $this->escapeHtmlAttr($element->getName()),
            $this->escapeHtmlAttr($json)
        );
        $html .= $this->renderMap($fieldId, $view);
        $html .= $this->renderStyles($fieldId);
        $html .= $this->renderScript($fieldId, $viewJson);

        return $html;
    }

    private function buildState(array $view)
    {
        $queues = [];
        foreach ($view['queues'] as $queue) {
            $queues[$queue['key']] = [
                'enabled' => (bool)$queue['enabled'],
                'priority' => (int)$queue['priority'],
                'variants' => $queue['variants'],
            ];
        }

        return [
            'version' => 1,
            'queues' => $queues,
        ];
    }

    private function renderMap($fieldId, array $view)
    {
        $summary = $view['summary'];
        $html = '<div class="litemage-qvm" id="' . $this->escapeHtmlAttr($fieldId) . '_map">';
        $html .= '<div class="litemage-qvm-summary">';
        $html .= '<span>' . $this->escapeHtml(__(
            '%1 queue(s), %2 enabled, %3 variant(s), %4 queued URL row(s)',
            (int)$summary['queues'],
            (int)$summary['enabled_queues'],
            (int)$summary['variants'],
            (int)$summary['rows']
        )) . '</span>';
        if (!empty($view['queues'])) {
            $html .= '<span class="litemage-qvm-actions">';
            $html .= '<button type="button" class="action-secondary litemage-qvm-small-action litemage-qvm-expand-all">' . $this->escapeHtml(__('Expand All')) . '</button>';
            $html .= '<button type="button" class="action-secondary litemage-qvm-small-action litemage-qvm-collapse-all">' . $this->escapeHtml(__('Collapse All')) . '</button>';
            $html .= '</span>';
        }
        $html .= '</div>';
        if (empty($view['queues'])) {
            $html .= '<div class="litemage-qvm-empty">' . $this->escapeHtml(__('No enabled warmup source instances are configured.')) . '</div></div>';
            return $html;
        }

        $html .= '<div class="litemage-qvm-scroll"><table class="litemage-qvm-table"><thead><tr>';
        $html .= '<th>' . $this->escapeHtml(__('Queue')) . '</th>';
        $html .= '<th>' . $this->escapeHtml(__('Variant Details')) . '</th>';
        $html .= '<th>' . $this->escapeHtml(__('Enabled')) . '</th>';
        $priorityHelp = __('Lower number means higher priority. Variant priority is an offset added to the queue priority.');
        $html .= '<th>' . $this->escapeHtml(__('Priority')) . ' <span class="litemage-qvm-info" title="' . $this->escapeHtmlAttr($priorityHelp) . '" aria-label="' . $this->escapeHtmlAttr($priorityHelp) . '">i</span></th>';
        $html .= '</tr></thead><tbody>';

        foreach ($view['queues'] as $queue) {
            $disabledClass = $queue['enabled'] ? '' : ' is-disabled';
            $variantCount = count($view['variants']);
            $enabledVariantCount = count(array_filter($queue['variants'], function ($variantState) {
                return !empty($variantState['enabled']);
            }));
            $html .= '<tr data-row-type="queue" data-queue-key="' . $this->escapeHtmlAttr($queue['key']) . '" class="litemage-qvm-queue-row' . $disabledClass . '">';
            $html .= '<td colspan="2"><div class="litemage-qvm-queue-title"><span class="litemage-qvm-chevron" aria-hidden="true"></span><strong>' . $this->escapeHtml($queue['label']) . '</strong><small>' . $this->escapeHtml(__(
                '%1, %2 queued URL row(s)',
                $queue['source'],
                (int)$queue['rows']
            )) . '</small><span class="litemage-qvm-variant-summary">' . $this->escapeHtml(__(
                '%1 enabled / %2 variant(s)',
                $enabledVariantCount,
                $variantCount
            )) . '</span></div></td>';
            $html .= '<td>' . $this->switchButton('queue', $queue['key'], '', $queue['enabled'], false) . '</td>';
            $html .= '<td><input class="litemage-qvm-priority" type="number" min="0" max="9999" value="' . (int)$queue['priority'] . '" /></td>';
            $html .= '</tr>';
            foreach ($view['variants'] as $variant) {
                $variantState = $queue['variants'][$variant['key']] ?? ['enabled' => true, 'offset' => $variant['default_offset']];
                $variantDisabled = !$queue['enabled'];
                $html .= '<tr data-row-type="variant" data-queue-key="' . $this->escapeHtmlAttr($queue['key']) . '" data-variant-key="' . $this->escapeHtmlAttr($variant['key']) . '" class="litemage-qvm-variant-row is-collapsed' . $disabledClass . '">';
                $html .= '<td class="litemage-qvm-spacer"></td>';
                $html .= '<td class="litemage-qvm-variant-label"><span>' . $this->escapeHtml($variant['label']) . '</span>';
                if (!empty($variant['detail'])) {
                    $html .= '<small>' . $this->escapeHtml($variant['detail']) . '</small>';
                } elseif (!empty($variant['type_label'])) {
                    $html .= '<small>' . $this->escapeHtml($variant['type_label']) . '</small>';
                }
                $html .= '</td>';
                $html .= '<td>' . $this->switchButton('variant', $queue['key'], $variant['key'], (bool)$variantState['enabled'], (bool)$variant['locked'], $variantDisabled) . '</td>';
                $html .= '<td>';
                if (!empty($variant['locked'])) {
                    $html .= '<span class="litemage-qvm-offset is-locked">+0</span>';
                } else {
                    $html .= '<span class="litemage-qvm-offset-wrap"><span>+</span><input class="litemage-qvm-offset" type="number" min="0" max="9999" value="' . (int)$variantState['offset'] . '"' . ($variantDisabled ? ' disabled="disabled"' : '') . ' /></span>';
                }
                $html .= '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div></div>';
        return $html;
    }

    private function switchButton($type, $queueKey, $variantKey, $enabled, $locked, $disabled = false)
    {
        $class = 'litemage-qvm-switch' . ($enabled ? ' is-on' : '') . ($locked ? ' is-locked' : '') . ($disabled ? ' is-disabled-control' : '');
        return sprintf(
            '<button type="button" class="%s" data-type="%s" data-queue-key="%s" data-variant-key="%s" data-locked="%s" aria-pressed="%s"%s><span>%s</span></button>',
            $this->escapeHtmlAttr($class),
            $this->escapeHtmlAttr($type),
            $this->escapeHtmlAttr($queueKey),
            $this->escapeHtmlAttr($variantKey),
            $locked ? '1' : '0',
            $enabled ? 'true' : 'false',
            ($locked || $disabled) ? ' disabled="disabled"' : '',
            $this->escapeHtml($enabled ? __('On') : __('Off'))
        );
    }

    private function renderStyles($fieldId)
    {
        $css = <<<CSS
#{$fieldId}_map .litemage-qvm-summary{align-items:center;display:flex;flex-wrap:wrap;gap:.8rem 1.2rem;margin-bottom:.6rem}
#{$fieldId}_map .litemage-qvm-summary span{color:#666}
#{$fieldId}_map .litemage-qvm-actions{display:inline-flex;gap:.4rem}
#{$fieldId}_map .litemage-qvm-small-action{font-size:1.1rem;line-height:1;padding:.35rem .7rem}
#{$fieldId}_map .litemage-qvm-scroll{border:1px solid #d6d6d6;max-width:100%;overflow:auto}
#{$fieldId}_map .litemage-qvm-table{border-collapse:collapse;min-width:58rem;width:100%}
#{$fieldId}_map .litemage-qvm-table th{background:#f5f5f5;border-bottom:1px solid #d6d6d6;padding:.8rem;text-align:left;white-space:nowrap}
#{$fieldId}_map .litemage-qvm-table td{border-top:1px solid #eee;padding:.8rem;vertical-align:middle}
#{$fieldId}_map .litemage-qvm-table tr.is-disabled td{background:#fafafa;color:#777}
#{$fieldId}_map .litemage-qvm-table tr.is-dirty td{box-shadow:inset 3px 0 0 #eb5202}
#{$fieldId}_map .litemage-qvm-queue-row{cursor:pointer}
#{$fieldId}_map .litemage-qvm-queue-row td{background:#fcfcfc}
#{$fieldId}_map .litemage-qvm-queue-row:hover td{background:#f7f7f7}
#{$fieldId}_map .litemage-qvm-queue-title{align-items:center;display:flex;gap:.8rem}
#{$fieldId}_map .litemage-qvm-queue-title strong{font-size:1.4rem}
#{$fieldId}_map .litemage-qvm-queue-title small{margin:0}
#{$fieldId}_map .litemage-qvm-variant-row td{border-top:0}
#{$fieldId}_map .litemage-qvm-variant-row.is-collapsed{display:none}
#{$fieldId}_map .litemage-qvm-spacer{border-right:1px solid #eee;width:2.4rem}
#{$fieldId}_map .litemage-qvm-chevron{border:solid #666;border-width:0 2px 2px 0;display:inline-block;height:.7rem;margin:0 .9rem .1rem .2rem;transform:rotate(-45deg);transition:transform .12s ease;width:.7rem}
#{$fieldId}_map .litemage-qvm-queue-row.is-expanded .litemage-qvm-chevron{transform:rotate(45deg)}
#{$fieldId}_map .litemage-qvm-info{border:1px solid #8a8a8a;border-radius:50%;color:#666;cursor:help;display:inline-block;font-size:1rem;font-weight:700;height:1.4rem;line-height:1.25rem;text-align:center;vertical-align:middle;width:1.4rem}
#{$fieldId}_map .litemage-qvm-variant-summary{color:#555}
#{$fieldId}_map .litemage-qvm-table small{color:#777;display:block;margin-top:.2rem}
#{$fieldId}_map .litemage-qvm-priority,#{$fieldId}_map .litemage-qvm-offset{max-width:7rem}
#{$fieldId}_map .litemage-qvm-switch{background:#777;border:0;border-radius:10rem;color:#fff;cursor:pointer;font-size:1.2rem;font-weight:600;line-height:1;padding:.45rem .8rem;min-width:4.8rem}
#{$fieldId}_map .litemage-qvm-switch.is-on{background:#007b3d}
#{$fieldId}_map .litemage-qvm-switch.is-locked,#{$fieldId}_map .litemage-qvm-switch.is-disabled-control{background:#555;cursor:not-allowed;opacity:.85}
#{$fieldId}_map .litemage-qvm-variant-label span{font-weight:600}
#{$fieldId}_map .litemage-qvm-offset-wrap{align-items:center;display:inline-flex;gap:.25rem}
#{$fieldId}_map .litemage-qvm-offset.is-locked{color:#555;font-weight:600}
#{$fieldId}_map .litemage-qvm-empty{background:#fff8d6;border:1px solid #e2c35d;padding:.9rem}
CSS;

        return $this->renderTag('style', [], $css);
    }

    private function renderScript($fieldId, $viewJson)
    {
        $script = <<<JS
(function () {
    var root = document.getElementById('{$fieldId}_map');
    var input = document.getElementById('{$fieldId}');
    if (!root || !input) {
        return;
    }
    var storageKey = 'litemage.qvm.expanded.{$fieldId}';
    var view = {$viewJson};
    var state = input.value ? JSON.parse(input.value) : {version: 1, queues: {}};
    function readExpanded() {
        try {
            return JSON.parse(window.localStorage.getItem(storageKey) || '{}');
        } catch (error) {
            return {};
        }
    }
    var expanded = readExpanded();
    function writeExpanded() {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(expanded));
        } catch (error) {
        }
    }
    function clamp(value) {
        value = parseInt(value, 10);
        if (isNaN(value) || value < 0) {
            return 0;
        }
        return value > 9999 ? 9999 : value;
    }
    function getRows(queueKey) {
        return Array.prototype.slice.call(root.querySelectorAll('tr')).filter(function (row) {
            return row.getAttribute('data-queue-key') === queueKey;
        });
    }
    function getQueueRow(queueKey) {
        var rows = Array.prototype.slice.call(root.querySelectorAll('tr[data-row-type="queue"]'));
        for (var index = 0; index < rows.length; index++) {
            if (rows[index].getAttribute('data-queue-key') === queueKey) {
                return rows[index];
            }
        }
        return null;
    }
    function getVariantRows(queueKey) {
        return getRows(queueKey).filter(function (row) {
            return row.getAttribute('data-row-type') === 'variant';
        });
    }
    function setExpanded(queueKey, isExpanded, persist) {
        var queueRow = getQueueRow(queueKey);
        if (queueRow) {
            queueRow.classList.toggle('is-expanded', isExpanded);
        }
        getVariantRows(queueKey).forEach(function (row) {
            row.classList.toggle('is-collapsed', !isExpanded);
        });
        expanded[queueKey] = !!isExpanded;
        if (persist) {
            writeExpanded();
        }
    }
    function setAllExpanded(isExpanded) {
        Array.prototype.slice.call(root.querySelectorAll('tr[data-row-type="queue"]')).forEach(function (row) {
            setExpanded(row.getAttribute('data-queue-key'), isExpanded, false);
        });
        writeExpanded();
    }
    function markDirty(queueKey) {
        getRows(queueKey).forEach(function (row) {
            row.classList.add('is-dirty');
        });
        setExpanded(queueKey, true, true);
    }
    function setQueueDisabled(queueKey, disabled) {
        getRows(queueKey).forEach(function (row) {
            row.classList.toggle('is-disabled', disabled);
            if (row.getAttribute('data-row-type') !== 'variant') {
                return;
            }
            Array.prototype.slice.call(row.querySelectorAll('.litemage-qvm-switch')).forEach(function (button) {
                var locked = button.getAttribute('data-locked') === '1';
                button.disabled = disabled || locked;
                button.classList.toggle('is-disabled-control', disabled);
            });
            Array.prototype.slice.call(row.querySelectorAll('.litemage-qvm-offset')).forEach(function (input) {
                if (input.tagName.toLowerCase() === 'input') {
                    input.disabled = disabled;
                }
            });
        });
    }
    function writeState() {
        input.value = JSON.stringify(state);
    }
    function updateSwitch(button, enabled) {
        button.classList.toggle('is-on', enabled);
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        button.querySelector('span').textContent = enabled ? 'On' : 'Off';
    }
    function updateVariantSummary(queueKey) {
        var queue = state.queues[queueKey] || {variants: {}};
        var enabledCount = 0;
        var totalCount = view.variants ? view.variants.length : 0;
        (view.variants || []).forEach(function (variant) {
            var variantState = queue.variants && queue.variants[variant.key] ? queue.variants[variant.key] : null;
            if (!variantState || variantState.enabled) {
                enabledCount++;
            }
        });
        var row = getQueueRow(queueKey);
        var summary = row ? row.querySelector('.litemage-qvm-variant-summary') : null;
        if (summary) {
            summary.textContent = enabledCount + ' enabled / ' + totalCount + ' variant(s)';
        }
    }
    function sortRows() {
        var tbody = root.querySelector('tbody');
        if (!tbody) {
            return;
        }
        var variants = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-row-type="variant"]'));
        Array.prototype.slice.call(tbody.querySelectorAll('tr[data-row-type="queue"]')).sort(function (a, b) {
            var aKey = a.getAttribute('data-queue-key');
            var bKey = b.getAttribute('data-queue-key');
            var aPriority = state.queues[aKey] ? state.queues[aKey].priority : 100;
            var bPriority = state.queues[bKey] ? state.queues[bKey].priority : 100;
            if (aPriority === bPriority) {
                return a.textContent.localeCompare(b.textContent);
            }
            return aPriority - bPriority;
        }).forEach(function (row) {
            var queueKey = row.getAttribute('data-queue-key');
            tbody.appendChild(row);
            variants.forEach(function (variantRow) {
                if (variantRow.getAttribute('data-queue-key') === queueKey) {
                    tbody.appendChild(variantRow);
                }
            });
        });
    }
    root.addEventListener('click', function (event) {
        var action = event.target.closest('.litemage-qvm-expand-all,.litemage-qvm-collapse-all');
        if (action) {
            setAllExpanded(action.classList.contains('litemage-qvm-expand-all'));
            return;
        }
        var button = event.target.closest('.litemage-qvm-switch');
        if (button) {
            if (button.disabled) {
                return;
            }
            var row = button.closest('tr');
            var queueKey = button.getAttribute('data-queue-key');
            var type = button.getAttribute('data-type');
            state.queues[queueKey] = state.queues[queueKey] || {enabled: true, priority: 100, variants: {}};
            if (type === 'queue') {
                state.queues[queueKey].enabled = !state.queues[queueKey].enabled;
                setQueueDisabled(queueKey, !state.queues[queueKey].enabled);
                updateSwitch(button, state.queues[queueKey].enabled);
            } else {
                var variantKey = button.getAttribute('data-variant-key');
                state.queues[queueKey].variants[variantKey] = state.queues[queueKey].variants[variantKey] || {enabled: true, offset: 0};
                state.queues[queueKey].variants[variantKey].enabled = !state.queues[queueKey].variants[variantKey].enabled;
                updateSwitch(button, state.queues[queueKey].variants[variantKey].enabled);
            }
            updateVariantSummary(queueKey);
            markDirty(queueKey);
            writeState();
            return;
        }
        if (event.target.closest('input,button,a,select,textarea,label')) {
            return;
        }
        var queueRow = event.target.closest('tr[data-row-type="queue"]');
        if (queueRow) {
            var clickedQueueKey = queueRow.getAttribute('data-queue-key');
            setExpanded(clickedQueueKey, !queueRow.classList.contains('is-expanded'), true);
        }
    });
    root.addEventListener('change', function (event) {
        var row = event.target.closest('tr');
        if (!row) {
            return;
        }
        var queueKey = row.getAttribute('data-queue-key');
        state.queues[queueKey] = state.queues[queueKey] || {enabled: true, priority: 100, variants: {}};
        if (event.target.classList.contains('litemage-qvm-priority')) {
            state.queues[queueKey].priority = clamp(event.target.value);
            event.target.value = state.queues[queueKey].priority;
            sortRows();
        }
        if (event.target.classList.contains('litemage-qvm-offset')) {
            var variantKey = row.getAttribute('data-variant-key');
            if (variantKey) {
                state.queues[queueKey].variants[variantKey] = state.queues[queueKey].variants[variantKey] || {enabled: true, offset: 0};
                state.queues[queueKey].variants[variantKey].offset = clamp(event.target.value);
                event.target.value = state.queues[queueKey].variants[variantKey].offset;
            }
        }
        markDirty(queueKey);
        writeState();
    });
    Array.prototype.slice.call(root.querySelectorAll('tr[data-row-type="queue"]')).forEach(function (row) {
        var queueKey = row.getAttribute('data-queue-key');
        setExpanded(queueKey, !!expanded[queueKey], false);
        updateVariantSummary(queueKey);
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
}
