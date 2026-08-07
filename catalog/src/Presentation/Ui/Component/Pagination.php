<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `Pagination` for pagination.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Pagination
{
    /**
     * @param array{first?:string,previous?:string,next?:string,last?:string,label?:string,class?:string} $links
     */
    public static function render(int $currentPage, int $totalPages, array $links = []): string
    {
        $currentPage = max(1, $currentPage);
        $totalPages = max(1, $totalPages);
        $label = trim((string)($links['label'] ?? 'Pagination'));
        $class = Html::classes('ui-pagination', (string)($links['class'] ?? ''));

        $start = '';
        if ($currentPage > 1) {
            if (trim((string)($links['first'] ?? '')) !== '') {
                $start .= Button::render('First', ['href' => (string)$links['first'], 'variant' => 'secondary', 'size' => 'sm']);
            }
            if (trim((string)($links['previous'] ?? '')) !== '') {
                $start .= Button::render('Previous', ['href' => (string)$links['previous'], 'variant' => 'secondary', 'size' => 'sm']);
            }
        }

        $end = '';
        if ($currentPage < $totalPages) {
            if (trim((string)($links['next'] ?? '')) !== '') {
                $end .= Button::render('Next', ['href' => (string)$links['next'], 'variant' => 'secondary', 'size' => 'sm']);
            }
            if (trim((string)($links['last'] ?? '')) !== '') {
                $end .= Button::render('Last', ['href' => (string)$links['last'], 'variant' => 'secondary', 'size' => 'sm']);
            }
        }

        return '<nav class="' . Html::escape($class) . '" aria-label="' . Html::escape($label) . '">'
            . '<div class="ui-pagination__start">' . $start . '</div>'
            . '<span class="ui-pagination__current" aria-current="page">Page ' . $currentPage . ' of ' . $totalPages . '</span>'
            . '<div class="ui-pagination__end">' . $end . '</div>'
            . '</nav>';
    }
}
