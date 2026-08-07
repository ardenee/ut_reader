<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `Alert` for alert.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Alert
{
    /** @param array{dismissible?:bool,id?:string} $props */
    public static function render(string $tone, string $message, string $title = '', array $props = []): string
    {
        $tone = Html::enum($tone, ['info', 'success', 'warning', 'danger'], 'info');
        $dismissible = (bool)($props['dismissible'] ?? false);
        $role = in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status';
        $id = trim((string)($props['id'] ?? ''));
        $attributes = ['role' => $role, 'aria-live' => $role === 'alert' ? 'assertive' : 'polite'];
        if ($id !== '') {
            $attributes['id'] = $id;
        }

        $html = '<div class="ui-alert ui-alert--' . $tone . '"' . Html::attributes($attributes) . '>';
        $html .= '<div class="ui-alert__icon" aria-hidden="true">' . self::symbol($tone) . '</div>';
        $html .= '<div class="ui-alert__body">';
        if ($title !== '') {
            $html .= '<strong class="ui-alert__title">' . Html::escape($title) . '</strong>';
        }
        $html .= '<div class="ui-alert__message">' . Html::escape($message) . '</div></div>';
        if ($dismissible) {
            $html .= IconButton::render([
                'label' => 'Dismiss message',
                'icon' => '×',
                'variant' => 'quiet',
                'size' => 'sm',
                'class' => 'ui-alert__dismiss',
                'attributes' => ['data-ui-dismiss' => true],
            ]);
        }

        return $html . '</div>';
    }

    private static function symbol(string $tone): string
    {
        return match ($tone) {
            'success' => '✓',
            'warning' => '!',
            'danger' => '×',
            default => 'i',
        };
    }
}
