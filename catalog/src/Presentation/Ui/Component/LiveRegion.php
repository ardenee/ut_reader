<?php
/**
 * Accessible status message container for asynchronous UI updates.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class LiveRegion
{
    /**
     * @param array{id?:string,tone?:string,priority?:string,atomic?:bool,class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(string $message, array $props = []): string
    {
        $tone = Html::enum((string)($props['tone'] ?? 'neutral'), ['neutral', 'info', 'success', 'warning', 'danger'], 'neutral');
        $priority = Html::enum((string)($props['priority'] ?? 'polite'), ['polite', 'assertive'], 'polite');
        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        $attributes['role'] = $priority === 'assertive' ? 'alert' : 'status';
        $attributes['aria-live'] = $priority;
        $attributes['aria-atomic'] = ($props['atomic'] ?? true) ? 'true' : 'false';
        $id = trim((string)($props['id'] ?? ''));
        if ($id !== '') {
            $attributes['id'] = $id;
        }

        $class = Html::classes('ui-live-region', 'ui-live-region--' . $tone, (string)($props['class'] ?? ''));
        return '<div class="' . Html::escape($class) . '"' . Html::attributes($attributes) . '>'
            . Html::escape($message) . '</div>';
    }
}
