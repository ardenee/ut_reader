<?php
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
