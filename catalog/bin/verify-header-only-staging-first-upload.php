#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies package reader selection is serialized-header-only and Profiled Upload stages the whole browser batch before worker processing starts.
 * Role: Read-only/no-database regression gate for upload correctness and ingress performance.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'lib/GameProfiles.php',
    'lib/CompatibilityRules.php',
    'src/Application/Catalog/CatalogPackageHeaderInspector.php',
    'profiled-upload.php',
    'api/v1/profiled-upload-chunk.php',
    'api/v1/profiled-upload-preflight.php',
    'src/Infrastructure/Games/CatalogGameProfileAdminService.php',
    'src/Infrastructure/Import/CatalogIncomingFileStore.php',
    'src/Infrastructure/Import/CatalogProfiledUploadDuplicatePreflight.php',
    'src/Infrastructure/Import/CatalogProfiledUploadQueue.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Metadata/CatalogAssetMetadataService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedStagingIndex.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedQueueStorage.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

try {
    require_once $root . '/lib/GameProfiles.php';
    require_once $root . '/src/Application/Catalog/CatalogPackageHeaderInspector.php';
    $record(
        'header_version_selects_supported_legacy_engine',
        gp_engine_from_version(69) === 'UE1'
            && gp_engine_from_version(128) === 'UE2'
            && gp_engine_from_version(512) === 'UE3'
            && gp_engine_from_version(8261) === null,
        'Legacy engine-family hints must come from serialized package versions only.'
    );

    $temporary = tempnam(sys_get_temp_dir(), 'unrealdb-legacy-engine-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Could not create the legacy engine-routing fixture.');
    }
    try {
        $fixture = pack('Vvv', 0x9E2A83C1, 119, 37159) . str_repeat("\0", 56);
        if (file_put_contents($temporary, $fixture) !== strlen($fixture)) {
            throw new RuntimeException('Could not write the legacy engine-routing fixture.');
        }
        $summary = gp_read_legacy_summary($temporary);
        $record(
            'high_bit_legacy_licensee_does_not_select_ue4',
            ($summary['ok'] ?? false) === true
                && ($summary['format'] ?? '') === 'legacy_package'
                && ($summary['version'] ?? null) === 119
                && ($summary['licensee'] ?? null) === 37159
                && ($summary['engine_hint'] ?? '') === 'UE2',
            'UE2 version 119 plus licensee 37159 shares bytes with signed -1859714953 but must still select the UE2 reader.'
        );
        $inspection = \UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector::inspect(
            $temporary,
            ['extension' => 'ut2']
        );
        $record(
            'header_inspector_agrees_with_ue2_routing',
            ($inspection['ok'] ?? false) === true
                && ($inspection['summary']['Version'] ?? null) === 119
                && ($inspection['summary']['Licensee Version'] ?? null) === 37159
                && ($inspection['summary']['Build'] ?? '') === 'UE2',
            'The file-examine header view must not repeat the negative-combined-value mistake after routing is fixed.'
        );

        $modernFixture = pack('VV', 0x9E2A83C1, 0xFFFFFFF9) . str_repeat("\0", 56);
        if (file_put_contents($temporary, $modernFixture) !== strlen($modernFixture)) {
            throw new RuntimeException('Could not write the modern engine-routing fixture.');
        }
        $modernSummary = gp_read_legacy_summary($temporary);
        $record(
            'negative_modern_marker_still_selects_ue4',
            ($modernSummary['ok'] ?? false) === true
                && ($modernSummary['format'] ?? '') === 'ue4_package'
                && ($modernSummary['version'] ?? null) === -7
                && ($modernSummary['engine_hint'] ?? '') === 'UE4',
            'Giving known legacy versions precedence must not stop a genuine negative UE4 marker from selecting UE4.'
        );
    } finally {
        @unlink($temporary);
    }
} catch (Throwable $error) {
    $record('header_only_runtime_helpers', false, get_class($error) . ': ' . $error->getMessage());
}

