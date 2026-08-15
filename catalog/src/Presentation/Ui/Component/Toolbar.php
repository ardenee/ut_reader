<?php
/**
 * Reusable responsive action bar for server-rendered catalog pages.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Toolbar
{
    /**
     * @param array{id?:string,label?:string,class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(string $actionsHtml, string $asideHtml = '', array $props = []): string
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

        $class = Html::classes('ui-toolbar', (string)($props['class'] ?? ''));
        $html = '<div class="' . Html::escape($class) . '"' . Html::attributes($attributes) . '>';
        $html .= '<div class="ui-toolbar__actions">' . $actionsHtml . '</div>';
        if ($asideHtml !== '') {
            $html .= '<div class="ui-toolbar__aside">' . $asideHtml . '</div>';
        }

        return $html . '</div>';
    }
}
