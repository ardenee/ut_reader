<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class FilterBar
{
    /**
     * @param array{method?:string,action?:string,id?:string,class?:string,hidden?:array<string,scalar>,loading_label?:string,described_by?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(string $fields, string $actions, array $props = []): string
    {
        $method = Html::enum(strtolower((string)($props['method'] ?? 'get')), ['get', 'post'], 'get');
        $action = trim((string)($props['action'] ?? ''));
        $class = Html::classes('ui-filter-bar', (string)($props['class'] ?? ''));
        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        $attributes['data-ui-loading-form'] = true;
        if (trim((string)($props['id'] ?? '')) !== '') {
            $attributes['id'] = (string)$props['id'];
        }
        if (trim((string)($props['described_by'] ?? '')) !== '') {
            $attributes['aria-describedby'] = (string)$props['described_by'];
        }

        $html = '<form class="' . Html::escape($class) . '" method="' . $method . '"'
            . ($action !== '' ? ' action="' . Html::escape($action) . '"' : '')
            . Html::attributes($attributes) . '>';
        foreach ((array)($props['hidden'] ?? []) as $name => $value) {
            $html .= '<input type="hidden" name="' . Html::escape($name) . '" value="' . Html::escape($value) . '">';
        }
        $html .= '<div class="ui-filter-bar__fields">' . $fields . '</div>';
        $html .= '<div class="ui-filter-bar__actions">' . $actions;
        $loadingLabel = trim((string)($props['loading_label'] ?? 'Applying filters…'));
        if ($loadingLabel !== '') {
            $html .= '<span class="ui-filter-bar__loading" data-ui-loading-indicator>' . LoadingState::render($loadingLabel, true) . '</span>';
        }
        $html .= '</div></form>';

        return $html;
    }
}
