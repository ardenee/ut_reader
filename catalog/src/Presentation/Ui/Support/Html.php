<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `Html` for html.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Support;

final class Html
{
    /** @var array<string, true> */
    private const SAFE_ATTRIBUTES = [
        'id' => true,
        'name' => true,
        'value' => true,
        'title' => true,
        'role' => true,
        'tabindex' => true,
        'placeholder' => true,
        'autocomplete' => true,
        'inputmode' => true,
        'min' => true,
        'max' => true,
        'step' => true,
        'rows' => true,
        'cols' => true,
        'form' => true,
    ];

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function classes(string ...$classes): string
    {
        $tokens = [];
        foreach ($classes as $class) {
            foreach (preg_split('/\s+/', trim($class)) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[$token] = true;
                }
            }
        }

        return implode(' ', array_keys($tokens));
    }

    /** @param array<string, scalar|null> $attributes */
    public static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            $name = (string)$name;
            if (!self::attributeAllowed($name) || $value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . self::escape($name);
                continue;
            }

            $html .= ' ' . self::escape($name) . '="' . self::escape($value) . '"';
        }

        return $html;
    }

    public static function uniqueId(string $prefix): string
    {
        static $sequences = [];
        $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '-', trim($prefix)) ?: 'ui';
        $sequences[$safePrefix] = ($sequences[$safePrefix] ?? 0) + 1;

        return strtolower($safePrefix) . '-' . $sequences[$safePrefix];
    }

    private static function attributeAllowed(string $name): bool
    {
        return isset(self::SAFE_ATTRIBUTES[$name])
            || preg_match('/^(aria|data)-[a-zA-Z0-9_-]+$/', $name) === 1;
    }
}
