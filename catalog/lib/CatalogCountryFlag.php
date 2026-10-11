<?php
declare(strict_types=1);

/**
 * Real flags from the pinned flag-icons v7.5.0 SVG set (see bundled LICENSE).
 * Data URIs avoid TLS-sensitive per-flag resource requests from LAN-IP pages.
 */
function catalog_country_flag_html(string $code, string $class): string
{
    $code = strtolower(trim($code));
    if (preg_match('/^[a-z]{2}$/D', $code) !== 1) {
        return '';
    }
    static $svgCache = [];
    if (!array_key_exists($code, $svgCache)) {
        $file = dirname(__DIR__) . '/assets/country-flags/4x3/' . $code . '.svg';
        $svg = is_file($file) ? @file_get_contents($file) : false;
        $svgCache[$code] = is_string($svg) && strlen($svg) <= 250000
            && str_contains($svg, '<svg')
            ? 'data:image/svg+xml;base64,' . base64_encode($svg)
            : null;
    }
    $label = strtoupper($code);
    $safeClass = htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (is_string($svgCache[$code])) {
        return '<img class="' . $safeClass . '" src="' . $svgCache[$code]
            . '" alt="' . $label . '" title="' . $label
            . '" data-country-code="' . $label . '" loading="lazy" width="20" height="15">';
    }
    $emoji = mb_chr(0x1F1E6 + ord($label[0]) - 65, 'UTF-8')
        . mb_chr(0x1F1E6 + ord($label[1]) - 65, 'UTF-8');
    return '<span class="' . $safeClass . '" role="img" title="' . $label
        . '" data-country-code="' . $label . '">' . $emoji . '</span>';
}