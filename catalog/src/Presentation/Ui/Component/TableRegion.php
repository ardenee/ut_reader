<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class TableRegion
{
    /** @param array{label?:string,busy?:bool,class?:string,id?:string} $props */
    public static function render(string $tableHtml, array $props = []): string
    {
        $attributes = [];
        if (trim((string)($props['label'] ?? '')) !== '') {
            $attributes['aria-label'] = (string)$props['label'];
        }
        if (!empty($props['busy'])) {
            $attributes['aria-busy'] = 'true';
        }
        if (trim((string)($props['id'] ?? '')) !== '') {
            $attributes['id'] = (string)$props['id'];
        }
        $class = Html::classes('ui-table-region', (string)($props['class'] ?? ''));

        return '<div class="' . Html::escape($class) . '"' . Html::attributes($attributes) . '>' . $tableHtml . '</div>';
    }

    /**
     * @param list<string> $headers
     */
    public static function skeleton(array $headers, int $rows = 4, string $label = 'Loading table data'): string
    {
        $rows = max(1, min($rows, 12));
        $columnCount = max(1, count($headers));
        $html = '<table class="ui-table ui-table--skeleton"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th scope="col">' . Html::escape($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($row = 0; $row < $rows; $row++) {
            $html .= '<tr>';
            for ($column = 0; $column < $columnCount; $column++) {
                $html .= '<td><span class="ui-skeleton" aria-hidden="true"></span><span class="ui-sr-only">Loading</span></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return self::render($html, ['label' => $label, 'busy' => true]);
    }
}
