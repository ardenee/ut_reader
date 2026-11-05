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
     * Shared compact controls used across public catalog pages.
     *
     * page_url, when provided, enables direct first/middle/last page links.
     * jump is a GET form with a validated numeric page and filter-preserving
     * hidden fields; cursor-only clients may omit both optional entries.
     *
     * @param array<string,mixed> $links
     */
    public static function render(int $currentPage, int $totalPages, array $links = []): string
    {
        $totalPages = max(1, $totalPages);
        $currentPage = max(1, min($totalPages, $currentPage));
        $label = trim((string)($links['label'] ?? 'Pagination'));
        $class = Html::classes('ui-pagination', (string)($links['class'] ?? ''));
        $start = '';
        if ($currentPage > 1) {
            foreach (['first' => 'First', 'previous' => 'Previous'] as $name => $title) {
                if (trim((string)($links[$name] ?? '')) !== '') {
                    $start .= Button::render($title, [
                        'href' => (string)$links[$name],
                        'variant' => 'secondary', 'size' => 'sm',
                    ]);
                }
            }
        }

        $end = '';
        if ($currentPage < $totalPages) {
            foreach (['next' => 'Next', 'last' => 'Last'] as $name => $title) {
                if (trim((string)($links[$name] ?? '')) !== '') {
                    $end .= Button::render($title, [
                        'href' => (string)$links[$name],
                        'variant' => 'secondary', 'size' => 'sm',
                    ]);
                }
            }
        }

        $numbers = '';
        $pageUrl = $links['page_url'] ?? null;
        if (is_callable($pageUrl) && $totalPages > 1) {
            $pages = $totalPages <= 9
                ? range(1, $totalPages)
                : [1, 2, $currentPage - 2, $currentPage - 1, $currentPage,
                    $currentPage + 1, $currentPage + 2, $totalPages - 1, $totalPages];
            $pages = array_values(array_unique(array_filter(
                $pages,
                static fn(int $page): bool => $page >= 1 && $page <= $totalPages
            )));
            sort($pages, SORT_NUMERIC);
            $previous = 0;
            foreach ($pages as $page) {
                if ($previous > 0 && $page > $previous + 1) {
                    $numbers .= '<span class="ui-pagination__ellipsis" aria-hidden="true">…</span>';
                }
                if ($page === $currentPage) {
                    $numbers .= '<span class="ui-pagination__page ui-pagination__page--active" aria-current="page">'
                        . $page . '</span>';
                } else {
                    $url = (string)$pageUrl($page);
                    if ($url !== '') {
                        $numbers .= '<a class="ui-pagination__page" href="' . Html::escape($url)
                            . '" aria-label="Go to page ' . $page . '">' . $page . '</a>';
                    }
                }
                $previous = $page;
            }
        }

        $jump = '';
        $form = $links['jump'] ?? null;
        if ($totalPages > 1 && is_array($form) && trim((string)($form['action'] ?? '')) !== '') {
            $name = (string)($form['name'] ?? 'page');
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/D', $name) !== 1) {
                $name = 'page';
            }
            $hidden = '';
            foreach (($form['fields'] ?? []) as $key => $value) {
                if (!is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/D', $key) !== 1
                    || $key === $name || !is_scalar($value) || $value === '') {
                    continue;
                }
                $hidden .= '<input type="hidden" name="' . Html::escape($key)
                    . '" value="' . Html::escape((string)$value) . '">';
            }
            $jump = '<form class="ui-pagination__jump" method="get" action="'
                . Html::escape((string)$form['action']) . '">'
                . $hidden
                . '<label>Go to page <input type="number" name="' . Html::escape($name)
                . '" min="1" max="' . $totalPages . '" value="' . $currentPage
                . '" step="1" required aria-label="Page number"></label>'
                . '<button class="ui-pagination__go" type="submit">Go</button></form>';
        }

        return '<nav class="' . Html::escape($class) . '" aria-label="' . Html::escape($label) . '">'
            . '<div class="ui-pagination__controls">'
            . '<div class="ui-pagination__start">' . $start . '</div>'
            . ($numbers !== '' ? '<div class="ui-pagination__pages">' . $numbers . '</div>' : '')
            . '<div class="ui-pagination__end">' . $end . '</div>'
            . '</div>'
            . '<div class="ui-pagination__meta"><span class="ui-pagination__current">'
            . 'Page ' . number_format($currentPage) . ' of ' . number_format($totalPages)
            . '</span>' . $jump . '</div>'
            . '</nav>';
    }
}