$profiles = $read('lib/GameProfiles.php');
$compatibility = $read('lib/CompatibilityRules.php');
$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$profileAdmin = $read('src/Infrastructure/Games/CatalogGameProfileAdminService.php');
$assetMetadata = $read('src/Infrastructure/Metadata/CatalogAssetMetadataService.php');
$unverifiedIndex = $read('src/Infrastructure/Unverified/CatalogUnverifiedStagingIndex.php');
$unverifiedQueue = $read('src/Infrastructure/Unverified/CatalogUnverifiedQueueStorage.php');
$record(
    'extension_engine_detector_is_removed',
    !str_contains($profiles, 'function gp_detect_from_extension')
        && !str_contains($assetMetadata, 'gp_detect_from_extension')
        && !str_contains($unverifiedIndex, 'gp_detect_from_extension')
        && !str_contains($unverifiedQueue, 'gp_detect_from_extension'),
    'There must be no filename/extension-to-engine detector or production call site to fall back to.'
);
$record(
    'classification_has_no_extension_or_profile_fallback',
    !str_contains($profiles, '$engineByExt')
        && !str_contains($profiles, '$engineByVersion ?: $engineByExt')
        && !str_contains($profiles, "?: (\$selectedEngine ?: 'UNKNOWN')")
        && str_contains($profiles, 'filename and extension fallback is disabled'),
    'Unknown/corrupt headers must remain UNKNOWN instead of borrowing the extension or selected profile.'
);
$record(
    'primary_importer_has_no_extension_gate_or_reader_fallback',
    !str_contains($importer, '$profileExtensions')
        && !str_contains($importer, 'Extension not allowed by assigned profile')
        && !str_contains($importer, "\$readerEngine = \$profileEngine")
        && str_contains($importer, 'serialized header data'),
    'Primary import must neither reject by extension nor fall back to the selected profile reader.'
);
$record(
    'compatibility_rules_ignore_filename_fields',
    !str_contains($compatibility, 'compat_rule_extensions(')
        && !str_contains($compatibility, 'in_array($extension')
        && str_contains($profileAdmin, "unset(\$rule['extensions']"),
    'Compatibility acceptance must use detected engine/version/licensee header facts only.'
);
$record(
    'unverified_and_metadata_readers_are_header_only',
    str_contains($unverifiedIndex, 'serialized package header data')
        && !str_contains($unverifiedIndex, 'file header or extension')
        && !str_contains($assetMetadata, '$fallback')
        && !str_contains($unverifiedQueue, 'gp_detect_from_extension'),
    'Unverified indexing, queue identity and asset metadata must not resurrect extension-based reader selection.'
);

$uploadPage = $read('profiled-upload.php');
$uploadJs = $read('assets/profiled-upload-jobs.js');
$hashWorker = $read('assets/profiled-upload-hash-worker.js');
$chunkApi = $read('api/v1/profiled-upload-chunk.php');
$incoming = $read('src/Infrastructure/Import/CatalogIncomingFileStore.php');
$profiledQueue = $read('src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
$claimer = $read('src/Infrastructure/Persistence/PdoJobClaimer.php');
$record(
    'browser_does_not_wait_for_import_jobs',
    !str_contains($uploadJs, 'waitForJob(')
        && !str_contains($uploadJs, 'readJob(')
        && !str_contains($uploadJs, 'job-status.php')
        && str_contains($uploadJs, "defer_worker_start', '1")
        && str_contains($uploadJs, 'All selected files have finished duplicate preflight/upload staging'),
    'Each file may be locally preflighted, but browser flow must never wait for its background import job before advancing.'
);
$record(
    'client_preflight_happens_before_network_upload',
    str_contains($uploadJs, 'await duplicatePreflight(file, index, total)')
        && str_contains($uploadJs, 'if (preflight.skip || cancelRequested) return;')
        && str_contains($uploadJs, 'new Worker(hashWorkerUrl)')
        && str_contains($hashWorker, 'file.slice(loaded, end).arrayBuffer()'),
    'Ordinary files should be checked locally before network transfer while hashing stays off the UI thread.'
);
$record(
    'jobs_are_unclaimable_during_batch_staging',
    str_contains($profiledQueue, 'BATCH_HOLD_SECONDS')
        && str_contains($profiledQueue, '$holdForBatch ? $this->batchHoldUntil() : null')
        && str_contains($claimer, "'available_at<=?'")
        && str_contains($uploadPage, '$deferWorkerStart')
        && str_contains($chunkApi, '$deferWorkerStart'),
    'Held upload jobs must have a future available_at so even already-running workers cannot process them before batch release.'
);
$record(
    'batch_is_released_once_after_upload_loop',
    str_contains($profiledQueue, 'public function releaseHeldJobs(')
        && str_contains($uploadPage, "'release_batch'")
        && str_contains($uploadJs, 'async function releaseBatch()')
        && substr_count($uploadJs, 'await releaseBatch();') === 1
        && !str_contains($uploadJs, 'ensureWorker('),
    'The browser must release the held job IDs once, after the upload loop exits; the release endpoint starts background processing.'
);
$record(
    'normal_http_staging_skips_whole_file_sha256',
    str_contains($uploadPage, 'stageUploadedFile($temporaryPath, $originalName, false)')
        && str_contains($incoming, 'bool $hashNow = true')
        && str_contains($incoming, 'if ($hashNow)')
        && str_contains($incoming, "\$sha256 = '';"),
    'Normal browser ingress must not reread a just-uploaded file solely to SHA-256 it before returning the HTTP response.'
);
$record(
    'hashless_staging_still_has_durable_job_dedupe',
    str_contains($profiledQueue, "'profiled-staged:'")
        && str_contains($profiledQueue, '$stagedPath'),
    'Skipping the synchronous SHA-256 reread must not allow duplicate queue requests for the same durable staged object.'
);
$record(
    'upload_ui_states_background_independence',
    str_contains($uploadPage, 'Jobs remain unclaimable while the selected browser batch is uploading')
        && str_contains($uploadJs, 'processing continues independently'),
    'The UI must describe the durable staging/background-processing boundary accurately.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
