<?php
/**
 * Semantic status badge with one canonical mapping from application status to visual tone.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class StatusBadge
{
    /** @param array{label?:string,class?:string,attributes?:array<string,scalar|null>} $props */
    public static function render(string $status, array $props = []): string
    {
        $normalized = self::normalize($status);
        $label = trim((string)($props['label'] ?? ''));
        if ($label === '') {
            $label = self::humanize($normalized !== '' ? $normalized : 'unknown');
        }
        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        $attributes['data-status'] = $normalized !== '' ? $normalized : 'unknown';
        $tone = self::tone($normalized);
        $class = Html::classes(
            'ui-badge',
            'ui-badge--' . $tone,
            'ui-status-badge',
            (string)($props['class'] ?? '')
        );

        return '<span class="' . Html::escape($class) . '"' . Html::attributes($attributes) . '>'
            . Html::escape($label) . '</span>';
    }

    public static function tone(string $status): string
    {
        return match (self::normalize($status)) {
            'queued', 'running', 'stopped_with_queue' => 'warning',
            'completed', 'imported', 'verified', 'alias', 'bucketed', 'decompressed', 'ready' => 'success',
            'duplicate', 'info' => 'info',
            'failed', 'rejected', 'unverified', 'dead_letter', 'cancelled', 'orphaned', 'not_ready' => 'danger',
            default => 'neutral',
        };
    }

    private static function normalize(string $status): string
    {
        $status = strtolower(trim($status));
        return preg_replace('/[^a-z0-9_-]+/', '_', $status) ?: '';
    }

    private static function humanize(string $status): string
    {
        if ($status === 'dead_letter') {
            return 'Failed';
        }
        return ucfirst(str_replace(['_', '-'], ' ', $status));
    }
}
