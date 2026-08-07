<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `Section` for section.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class Section
{
    /**
     * @param array{title?:string,description?:string,actions?:array<string,string>|list<array{label:string,href:string,variant?:string}>,class?:string,id?:string} $props
     */
    public static function render(string $content, array $props = []): string
    {
        $title = (string)($props['title'] ?? '');
        $description = (string)($props['description'] ?? '');
        $actions = is_array($props['actions'] ?? null) ? $props['actions'] : [];
        $class = Html::classes('ui-section', (string)($props['class'] ?? ''));
        $id = trim((string)($props['id'] ?? ''));
        $headingId = $title !== '' ? Html::uniqueId('ui-section-title') : '';
        $attributes = [];
        if ($id !== '') {
            $attributes['id'] = $id;
        }
        if ($headingId !== '') {
            $attributes['aria-labelledby'] = $headingId;
        }

        $html = '<section class="' . Html::escape($class) . '"' . Html::attributes($attributes) . '>';
        if ($title !== '' || $description !== '' || $actions !== []) {
            $html .= '<div class="ui-section__header"><div>';
            if ($title !== '') {
                $html .= '<h2 id="' . Html::escape($headingId) . '">' . Html::escape($title) . '</h2>';
            }
            if ($description !== '') {
                $html .= '<p>' . Html::escape($description) . '</p>';
            }
            $html .= '</div>';

            $normalized = self::normalizeActions($actions);
            if ($normalized !== []) {
                $html .= '<div class="ui-action-group" aria-label="Section actions">';
                foreach ($normalized as $action) {
                    $html .= Button::render($action['label'], [
                        'href' => $action['href'],
                        'variant' => $action['variant'],
                        'size' => 'sm',
                    ]);
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        return $html . '<div class="ui-section__body">' . $content . '</div></section>';
    }

    /** @return list<array{label:string,href:string,variant:string}> */
    private static function normalizeActions(array $actions): array
    {
        $normalized = [];
        foreach ($actions as $key => $value) {
            if (is_array($value)) {
                $label = trim((string)($value['label'] ?? ''));
                $href = trim((string)($value['href'] ?? ''));
                $variant = Html::enum((string)($value['variant'] ?? 'secondary'), ['primary', 'secondary', 'danger', 'quiet'], 'secondary');
            } else {
                $label = trim((string)$key);
                $href = trim((string)$value);
                $variant = 'secondary';
            }

            if ($label !== '' && $href !== '') {
                $normalized[] = ['label' => $label, 'href' => $href, 'variant' => $variant];
            }
        }

        return $normalized;
    }
}
