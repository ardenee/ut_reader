#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the active local source-scan architecture boundaries.
 * Why: Source scanning is performance-sensitive and parser/import semantics must not drift while compatibility layers are retired.
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

$page = $read('catalog/source-scan.php');
$service = $read('catalog/src/Infrastructure/Source/CatalogSourceScanService.php');
$runner = $read('catalog/src/Infrastructure/Source/CatalogSourceScanRunner.php');
$discovery = $read('catalog/src/Infrastructure/Source/CatalogSourceScanDiscovery.php');
$fingerprints = $read('catalog/src/Infrastructure/Source/CatalogSourceFingerprintSession.php');
$identities = $read('catalog/src/Infrastructure/Persistence/PdoCatalogSourceIdentityQuery.php');
$locations = $read('catalog/src/Infrastructure/Source/CatalogSourceLocationRecorder.php');
$profiledImport = $read('catalog/src/Infrastructure/Source/CatalogSourceProfiledImportService.php');
$legacyFacade = $repoRoot . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'CatalogSourceScanNoContainers.php';

$record(
    'presentation_uses_namespaced_scan_service',
    str_contains($page, 'CatalogSourceScanService')
        && str_contains($page, 'new CatalogSourceScanService(')
        && str_contains($page, '$scanner->run(')
        && !str_contains($page, 'catalog_source_scan_run_without_containers('),
    'source-scan.php must call the namespaced service rather than a procedural compatibility entry point'
);

$record(
    'service_delegates_to_runner',
    str_contains($service, 'CatalogSourceScanRunner')
        && str_contains($service, 'new CatalogSourceScanRunner(')
        && str_contains($service, '$this->runner->run(')
        && !str_contains($service, 'catalog_source_scan_run_without_containers('),
    'CatalogSourceScanService must remain a thin application-facing adapter over the runner'
);

$record(
    'legacy_scan_facade_retired',
    !is_file($legacyFacade),
    'CatalogSourceScanNoContainers.php must not return after production callers moved to CatalogSourceScanService'
);

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
        && !str_contains($runner, 'scanner_scan_uploaded_file(')
        && !str_contains($runner, 'LegacyUnverifiedFileStager'),
    'runner must orchestrate collaborators rather than regain traversal, cache, SQL or profiled-import implementation'
);

$record(
    'runner_work_file_and_parser_contract',
    str_contains($runner, 'CatalogSourceScanWorkFile::prepare(')
        && str_contains($runner, 'CatalogSourceScanWorkFile::cleanup(')
        && str_contains($runner, 'catalog_try_read_package_header(')
        && str_contains($runner, 'catalog_header_guid('),
    'redirect/work-file handling and package header/GUID parsing must remain on the established helper path'
);

$md5Lookup = strpos($runner, 'findVerifiedByMd5(');
$headerRead = strpos($runner, 'catalog_try_read_package_header(');
$guidLookup = strpos($runner, 'findVerifiedByGuid(');
$record(
    'identity_decision_order_preserved',
    $md5Lookup !== false
        && $headerRead !== false
        && $guidLookup !== false
        && $md5Lookup < $headerRead
        && $headerRead < $guidLookup,
    'full MD5 match must precede package-header/GUID matching; profiled import remains fallback behavior'
);

$record(
    'discovery_contract',
    str_contains($discovery, 'FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS')
        && str_contains($discovery, 'RecursiveIteratorIterator::SELF_FIRST')
        && str_contains($discovery, "pathinfo(\$path, PATHINFO_EXTENSION)) === 'pak'")
        && str_contains($discovery, 'CatalogSourceScanPathPolicy::allowedFile(')
        && str_contains($discovery, 'CatalogSourceScanPathPolicy::relativePath(')
        && str_contains($discovery, '(count($files) % 250) === 0'),
    'discovery must preserve PAK exclusion, file policy, symlink traversal and progress cadence'
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
    'fingerprint cache/probe/write errors must remain counters/logs rather than aborting a source scan'
);

$record(
    'identity_query_contract',
    str_contains($identities, 'findVerifiedById(')
        && str_contains($identities, 'findVerifiedByMd5(')
        && str_contains($identities, 'findVerifiedByGuid(')
        && str_contains($identities, 'WHERE id=? AND scan_status="verified" LIMIT 1')
        && str_contains($identities, 'WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1')
        && str_contains($identities, 'WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id'),
    'verified identity lookup must preserve exact ID/MD5/GUID SQL semantics'
);

$record(
    'location_persistence_contract',
    str_contains($locations, 'PdoCatalogSourcePathStore')
        && str_contains($locations, 'INSERT INTO ue_file_locations')
        && str_contains($locations, 'ON DUPLICATE KEY UPDATE')
        && str_contains($locations, 'recordMatched(')
        && str_contains($locations, 'recordImportResult('),
    'matched and imported files must retain source-path/location persistence'
);

$record(
    'profiled_import_contract',
    str_contains($profiledImport, 'scanner_scan_uploaded_file(')
        && str_contains($profiledImport, "tempnam(sys_get_temp_dir(), 'ue_src_scan_')")
        && str_contains($profiledImport, 'LegacyUnverifiedFileStager')
        && str_contains($profiledImport, '$this->locations->recordImportResult(')
        && str_contains($profiledImport, '$this->identities->findVerifiedById(')
        && str_contains($profiledImport, '$this->fingerprints->remember(')
        && str_contains($profiledImport, 'rememberFailureFingerprint'),
    'profiled import must preserve temp copy, scanner import, accounting, fingerprint and failed-staging behavior'
);

$criticalPhp = [
    'catalog/bin/verify-source-scan-boundaries.php',
    'catalog/source-scan.php',
    'catalog/lib/CatalogSourceScan.php',
    'catalog/src/Infrastructure/Persistence/PdoCatalogSourceIdentityQuery.php',
    'catalog/src/Infrastructure/Source/CatalogSourceFingerprintSession.php',
    'catalog/src/Infrastructure/Source/CatalogSourceLocationRecorder.php',
    'catalog/src/Infrastructure/Source/CatalogSourceProfiledImportService.php',
    'catalog/src/Infrastructure/Source/CatalogSourceScanDiscovery.php',
    'catalog/src/Infrastructure/Source/CatalogSourceScanRunner.php',
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
