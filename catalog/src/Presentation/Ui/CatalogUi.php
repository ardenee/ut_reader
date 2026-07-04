<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui;

/**
 * Server-rendered component primitives for the existing PHP catalog.
 *
 * All text arguments are escaped. Arguments named $content or $body are
 * deliberate HTML composition points and must come from trusted server-side
 * component output, not request input.
 */
final class CatalogUi
{
    /**
     * @param array<string, string> $actions label => href
     */
    public static function pageHeader(string $title, string $description = '', array $actions = []): string
    {
        $html = '<section class="ui-page-header" aria-labelledby="page-title">';
        $html .= '<div class="ui-page-header__content"><h1 id="page-title">' . self::escape($title) . '</h1>';
        if ($description !== '') {
            $html .= '<p class="ui-page-header__description">' . self::escape($description) . '</p>';
        }
        $html .= '</div>';

        if ($actions !== []) {
            $html .= '<div class="ui-action-group" aria-label="Page actions">';
            foreach ($actions as $label => $href) {
                $html .= self::button((string)$label, ['href' => (string)$href, 'variant' => 'secondary']);
            }
            $html .= '</div>';
        }

        return $html . '</section>';
    }

    /**
     * @param array{href?:string,type?:string,variant?:string,size?:string,disabled?:bool,class?:string,attributes?:array<string,scalar|null>} $options
     */
    public static function button(string $label, array $options = []): string
    {
        $href = isset($options['href']) ? trim((string)$options['href']) : '';
        $variant = self::enum((string)($options['variant'] ?? 'primary'), ['primary', 'secondary', 'danger', 'quiet'], 'primary');
        $size = self::enum((string)($options['size'] ?? 'md'), ['sm', 'md'], 'md');
        $disabled = (bool)($options['disabled'] ?? false);
        $class = trim('ui-button ui-button--' . $variant . ' ui-button--' . $size . ' ' . (string)($options['class'] ?? ''));
        $attributes = self::attributes($options['attributes'] ?? []);

        if ($href !== '') {
            if ($disabled) {
                return '<span class="' . self::escape($class) . '" aria-disabled="true">' . self::escape($label) . '</span>';
            }
            return '<a class="' . self::escape($class) . '" href="' . self::escape($href) . '"' . $attributes . '>' . self::escape($label) . '</a>';
        }

        $type = self::enum((string)($options['type'] ?? 'button'), ['button', 'submit', 'reset'], 'button');
        return '<button class="' . self::escape($class) . '" type="' . $type . '"'
            . ($disabled ? ' disabled aria-disabled="true"' : '') . $attributes . '>'
            . self::escape($label) . '</button>';
    }

    /**
     * @param array{dismissible?:bool,id?:string} $options
     */
    public static function alert(string $tone, string $message, string $title = '', array $options = []): string
    {
        $tone = self::enum($tone, ['info', 'success', 'warning', 'danger'], 'info');
        $dismissible = (bool)($options['dismissible'] ?? false);
        $role = in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status';
        $id = isset($options['id']) && $options['id'] !== '' ? ' id="' . self::escape((string)$options['id']) . '"' : '';

        $html = '<div' . $id . ' class="ui-alert ui-alert--' . $tone . '" role="' . $role . '" aria-live="polite">';
        $html .= '<div class="ui-alert__icon" aria-hidden="true">' . self::toneSymbol($tone) . '</div><div class="ui-alert__body">';
        if ($title !== '') {
            $html .= '<strong class="ui-alert__title">' . self::escape($title) . '</strong>';
        }
        $html .= '<div class="ui-alert__message">' . self::escape($message) . '</div></div>';
        if ($dismissible) {
            $html .= '<button class="ui-icon-button" type="button" data-ui-dismiss aria-label="Dismiss message">×</button>';
        }
        return $html . '</div>';
    }

