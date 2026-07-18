<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class TableRegion
{
    /** @param array{label?:string,busy?:bool,class?:string,id?:string,focusable?:bool} $props */
    public static function render(string $tableHtml, array $props = []): string
    {
        $attributes = [];
        $label = trim((string)($props['label'] ?? ''));
        if ($label !== '') {
            $attributes['role'] = 'region';
            $attributes['aria-label'] = $label;
        }
        if (!empty($props['busy'])) {
            $attributes['aria-busy'] = 'true';
        }
        if (trim((string)($props['id'] ?? '')) !== '') {
            $attributes['id'] = (string)$props['id'];
        }
        if (($props['focusable'] ?? true) && $label !== '') {
            $attributes['tabindex'] = '0';
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
        $statusId = Html::uniqueId('ui-table-loading');
        $html = '<span class="ui-sr-only" id="' . Html::escape($statusId) . '" role="status" aria-live="polite">'
            . Html::escape($label) . '</span>';
        $html .= '<table class="ui-table ui-table--skeleton" aria-hidden="true"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th scope="col">' . Html::escape($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($row = 0; $row < $rows; $row++) {
            $html .= '<tr>';
            for ($column = 0; $column < $columnCount; $column++) {
                $html .= '<td><span class="ui-skeleton"></span></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return self::render($html, [
            'label' => $label,
            'busy' => true,
            'focusable' => false,
        ]);
    }
}
