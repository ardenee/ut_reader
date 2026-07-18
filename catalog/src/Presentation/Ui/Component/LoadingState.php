<?php
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
