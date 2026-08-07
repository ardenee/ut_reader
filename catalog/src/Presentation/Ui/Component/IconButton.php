<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `IconButton` for icon button.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use InvalidArgumentException;
use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class IconButton
{
    /**
     * @param array{label:string,icon:string,href?:string,type?:string,variant?:string,size?:string,disabled?:bool,class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(array $props): string
    {
        $label = trim((string)($props['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('IconButton requires a non-empty accessible label.');
        }

        $icon = (string)($props['icon'] ?? '');
        $href = isset($props['href']) ? trim((string)$props['href']) : '';
        $variant = Html::enum((string)($props['variant'] ?? 'secondary'), ['primary', 'secondary', 'danger', 'quiet'], 'secondary');
        $size = Html::enum((string)($props['size'] ?? 'md'), ['sm', 'md'], 'md');
        $disabled = (bool)($props['disabled'] ?? false);
        $class = Html::classes('ui-icon-action', 'ui-icon-action--' . $variant, 'ui-icon-action--' . $size, (string)($props['class'] ?? ''));
        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        $attributes['aria-label'] = $label;
        if (!isset($attributes['title'])) {
            $attributes['title'] = $label;
        }
        $attributeHtml = Html::attributes($attributes);
        $content = '<span aria-hidden="true">' . Html::escape($icon) . '</span>';

        if ($href !== '') {
            if ($disabled) {
                return '<span class="' . Html::escape($class) . '" aria-disabled="true"' . $attributeHtml . '>' . $content . '</span>';
            }

            return '<a class="' . Html::escape($class) . '" href="' . Html::escape($href) . '"' . $attributeHtml . '>' . $content . '</a>';
        }

        $type = Html::enum((string)($props['type'] ?? 'button'), ['button', 'submit', 'reset'], 'button');

        return '<button class="' . Html::escape($class) . '" type="' . $type . '"'
            . ($disabled ? ' disabled aria-disabled="true"' : '')
            . $attributeHtml . '>' . $content . '</button>';
    }
}
