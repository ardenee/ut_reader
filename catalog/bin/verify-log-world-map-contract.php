<?php
/**
 * Static contract for reusing the world activity map on IP-based administrator logs.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'shared UI discovers generic map sources' => [
        $root . '/assets/catalog-ui.js',
        "document.querySelector('[data-world-map-source]')",
    ],
    'shared UI reads server-supplied IP points' => [
        $root . '/assets/catalog-ui.js',
        "row.getAttribute('data-world-map-ip')",
    ],
    'shared UI reads country codes' => [
        $root . '/assets/catalog-ui.js',
        "row.getAttribute('data-world-map-country-code')",
    ],
    'shared UI keeps same-origin world map endpoint' => [
        $root . '/assets/catalog-ui.js',
        "fetch(root + 'world-map.php'",
    ],
    'shared UI keeps existing download-map compatibility' => [
        $root . '/assets/catalog-ui.js',
        "table.download-log-table",
    ],
    'access matrix uses local GeoIP resolver' => [
        $root . '/access-matrix.php',
        'CatalogGeoIpCountryResolver',
    ],
    'access matrix raw events expose map source' => [
        $root . '/access-matrix.php',
        'data-world-map-source="access-matrix-raw-events"',
    ],
    'access matrix raw rows expose IP map points' => [
        $root . '/access-matrix.php',
        'data-world-map-ip="',
    ],
    'site blacklist uses local GeoIP resolver' => [
        $root . '/site-blacklist.php',
        'CatalogGeoIpCountryResolver',
    ],
    'site blacklist exposes map source' => [
        $root . '/site-blacklist.php',
        'data-world-map-source="site-blacklist"',
    ],
    'site blacklist rows expose country map points' => [
        $root . '/site-blacklist.php',
        'data-world-map-country-name="',
    ],
];

$failed = [];
foreach ($checks as $label => [$path, $needle]) {
    $content = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($content) || !str_contains($content, $needle)) {
        $failed[] = $label;
    }
}

$resolverPath = $root . '/src/Infrastructure/Downloads/CatalogGeoIpCountryResolver.php';
$resolver = is_file($resolverPath) ? file_get_contents($resolverPath) : false;
if (!is_string($resolver)
    || str_contains($resolver, 'curl_')
    || str_contains($resolver, 'file_get_contents(')
    || !str_contains($resolver, 'ue_geoip_country_ranges')) {
    $failed[] = 'administrator map enrichment must remain local-only';
}

if ($failed !== []) {
    fwrite(STDERR, "Log world map contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo 'Log world map contract passed (' . (count($checks) + 1) . " checks).\n";
