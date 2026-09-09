#!/usr/bin/env php
<?php
/** Read-only contract for administrator maintenance page wiring. */
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

$asset = $read('asset-metadata-rebuild.php');
$normalizer = $read('src/Infrastructure/Maintenance/CatalogLegacyPackageNormalizationService.php');
$duplicates = $read('duplicates.php');
$duplicateQuery = $read('src/Infrastructure/Persistence/PdoDuplicateGroupListQuery.php');
$workload = $read('workload-tracing.php');

$checks = [
    'asset metadata loads dependency schema facade' =>
        str_contains($asset, "require_once __DIR__ . '/lib/CatalogDependencySchema.php';")
        && str_contains($asset, 'catalog_dependency_schema_ensure($db);'),
    'package normalizer lists verified files only' =>
        str_contains($normalizer, 'WHERE f.scan_status="verified"')
        && str_contains($normalizer, "'File #' . \$fileId . ' is not verified and cannot be package-normalized.'"),
    'duplicates query exposes package and licensee versions' =>
        str_contains($duplicateQuery, 'f.uploaded_at,f.package_version,f.licensee_version'),
    'duplicates page renders package and licensee versions' =>
        str_contains($duplicates, '<th>Package ver</th><th>Licensee ver</th>')
        && str_contains($duplicates, "\$file['package_version']")
        && str_contains($duplicates, "\$file['licensee_version']"),
    'workload tracing assesses opcache thresholds instead of always warning' =>
        str_contains($workload, '$opcacheMemoryReady')
        && str_contains($workload, '$opcacheFilesReady')
        && str_contains($workload, "'ready' : 'change'")
        && str_contains($workload, 'These are advisory production targets'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'asset-metadata-rebuild.php',
    'src/Infrastructure/Maintenance/CatalogLegacyPackageNormalizationService.php',
    'duplicates.php',
    'src/Infrastructure/Persistence/PdoDuplicateGroupListQuery.php',
    'workload-tracing.php',
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
    $failed[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

echo json_encode([
    'ok' => $failed === [],
    'checks' => count($checks) + 1,
    'failures' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
