<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Progress
{
    /** @param array{value?:int,max?:int,label?:string,description?:string,class?:string,id?:string} $props */
    public static function render(array $props = []): string
    {
        $max = max(1, (int)($props['max'] ?? 100));
        $value = max(0, min($max, (int)($props['value'] ?? 0)));
        $label = trim((string)($props['label'] ?? 'Progress'));
        $description = trim((string)($props['description'] ?? ''));
        $id = trim((string)($props['id'] ?? '')) ?: Html::uniqueId('ui-progress');
        $class = Html::classes('ui-progress', (string)($props['class'] ?? ''));
        $percent = (int)round(($value / $max) * 100);

        $html = '<div class="' . Html::escape($class) . '">';
        $html .= '<div class="ui-progress__meta"><label for="' . Html::escape($id) . '">' . Html::escape($label) . '</label>';
        $html .= '<span>' . $percent . '%</span></div>';
        $html .= '<progress id="' . Html::escape($id) . '" value="' . $value . '" max="' . $max . '">' . $percent . '%</progress>';
        if ($description !== '') {
            $html .= '<p class="ui-progress__description">' . Html::escape($description) . '</p>';
        }

        return $html . '</div>';
    }
}
