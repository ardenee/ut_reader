#!/usr/bin/env php
<?php
/** Read-only contract for city/region GeoIP map enrichment. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$migration = $read('migrations/202609080003_geoip_city_location.php');
$resolver = $read('src/Infrastructure/Downloads/CatalogGeoIpLocationResolver.php');
$compat = $read('src/Infrastructure/Downloads/CatalogGeoIpCountryResolver.php');
$importer = $read('bin/import-geoip-city-maxmind.php');
$core = $read('lib/CatalogSupportCore.php');
$ui = $read('assets/catalog-ui.js');
$access = $read('access-matrix.php');
$blacklist = $read('site-blacklist.php');
$downloads = $read('download-logs.php');

$checks = [
    'migration adds detailed GeoIP fields' =>
        str_contains($migration, 'subdivision_name')
        && str_contains($migration, 'city_name')
        && str_contains($migration, 'latitude')
        && str_contains($migration, 'longitude')
        && str_contains($migration, 'accuracy_radius_km'),
    'location resolver remains local-only' =>
        str_contains($resolver, 'FROM ue_geoip_country_ranges')
        && !str_contains($resolver, 'curl_')
        && !str_contains($resolver, 'file_get_contents(')
        && !preg_match('/https?:\/\//i', $resolver),
    'resolver falls back when detail columns are unavailable' =>
        str_contains($resolver, 'detailedColumnsAvailable()')
        && str_contains($resolver, 'SELECT range_end,country_code,country_name '),
    'country resolver remains backward compatible' =>
        str_contains($compat, 'CatalogGeoIpLocationResolver')
        && str_contains($compat, 'return $this->resolver->resolve($ip);'),
    'MaxMind importer accepts official city bundle files' =>
        str_contains($importer, 'City-Locations-en')
        && str_contains($importer, 'City-Blocks-IPv4')
        && str_contains($importer, 'City-Blocks-IPv6'),
    'MaxMind importer converts CIDR to binary ranges' =>
        str_contains($importer, 'function geoip_city_cidr_bounds(')
        && str_contains($importer, "pack('C*', ...$start)")
        && str_contains($importer, "pack('C*', ...$end)"),
    'MaxMind importer stages before atomic swap' =>
        str_contains($importer, 'ue_geoip_country_ranges_import')
        && str_contains($importer, 'RENAME TABLE ')
        && str_contains($importer, 'ue_geoip_country_ranges_previous'),
    'shared map attributes expose coordinate detail' =>
        str_contains($core, 'function catalog_world_map_attributes(')
        && str_contains($core, 'data-world-map-latitude')
        && str_contains($core, 'data-world-map-longitude')
        && str_contains($core, 'data-world-map-region')
        && str_contains($core, 'data-world-map-city')
        && str_contains($core, 'data-world-map-accuracy-km'),
    'shared map reads city coordinate detail' =>
        str_contains($ui, "row.getAttribute('data-world-map-latitude')")
        && str_contains($ui, "row.getAttribute('data-world-map-longitude')")
        && str_contains($ui, "row.getAttribute('data-world-map-region')")
        && str_contains($ui, "row.getAttribute('data-world-map-city')"),
    'shared map uses VectorAtlas equirectangular projection' =>
        str_contains($ui, 'function projectCoordinate(')
        && str_contains($ui, '578.370221')
        && str_contains($ui, 'viewBox.width / 360'),
    'same-country IPs are not artificially spread in a circle' =>
        !str_contains($ui, 'Math.cos(angle) * spread')
        && !str_contains($ui, 'Math.sin(angle) * spread')
        && str_contains($ui, "point.latitude.toFixed(4)"),
    'country fallback uses largest map component' =>
        str_contains($ui, 'function largestCountryComponentCenter(')
        && str_contains($ui, "d.match(/M[^M]+/g)"),
    'map tooltips include location and accuracy detail' =>
        str_contains($ui, 'function locationLabel(')
        && str_contains($ui, 'approximate accuracy ±')
        && str_contains($ui, 'country fallback'),
    'Access Matrix uses detailed location resolver' =>
        str_contains($access, 'CatalogGeoIpLocationResolver')
        && str_contains($access, 'catalog_world_map_attributes('),
    'Site Blacklist uses detailed location resolver' =>
        str_contains($blacklist, 'CatalogGeoIpLocationResolver')
        && str_contains($blacklist, 'catalog_world_map_attributes('),
    'Download Logs uses detailed location resolver and generic map source' =>
        str_contains($downloads, 'CatalogGeoIpLocationResolver')
        && str_contains($downloads, 'catalog_world_map_attributes(')
        && str_contains($downloads, 'data-world-map-source="download-logs"')
        && str_contains($downloads, 'data-world-map-source="download-logs-generations"'),
];

$failures = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failures[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'migrations/202609080003_geoip_city_location.php',
    'src/Infrastructure/Downloads/CatalogGeoIpLocationResolver.php',
    'src/Infrastructure/Downloads/CatalogGeoIpCountryResolver.php',
    'bin/import-geoip-city-maxmind.php',
    'lib/CatalogSupportCore.php',
    'access-matrix.php',
    'site-blacklist.php',
    'download-logs.php',
    __FILE__,
] as $relative) {
    $file = $relative === __FILE__
        ? __FILE__
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
if ($syntaxFailures !== []) {
    $failures[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

$result = [
    'ok' => $failures === [],
    'checks' => count($checks) + 1,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
