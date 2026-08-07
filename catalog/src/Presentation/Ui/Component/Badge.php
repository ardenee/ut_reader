<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `Badge` for badge.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Badge
{
    public static function render(string $label, string $tone = 'neutral'): string
    {
        $tone = Html::enum($tone, ['neutral', 'info', 'success', 'warning', 'danger'], 'neutral');

        return '<span class="ui-badge ui-badge--' . $tone . '">' . Html::escape($label) . '</span>';
    }
}
