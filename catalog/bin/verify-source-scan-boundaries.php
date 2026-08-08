#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the local no-container source-scan discovery/fingerprint boundaries.
 * Why: Source scanning is performance-sensitive and parser/import semantics must not drift while the legacy orchestrator is decomposed.
 * Role: Read-only CLI architecture/regression verifier; it performs no database or source-file mutation.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($catalogRoot);
$checks = [];
$failures = [];

$read = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$runner = $read('catalog/lib/CatalogSourceScanNoContainers.php');
$discovery = $read('catalog/src/Infrastructure/Source/CatalogSourceScanDiscovery.php');
$fingerprints = $read('catalog/src/Infrastructure/Source/CatalogSourceFingerprintSession.php');
$service = $read('catalog/src/Infrastructure/Source/CatalogSourceScanService.php');

$record(
    'runner_delegates_discovery_and_fingerprints',
    str_contains($runner, 'CatalogSourceScanDiscovery')
        && str_contains($runner, 'CatalogSourceFingerprintSession')
        && str_contains($runner, '->discover(')
        && str_contains($runner, '->probeAndLookup(')
        && str_contains($runner, '->remember(')
        && str_contains($runner, '->applyCounters(')
        && !str_contains($runner, 'new RecursiveDirectoryIterator(')
        && !str_contains($runner, 'PdoSourceFileFingerprintCache')
        && !str_contains($runner, 'function catalog_source_scan_cached_work(')
        && !str_contains($runner, 'function catalog_source_scan_remember_fingerprint('),
    'source runner must not regain traversal or fingerprint cache policy'
);

$record(
    'discovery_contract',
    str_contains($discovery, 'FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS')
        && str_contains($discovery, 'RecursiveIteratorIterator::SELF_FIRST')
        && str_contains($discovery, "pathinfo(\$path, PATHINFO_EXTENSION)) === 'pak'")
        && str_contains($discovery, 'catalog_source_scan_allowed_file(')
        && str_contains($discovery, 'catalog_source_scan_relative_path(')
        && str_contains($discovery, '(count($files) % 250) === 0')
        && str_contains($discovery, "'Discovered ' . count(\$files) . ' package-like files.'"),
    'discovery must preserve PAK exclusion, existing file policy, symlink traversal and progress cadence'
);

$record(
    'fingerprint_failures_remain_nonfatal',
    str_contains($fingerprints, 'PdoSourceFileFingerprintCache')
        && str_contains($fingerprints, "'[UnrealDB source fingerprint availability] '")
        && str_contains($fingerprints, "'[UnrealDB source fingerprint probe] '")
        && str_contains($fingerprints, "'[UnrealDB source fingerprint] '")
        && str_contains($fingerprints, "return ['probe' => null, 'cached' => null];")
        && str_contains($fingerprints, '$this->errors++')
        && str_contains($fingerprints, '$this->writes++'),
    'fingerprint cache/probe/write errors must remain counters/logs rather than aborting the source scan'
);

$record(
    'cached_work_contract',
    str_contains($fingerprints, "'temp' => false")
        && str_contains($fingerprints, "'redirect' => \$redirect")
        && str_contains($fingerprints, "'source_extension' => \$redirect")
        && str_contains($fingerprints, 'catalog_clean_unreal_filename(basename($path))'),
    'fingerprint cache hits must preserve the existing cached work-file descriptor'
);

$md5Lookup = strpos($runner, 'AND md5=? LIMIT 1');
$headerRead = strpos($runner, 'catalog_try_read_package_header(');
$guidLookup = strpos($runner, 'AND package_guid=? ORDER BY id');
$importCall = strpos($runner, 'catalog_source_scan_import_work_file(');
$record(
    'identity_decision_order_preserved',
    $md5Lookup !== false
        && $headerRead !== false
        && $guidLookup !== false
        && $importCall !== false
        && $md5Lookup < $headerRead
        && $headerRead < $guidLookup,
    'full MD5 match must precede package-header/GUID matching; imports remain fallback behavior'
);

$record(
    'parser_and_import_helpers_unchanged',
    str_contains($runner, 'catalog_source_scan_work_file(')
        && str_contains($runner, 'catalog_try_read_package_header(')
        && str_contains($runner, 'catalog_header_guid(')
        && str_contains($runner, 'catalog_source_scan_import_work_file(')
        && str_contains($runner, 'catalog_source_scan_stage_failed(')
        && str_contains($runner, 'catalog_source_scan_cleanup_work_file('),
    'this extraction must not replace redirect decoding, package parsing, import or failed-staging semantics'
);

$record(
    'source_scan_service_keeps_compatibility_entry',
    str_contains($service, 'catalog_source_scan_run_without_containers('),
    'namespaced public source-scan service must retain the established entry point in this staged refactor'
);

$criticalPhp = [
    'catalog/bin/verify-source-scan-boundaries.php',
    'catalog/lib/CatalogSourceScanNoContainers.php',
    'catalog/src/Infrastructure/Source/CatalogSourceFingerprintSession.php',
    'catalog/src/Infrastructure/Source/CatalogSourceScanDiscovery.php',
    'catalog/src/Infrastructure/Source/CatalogSourceScanService.php',
];
if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually on the guarded files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
