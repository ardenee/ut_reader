<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class PageHeader
{
    /**
     * @param array<string,string>|list<array{label:string,href:string,variant?:string}> $actions
     */
    public static function render(string $title, string $description = '', array $actions = []): string
    {
        $headingId = Html::uniqueId('ui-page-title');
        $html = '<section class="ui-page-header" aria-labelledby="' . Html::escape($headingId) . '">';
        $html .= '<div class="ui-page-header__content"><h1 id="' . Html::escape($headingId) . '">' . Html::escape($title) . '</h1>';
        if ($description !== '') {
            $html .= '<p class="ui-page-header__description">' . Html::escape($description) . '</p>';
        }
        $html .= '</div>';

        $normalized = self::normalizeActions($actions);
        if ($normalized !== []) {
            $html .= '<div class="ui-action-group" aria-label="Page actions">';
            foreach ($normalized as $action) {
                $html .= Button::render($action['label'], [
                    'href' => $action['href'],
                    'variant' => $action['variant'],
                ]);
            }
            $html .= '</div>';
        }

        return $html . '</section>';
    }

    /**
     * @param array<string,string>|list<array{label:string,href:string,variant?:string}> $actions
     * @return list<array{label:string,href:string,variant:string}>
     */
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
