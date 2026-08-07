<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `LoadingState` for loading state.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class LoadingState
{
    public static function render(string $label = 'Loading content…', bool $compact = false): string
    {
        return '<div class="ui-loading' . ($compact ? ' ui-loading--compact' : '') . '" role="status" aria-live="polite">'
            . '<span class="ui-spinner" aria-hidden="true"></span><span>' . Html::escape($label) . '</span></div>';
    }
}
