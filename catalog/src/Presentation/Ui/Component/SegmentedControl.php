<?php
/**
 * Accessible grouped buttons for filters and view modes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class SegmentedControl
{
    /**
     * @param list<array{label:string,value:string,active?:bool,count?:int|string,attributes?:array<string,scalar|null>}> $items
     * @param array{id?:string,label?:string,class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(array $items, array $props = []): string
    {
        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        $attributes['role'] = 'group';
        $label = trim((string)($props['label'] ?? ''));
        if ($label !== '') {
            $attributes['aria-label'] = $label;
        }
        $id = trim((string)($props['id'] ?? ''));
        if ($id !== '') {
            $attributes['id'] = $id;
        }

        $class = Html::classes('ui-segmented', 'ui-action-group', (string)($props['class'] ?? ''));
        $html = '<div class="' . Html::escape($class) . '"' . Html::attributes($attributes) . '>';
        foreach ($items as $item) {
            $itemLabel = trim((string)($item['label'] ?? ''));
            if ($itemLabel === '') {
                continue;
            }
            $itemAttributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $itemAttributes['data-value'] = (string)($item['value'] ?? '');
            $itemAttributes['aria-pressed'] = !empty($item['active']) ? 'true' : 'false';
            $buttonClass = Html::classes('ui-segmented__item', 'ui-button', 'ui-button--quiet', 'ui-button--sm');
            $html .= '<button class="' . Html::escape($buttonClass) . '" type="button"' . Html::attributes($itemAttributes) . '>';
            $html .= '<span class="ui-segmented__label">' . Html::escape($itemLabel) . '</span>';
            if (array_key_exists('count', $item)) {
                $html .= ' <span class="ui-segmented__count" aria-hidden="true">' . Html::escape($item['count']) . '</span>';
            }
            $html .= '</button>';
        }

        return $html . '</div>';
    }
}
