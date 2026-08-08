#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the local no-container source-scan architecture boundaries.
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
$shared = $read('catalog/lib/CatalogSourceScan.php');
$discovery = $read('catalog/src/Infrastructure/Source/CatalogSourceScanDiscovery.php');
$fingerprints = $read('catalog/src/Infrastructure/Source/CatalogSourceFingerprintSession.php');
$identities = $read('catalog/src/Infrastructure/Persistence/PdoCatalogSourceIdentityQuery.php');
$locations = $read('catalog/src/Infrastructure/Source/CatalogSourceLocationRecorder.php');
$profiledImport = $read('catalog/src/Infrastructure/Source/CatalogSourceProfiledImportService.php');
$service = $read('catalog/src/Infrastructure/Source/CatalogSourceScanService.php');

$record(
    'runner_delegates_scan_infrastructure',
    str_contains($runner, 'CatalogSourceScanDiscovery')
        && str_contains($runner, 'CatalogSourceFingerprintSession')
        && str_contains($runner, 'PdoCatalogSourceIdentityQuery')
        && str_contains($runner, 'CatalogSourceLocationRecorder')
        && str_contains($runner, 'CatalogSourceProfiledImportService')
        && str_contains($runner, '->discover(')
        && str_contains($runner, '->probeAndLookup(')
        && str_contains($runner, '->findVerifiedByMd5(')
        && str_contains($runner, '->findVerifiedByGuid(')
        && str_contains($runner, '->recordMatched(')
        && str_contains($runner, '->attempt(')
        && str_contains($runner, '->stageFailure(')
        && !str_contains($runner, 'new RecursiveDirectoryIterator(')
        && !str_contains($runner, 'PdoSourceFileFingerprintCache')
        && !str_contains($runner, 'INSERT INTO ue_file_locations')
        && !str_contains($runner, 'scan_status="verified"')
        && !str_contains($runner, 'scanner_scan_uploaded_file(')
        && !str_contains($runner, 'LegacyUnverifiedFileStager'),
    'source runner must not regain traversal, fingerprint cache, verified-file SQL, location persistence or profiled import/staging implementation'
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

$record(
    'identity_query_contract',
    str_contains($identities, 'findVerifiedById(')
        && str_contains($identities, 'findVerifiedByMd5(')
        && str_contains($identities, 'findVerifiedByGuid(')
        && str_contains($identities, 'WHERE id=? AND scan_status="verified" LIMIT 1')
        && str_contains($identities, 'WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1')
        && str_contains($identities, 'WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id'),
    'verified identity lookup must preserve the existing exact ID/MD5/GUID SQL semantics'
);

$record(
    'location_persistence_contract',
    str_contains($locations, 'PdoCatalogSourcePathStore')
        && str_contains($locations, 'INSERT INTO ue_file_locations')
        && str_contains($locations, 'ON DUPLICATE KEY UPDATE')
        && str_contains($locations, 'recordMatched(')
        && str_contains($locations, 'recordImportResult(')
        && str_contains($locations, "if ((\$result[0] ?? '') === 'duplicate')")
        && str_contains($locations, "return ['imported' => 0, 'duplicates' => 1, 'locations' => \$locations];")
        && str_contains($locations, "return ['imported' => 1, 'duplicates' => 0, 'locations' => 1];"),
    'matched files and import results must retain ue_file_locations/source-path persistence and accounting semantics'
);

$record(
    'profiled_import_contract',
    str_contains($profiledImport, 'scanner_scan_uploaded_file(')
        && str_contains($profiledImport, "tempnam(sys_get_temp_dir(), 'ue_src_scan_')")
        && str_contains($profiledImport, 'LegacyUnverifiedFileStager')
        && str_contains($profiledImport, "'Local Source Scan import failed for '")
        && str_contains($profiledImport, "'source_relative_path' => \\catalog_source_scan_normalized_relative_path")
        && str_contains($profiledImport, '$this->locations->recordImportResult(')
        && str_contains($profiledImport, '$this->identities->findVerifiedById(')
        && str_contains($profiledImport, '$this->fingerprints->remember(')
        && str_contains($profiledImport, 'rememberFailureFingerprint'),
    'profiled import service must preserve temp-copy, scanner import, accounting, fingerprint and failed-staging behavior'
);

$md5Lookup = strpos($runner, 'findVerifiedByMd5(');
$headerRead = strpos($runner, 'catalog_try_read_package_header(');
$guidLookup = strpos($runner, 'findVerifiedByGuid(');
$record(
    'identity_decision_order_preserved',
    $md5Lookup !== false
        && $headerRead !== false
        && $guidLookup !== false
        && str_contains($runner, '->attempt(')
        && $md5Lookup < $headerRead
        && $headerRead < $guidLookup,
    'full MD5 match must precede package-header/GUID matching; profiled imports remain fallback behavior'
);

$record(
    'retired_stateful_helpers_absent',
    !str_contains($runner, 'catalog_source_scan_record_location(')
        && !str_contains($runner, 'catalog_source_scan_record_import_result(')
        && !str_contains($runner, 'catalog_source_scan_catalog_identity(')
        && !str_contains($runner, 'catalog_source_scan_import_work_file(')
        && !str_contains($runner, 'catalog_source_scan_stage_failed(')
        && !str_contains($shared, 'function catalog_source_scan_record_location(')
        && !str_contains($shared, 'function catalog_source_scan_record_import_result(')
        && !str_contains($shared, 'function catalog_source_scan_temp_copy(')
        && !str_contains($shared, 'function catalog_source_scan_import_work_file(')
        && !str_contains($shared, 'function catalog_source_scan_stage_failed('),
    'retired procedural identity/location/import/staging helpers must not return after namespaced extraction'
);

$record(
    'parser_and_redirect_helpers_unchanged',
    str_contains($runner, 'catalog_source_scan_work_file(')
        && str_contains($runner, 'catalog_try_read_package_header(')
        && str_contains($runner, 'catalog_header_guid(')
        && str_contains($runner, 'catalog_source_scan_cleanup_work_file(')
        && str_contains($shared, 'catalog_redirect_archive_decompress_to_temp(')
        && str_contains($shared, 'catalog_source_scan_normalized_relative_path('),
    'this extraction must not replace redirect decoding, package-header parsing or work-file cleanup semantics'
);

$record(
    'source_scan_service_keeps_compatibility_entry',
    str_contains($service, 'catalog_source_scan_run_without_containers('),
    'namespaced public source-scan service must retain the established entry point in this staged refactor'
);

$criticalPhp = [
    'catalog/bin/verify-source-scan-boundaries.php',
    'catalog/lib/CatalogSourceScan.php',
    'catalog/lib/CatalogSourceScanNoContainers.php',
    'catalog/src/Infrastructure/Persistence/PdoCatalogSourceIdentityQuery.php',
    'catalog/src/Infrastructure/Source/CatalogSourceFingerprintSession.php',
    'catalog/src/Infrastructure/Source/CatalogSourceLocationRecorder.php',
    'catalog/src/Infrastructure/Source/CatalogSourceProfiledImportService.php',
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
