<?php
/** Static contract for persisted local GeoIP enrichment of download audits. */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'migration creates local range table' => [
        $root . '/migrations/202608260001_download_geoip_country.php',
        'ue_geoip_country_ranges',
    ],
    'migration adds country code snapshots' => [
        $root . '/migrations/202608260001_download_geoip_country.php',
        'country_code',
    ],
    'migration adds sortable country indexes' => [
        $root . '/migrations/202608260001_download_geoip_country.php',
        'idx_ue_download_audit_country',
    ],
    'resolver uses local range table' => [
        $root . '/src/Infrastructure/Downloads/CatalogGeoIpCountryResolver.php',
        'FROM ue_geoip_country_ranges',
    ],
    'audit persists country snapshots' => [
        $root . '/src/Infrastructure/Downloads/CatalogDownloadAuditService.php',
        'country_code,country_name',
    ],
    'audit resolves country at write time' => [
        $root . '/src/Infrastructure/Downloads/CatalogDownloadAuditService.php',
        '$this->geoIp->resolve($ipText)',
    ],
    'log renders country flag' => [
        $root . '/download-logs.php',
        'download-country-flag',
    ],
    'log country column is server-sortable' => [
        $root . '/download-logs.php',
        "a.country_name ' . strtoupper(\$direction)",
    ],
    'CSV importer loads local ranges transactionally' => [
        $root . '/bin/import-geoip-country-csv.php',
        '$db->beginTransaction()',
    ],
    'CSV importer supplies PHP 8.4+ escape argument' => [
        $root . '/bin/import-geoip-country-csv.php',
        "fgetcsv(\$handle, null, ',', '\"', '')",
    ],
    'CSV importer has built-in country-name fallback' => [
        $root . '/bin/import-geoip-country-csv.php',
        'geoip_iso_country_names()',
    ],
    'CSV importer ignores DB-IP unknown ranges' => [
        $root . '/bin/import-geoip-country-csv.php',
        "if (\$code === 'ZZ')",
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
    || preg_match('/https?:\/\//i', $resolver) === 1
    || str_contains($resolver, 'curl_')
    || str_contains($resolver, 'file_get_contents(')) {
    $failed[] = 'GeoIP resolution must remain local-only and fail open';
}

$importerPath = $root . '/bin/import-geoip-country-csv.php';
$importer = is_file($importerPath) ? file_get_contents($importerPath) : false;
if (!is_string($importer)
    || str_contains($importer, 'Three-column files require ext-intl')
    || str_contains($importer, 'enable PHP ext-intl')) {
    $failed[] = 'DB-IP three-column import must not require ext-intl';
}

if ($failed !== []) {
    fwrite(STDERR, "Download GeoIP contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Download GeoIP contract passed (" . (count($checks) + 2) . " checks).\n";
