<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Button
{
    /**
     * @param array{href?:string,type?:string,variant?:string,size?:string,disabled?:bool,class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(string $label, array $props = []): string
    {
        $href = isset($props['href']) ? trim((string)$props['href']) : '';
        $variant = Html::enum((string)($props['variant'] ?? 'primary'), ['primary', 'secondary', 'danger', 'quiet'], 'primary');
        $size = Html::enum((string)($props['size'] ?? 'md'), ['sm', 'md'], 'md');
        $disabled = (bool)($props['disabled'] ?? false);
        $class = Html::classes('ui-button', 'ui-button--' . $variant, 'ui-button--' . $size, (string)($props['class'] ?? ''));
        $attributes = Html::attributes(is_array($props['attributes'] ?? null) ? $props['attributes'] : []);

        if ($href !== '') {
            if ($disabled) {
                return '<span class="' . Html::escape($class) . '" aria-disabled="true">' . Html::escape($label) . '</span>';
            }

            return '<a class="' . Html::escape($class) . '" href="' . Html::escape($href) . '"' . $attributes . '>'
                . Html::escape($label) . '</a>';
        }

        $type = Html::enum((string)($props['type'] ?? 'button'), ['button', 'submit', 'reset'], 'button');

        return '<button class="' . Html::escape($class) . '" type="' . $type . '"'
            . ($disabled ? ' disabled aria-disabled="true"' : '')
            . $attributes . '>' . Html::escape($label) . '</button>';
    }
}