    /**
     * @param array{label:string,href:string,variant?:string}|null $action
     */
    public static function emptyState(string $title, string $description, ?array $action = null, string $icon = '○'): string
    {
        $html = '<section class="ui-empty-state" aria-labelledby="empty-state-title">';
        $html .= '<div class="ui-empty-state__icon" aria-hidden="true">' . self::escape($icon) . '</div>';
        $html .= '<h2 id="empty-state-title">' . self::escape($title) . '</h2>';
        $html .= '<p>' . self::escape($description) . '</p>';
        if ($action !== null && ($action['href'] ?? '') !== '') {
            $html .= '<div class="ui-empty-state__action">' . self::button((string)$action['label'], [
                'href' => (string)$action['href'],
                'variant' => (string)($action['variant'] ?? 'primary'),
            ]) . '</div>';
        }
        return $html . '</section>';
    }

    public static function loadingState(string $label = 'Loading content…', bool $compact = false): string
    {
        return '<div class="ui-loading' . ($compact ? ' ui-loading--compact' : '') . '" role="status" aria-live="polite">'
            . '<span class="ui-spinner" aria-hidden="true"></span><span>' . self::escape($label) . '</span></div>';
    }

    public static function badge(string $label, string $tone = 'neutral'): string
    {
        $tone = self::enum($tone, ['neutral', 'info', 'success', 'warning', 'danger'], 'neutral');
        return '<span class="ui-badge ui-badge--' . $tone . '">' . self::escape($label) . '</span>';
    }

    /**
     * @param list<string> $headers
     */
    public static function skeletonTable(array $headers, int $rows = 4, string $label = 'Loading table data'): string
    {
        $rows = max(1, min($rows, 12));
        $columnCount = max(1, count($headers));
        $html = '<div class="ui-table-region" aria-busy="true" aria-label="' . self::escape($label) . '"><table class="ui-table ui-table--skeleton"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th scope="col">' . self::escape($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($row = 0; $row < $rows; $row++) {
            $html .= '<tr>';
            for ($column = 0; $column < $columnCount; $column++) {
                $html .= '<td><span class="ui-skeleton" aria-hidden="true"></span><span class="ui-sr-only">Loading</span></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * @param array{title?:string,description?:string,actions?:array<string,string>,class?:string} $options
     */
    public static function section(string $content, array $options = []): string
    {
        $title = (string)($options['title'] ?? '');
        $description = (string)($options['description'] ?? '');
        $actions = $options['actions'] ?? [];
        $class = trim('ui-section ' . (string)($options['class'] ?? ''));
        $html = '<section class="' . self::escape($class) . '">';

        if ($title !== '' || $description !== '' || $actions !== []) {
            $html .= '<div class="ui-section__header"><div>';
            if ($title !== '') {
                $html .= '<h2>' . self::escape($title) . '</h2>';
            }
            if ($description !== '') {
                $html .= '<p>' . self::escape($description) . '</p>';
            }
            $html .= '</div>';
            if (is_array($actions) && $actions !== []) {
                $html .= '<div class="ui-action-group" aria-label="Section actions">';
                foreach ($actions as $label => $href) {
                    $html .= self::button((string)$label, ['href' => (string)$href, 'variant' => 'secondary', 'size' => 'sm']);
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        return $html . '<div class="ui-section__body">' . $content . '</div></section>';
    }

    /**
     * @param array<string, scalar|null> $attributes
     */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (!preg_match('/^(aria-[a-zA-Z0-9_-]+|data-[a-zA-Z0-9_-]+|id|name|value|title)$/', (string)$name)) {
                continue;
            }
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $html .= ' ' . self::escape((string)$name);
                continue;
            }
            $html .= ' ' . self::escape((string)$name) . '="' . self::escape((string)$value) . '"';
        }
        return $html;
    }

    private static function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function toneSymbol(string $tone): string
    {
        return match ($tone) {
            'success' => '✓',
            'warning' => '!',
            'danger' => '×',
            default => 'i',
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
