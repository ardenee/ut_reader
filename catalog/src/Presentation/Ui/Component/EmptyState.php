<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `EmptyState` for empty state.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class EmptyState
{
    /** @param array{label:string,href:string,variant?:string}|null $action */
    public static function render(string $title, string $description, ?array $action = null, string $icon = '○'): string
    {
        $headingId = Html::uniqueId('ui-empty-state-title');
        $html = '<section class="ui-empty-state" aria-labelledby="' . Html::escape($headingId) . '">';
        $html .= '<div class="ui-empty-state__icon" aria-hidden="true">' . Html::escape($icon) . '</div>';
        $html .= '<h2 id="' . Html::escape($headingId) . '">' . Html::escape($title) . '</h2>';
        $html .= '<p>' . Html::escape($description) . '</p>';
        if ($action !== null && trim((string)($action['href'] ?? '')) !== '') {
            $html .= '<div class="ui-empty-state__action">' . Button::render((string)($action['label'] ?? ''), [
                'href' => (string)$action['href'],
                'variant' => (string)($action['variant'] ?? 'primary'),
            ]) . '</div>';
        }

        return $html . '</section>';
    }
}
