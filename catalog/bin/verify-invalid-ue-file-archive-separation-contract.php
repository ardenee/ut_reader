#!/usr/bin/env php
<?php
/** Read-only contract: invalid UE content is a System Error, never retryable archive work. */
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

$outcome = $read('src/Infrastructure/Import/CatalogImportOutcome.php');
$policy = $read('src/Application/Jobs/JobFailureRetryPolicy.php');
$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$bucket = $read('src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php');
$children = $read('src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php');
$workflow = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$router = $read('src/Infrastructure/Jobs/CatalogArchiveMemberContentRoutingJobHandler.php');
$projector = $read('src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$fileTreeQuery = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');
$fileTreeProjector = $read('src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php');
$display = $read('src/Infrastructure/Jobs/CatalogJobDisplayStatus.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$reporter = $read('src/Infrastructure/Telemetry/CatalogInvalidUeFileReporter.php');
$systemRecorder = $read('src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php');
$backfill = $read('src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'invalid_ue_has_distinct_child_outcome',
    str_contains($outcome, "INVALID_UE_PACKAGE = 'invalid_ue_package'"),
    'Invalid package bytes need a durable child outcome distinct from worker/archive extraction failure.'
);

$record(
    'package_structure_failure_is_content_failure',
    str_contains($policy, 'isInvalidPackageContentText')
        && str_contains($policy, "'invalid exports table offset:'")
        && str_contains($policy, "'invalid imports table offset:'")
        && str_contains($policy, "'invalid compact package index length'")
        && str_contains($policy, "'invalid legacy wide fstring length:'"),
    'UE table/index/string contradictions such as the truncated M1.utx case must be classified as invalid package content.'
);

$record(
    'invalid_profiled_import_reports_system_error',
    str_contains($staged, 'CatalogInvalidUeFileReporter::record([')
        && str_contains($staged, "'reason' => $shortError")
        && str_contains($staged, "'system_error_recorded' => $systemErrorRecorded"),
    'Invalid profiled package content must be recorded in System Errors when the terminal child outcome is produced.'
);

$record(
    'invalid_bucket_member_reports_system_error',
    substr_count($bucket, 'CatalogInvalidUeFileReporter::record([') >= 2
        && str_contains($bucket, "'archive_source_name' =>")
        && str_contains($bucket, "'archive_entry_path' =>")
        && str_contains($bucket, "'md5' => $md5")
        && str_contains($bucket, "'sha1' => $sha1"),
    'Both non-package and parser-invalid extracted members must be recorded as System Errors with archive/file identity.'
);

$record(
    'system_error_type_is_file_validation_not_job_retry',
    str_contains($reporter, "'source_kind' => 'unreal-file-validation'")
        && str_contains($reporter, "'error_type' => 'InvalidUnrealPackage'")
        && str_contains($reporter, "'route' => $route")
        && str_contains($reporter, "'disposition' => 'invalid_ue_file'")
        && str_contains($reporter, "'md5' => $md5")
        && str_contains($reporter, "'archive_source_name' => $archiveSourceName"),
    'System Errors must identify the invalid UE file/content and preserve archive provenance without calling the archive corrupt.'
);

$record(
    'system_error_recorder_reports_persistence_success',
    str_contains($systemRecorder, 'public static function record(array $data): bool')
        && str_contains($systemRecorder, 'return true;')
        && str_contains($systemRecorder, 'return false;'),
    'Jobs/backfill need a persistence acknowledgement before marking an invalid-file error as reported.'
);

$record(
    'archive_child_rollup_keeps_invalid_content_out_of_failed_count',
    str_contains($children, "'invalid_ue' => 0")
        && str_contains($children, "['invalid_ue_package', 'invalid_files', 'rejected']")
        && str_contains($children, '$state[\'invalid_ue\'] += $count')
        && str_contains($children, "['failed', 'unverified', 'partial', 'error']"),
    'Invalid UE content may be counted diagnostically but must not increment archive failed/retry counts.'
);

$record(
    'archive_with_only_invalid_content_completes',
    str_contains($workflow, '$partial = $totalFailed > 0 || $cancelled > 0;')
        && str_contains($workflow, "$result['status'] = $partial ? 'partial' : 'completed';")
        && str_contains($workflow, "'invalid_ue_files' => $invalidUe")
        && !str_contains($workflow, 'CatalogImportOutcome::ARCHIVE_INVALID_FILES'),
    'A healthy archive containing an invalid UE member must complete; only extraction/worker failure makes it partial.'
);

$record(
    'nested_archive_with_only_invalid_content_completes',
    str_contains($router, '$partial = $failed > 0 || $cancelled > 0;')
        && str_contains($router, "$status = $partial ? 'partial' : 'nested_archive';")
        && !str_contains($router, 'CatalogImportOutcome::ARCHIVE_INVALID_FILES'),
    'Invalid UE content must not turn a nested healthy archive into a retryable partial archive.'
);

$record(
    'read_projection_keeps_historical_invalid_archives_completed',
    str_contains($projector, "['invalid_ue_package', 'invalid_files', 'rejected']")
        && str_contains($projector, '$summary[\'invalid_ue\']++')
        && str_contains($projector, "$row['display_status'] = 'completed';")
        && !str_contains($projector, 'CatalogImportOutcome::ARCHIVE_INVALID_FILES'),
    'Historical invalid_files archive rows must project as completed when no actual extraction/child failure exists.'
);

$record(
    'background_jobs_issue_filters_exclude_invalid_ue',
    !str_contains($fileTreeQuery, '"invalid_ue_package"')
        && !str_contains($fileTreeQuery, '"invalid_files"')
        && !str_contains($fileTreeProjector, "'invalid_ue_package', 'partial'")
        && str_contains($fileTreeProjector, "'invalid_ue_package' => 'Invalid UE file · logged in System Errors'")
        && !str_contains($display, "'failed', 'rejected', 'unverified', 'invalid_ue_package'")
        && !str_contains($display, 'display_status IN ("failed","rejected","unverified","invalid_ue_package")'),
    'Invalid UE content must not appear in Background Jobs Issue/failed filters or retry-job grouping.'
);

$record(
    'archive_retry_scope_remains_real_partial_only',
    str_contains($bulk, 'j.display_status="partial"')
        && str_contains($bulk, "status === 'partial_archive'")
        && !str_contains($bulk, 'j.display_status="invalid_files"'),
    'Retry retryable archives must remain limited to real partial archive outcomes.'
);

$record(
    'historical_invalid_ue_backfill_is_idempotent_and_metadata_only',
    str_contains($backfill, 'display_status="invalid_ue_package"')
        && str_contains($backfill, '$.system_error_recorded')
        && str_contains($backfill, 'CatalogInvalidUeFileReporter::record([')
        && str_contains($backfill, 'GET_LOCK')
        && !str_contains($backfill, 'CatalogArchiveExtractor')
        && !str_contains($backfill, 'CatalogIncomingFileStore'),
    'Existing invalid UE child jobs must be copied to System Errors once without reopening source bytes.'
);

$record(
    'worker_startup_runs_invalid_ue_system_error_backfill',
    str_contains($factory, 'new PdoInvalidUeSystemErrorBackfill($db)')
        && str_contains($factory, 'Invalid Unreal package content is a System Error/data-quality problem')
        && str_contains($fingerprint, '/src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php')
        && str_contains($fingerprint, '/src/Infrastructure/Telemetry/CatalogInvalidUeFileReporter.php')
        && str_contains($fingerprint, '/src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php'),
    'A deployed worker must invalidate stale code and backfill historical invalid UE System Errors automatically.'
);

$syntaxFailures = [];
foreach ([
    $root . '/src/Infrastructure/Telemetry/CatalogSystemErrorRecorder.php',
    $root . '/src/Infrastructure/Telemetry/CatalogInvalidUeFileReporter.php',
    $root . '/src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php',
    $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveMemberContentRoutingJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    $root . '/src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
    $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    $root . '/src/Infrastructure/Jobs/CatalogJobDisplayStatus.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not run php -l';
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
