#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for corrupt archive retry, retained recovery and duplicate admission handling. */
declare(strict_types=1);

use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$leaseUntil = new DateTimeImmutable('+2 minutes');
$archiveJob = new ClaimedJob(
    1,
    'catalog:bucket-processing',
    JobType::PROCESS_BUCKET_ARCHIVE,
    ['original_name' => 'dmsludge.zip'],
    'test-lease',
    1,
    3,
    $leaseUntil
);
$ordinaryJob = new ClaimedJob(
    2,
    'catalog',
    JobType::REBUILD_FILE_DEPENDENCIES,
    ['file_id' => 123],
    'test-lease-2',
    1,
    3,
    $leaseUntil
);

$corrupt = new RuntimeException(
    'Archive "dmsludge.zip" could not be opened as ZIP. '
    . 'ZipArchive: Could not open ZIP archive (ZipArchive code 21). '
    . 'libarchive: Error moving to next header: Extra data overflow: Need 1024 bytes but only found 12 bytes.'
);
$transient = new RuntimeException('Archive source is temporarily unavailable.');

$record(
    'known_corrupt_zip_is_not_retried',
    JobFailureRetryPolicy::retryDelaySeconds($archiveJob, $corrupt) === 0,
    'A structurally inconsistent immutable ZIP must dead-letter on its first failed attempt instead of replaying the same bytes three times.'
);
$record(
    'ordinary_jobs_keep_normal_retry_policy',
    JobFailureRetryPolicy::retryDelaySeconds($ordinaryJob, $corrupt) > 0,
    'Archive corruption markers must not change retry behaviour for unrelated job types.'
);
$record(
    'transient_archive_failures_remain_retryable',
    JobFailureRetryPolicy::retryDelaySeconds($archiveJob, $transient) > 0,
    'Only deterministic archive-data failures are terminal; availability/runtime failures still retry.'
);

$workerPath = $root . '/src/Application/Jobs/JobWorker.php';
$policyPath = $root . '/src/Application/Jobs/JobFailureRetryPolicy.php';
$batchPath = $root . '/src/Infrastructure/Import/CatalogBucketBatchQueue.php';
$fingerprintPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';
$displayStatusPath = $root . '/src/Infrastructure/Jobs/CatalogJobDisplayStatus.php';
$countQueryPath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobDisplayCountQuery.php';
$browserQueryPath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobBrowserQuery.php';
$bulkActionPath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php';
$sequentialReaderPath = $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php';
$stagedPackagePath = $root . '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php';
$storageCleanupPath = $root . '/src/Infrastructure/Jobs/CatalogJobStorageCleanup.php';
$outcomeProjectorPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php';
$stableJobsJsPath = $root . '/assets/background-jobs-stable.js';
$archiveRecoveryJsPath = $root . '/assets/background-jobs-archive-errors.js';
$worker = (string)@file_get_contents($workerPath);
$policy = (string)@file_get_contents($policyPath);
$batch = (string)@file_get_contents($batchPath);
$fingerprint = (string)@file_get_contents($fingerprintPath);
$displayStatus = (string)@file_get_contents($displayStatusPath);
$countQuery = (string)@file_get_contents($countQueryPath);
$browserQuery = (string)@file_get_contents($browserQueryPath);
$bulkAction = (string)@file_get_contents($bulkActionPath);
$sequentialReader = (string)@file_get_contents($sequentialReaderPath);
$stagedPackage = (string)@file_get_contents($stagedPackagePath);
$storageCleanup = (string)@file_get_contents($storageCleanupPath);
$outcomeProjector = (string)@file_get_contents($outcomeProjectorPath);
$stableJobsJs = (string)@file_get_contents($stableJobsJsPath);
$archiveRecoveryJs = (string)@file_get_contents($archiveRecoveryJsPath);

$record(
    'worker_uses_failure_retry_policy',
    str_contains($worker, 'JobFailureRetryPolicy::retryDelaySeconds($job, $exception)'),
    'Worker exception handling must ask the central policy before scheduling a retry.'
);
$record(
    'corrupt_zip_markers_are_explicit',
    str_contains($policy, "'ziparchive code 21'")
        && str_contains($policy, 'PROCESS_BUCKET_ARCHIVE')
        && str_contains($policy, 'IMPORT_STAGED_ARCHIVE')
        && str_contains($policy, 'Do not classify libarchive stream/header failures as immutable source')
        && !str_contains($policy, "'extra data overflow'"),
    'The dmsludge.zip composite failure is deterministic because ZipArchive code 21 proves a structural ZIP error; libarchive extra-data overflow alone must remain recoverable by native ZIP fallback.'
);
$record(
    'archive_uploads_dedupe_by_content_and_source_path',
    str_contains($batch, "'bucket-archive-source:'")
        && str_contains($batch, '$archiveSourceIdentity = hash(')
        && str_contains($batch, '$fingerprint')
        && str_contains($batch, 'strtolower($relativePath)')
        && str_contains($batch, 'activeSourceForDedupe(')
        && !str_contains($batch, "'bucket-archive-upload:' . \$uploadId"),
    'Two active submissions of identical archive bytes at the same logical mirror path must share one archive job; different paths retain distinct provenance.'
);
$record(
    'deduped_archive_upload_removes_redundant_staging',
    str_contains($batch, '$existingSourceAvailable')
        && str_contains($batch, 'CatalogChunkedUploadCleanup($this->config))->delete($uploadId)'),
    'When an active archive job already owns valid staged bytes, the redundant second upload source should be removed after the job is reused.'
);
$record(
    'retained_partial_archive_filter_is_first_class',
    str_contains($displayStatus, "'partial_archive'")
        && str_contains($displayStatus, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($displayStatus, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($displayStatus, 'display_status="partial"')
        && str_contains($countQuery, "'partial_archive' => 0")
        && str_contains($countQuery, "\$counts['partial_archive'] += \$amount"),
    'Background Jobs must expose completed partial archive parents as a dedicated retained-archive recovery scope.'
);
$record(
    'retained_partial_archive_filter_uses_no_bound_type_params',
    str_contains($displayStatus, "JobType::PROCESS_BUCKET_ARCHIVE . '\",\"'")
        && str_contains($displayStatus, "'params' => []"),
    'The synthetic retained-archive filter must not add bound job-type parameters after the shared UNION queue scope.'
);
$record(
    'retained_archive_view_is_narrow_indexed_query_only',
    str_contains($browserQuery, 'return $this->fetchRetainedArchives($queue, $perPage, $cursor, $move);')
        && str_contains($browserQuery, "'SELECT COUNT(*) FROM ue_background_jobs j WHERE ' . \$whereSql")
        && str_contains($browserQuery, "\$counts = ['partial_archive' => \$total];")
        && !str_contains($browserQuery, 'fastQueueCounts(')
        && !str_contains($browserQuery, 'GROUP BY j.status,j.display_status,j.job_type'),
    'Opening Retained archives must count only the indexed partial archive roots; it must not scan/group every root or problem child merely to populate unrelated tabs.'
);
$record(
    'stable_client_keeps_retained_archive_deep_link_on_first_fetch',
    str_contains($stableJobsJs, "'partial_archive', 'cancelled'")
        && str_contains($stableJobsJs, 'validStatuses.includes(requestedStatus)'),
    'The base Background Jobs client must recognise partial_archive before its initial refresh; otherwise the deep link starts an expensive unfiltered All-jobs request first.'
);
$record(
    'retained_archive_row_sync_cannot_self_trigger_renderer_loop',
    str_contains($archiveRecoveryJs, "setText(button, 'Retry archive')")
        && str_contains($archiveRecoveryJs, 'Do not observe table childList mutations here')
        && !str_contains($archiveRecoveryJs, 'new MutationObserver(() => window.queueMicrotask(syncRecoveryRows)).observe(tableBody'),
    'Retained archive row decoration must be idempotent and must not mutate textContent from a MutationObserver watching the same table subtree.'
);
$record(
    'rar_zero_byte_non_extracted_records_do_not_open_member_stream',
    str_contains($sequentialReader, '$declaredSize !== null && (int)$declaredSize === 0 && !$extract')
        && str_contains($sequentialReader, '$complete($entry, null, $state);')
        && str_contains($sequentialReader, 'continue;'),
    'RAR directory records exposed without a trailing slash but with a known zero size must not be opened as data streams.'
);
$record(
    'retained_archive_restart_replays_parent_from_start',
    str_contains($bulkAction, 'j.display_status="partial"')
        && str_contains($bulkAction, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($bulkAction, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($bulkAction, 'progress_json=NULL,progress_updated_at=NULL')
        && str_contains($bulkAction, '$retainedArchiveIds'),
    'Retrying a retained partial archive must requeue the parent and clear its completed archive cursor while preserving already-created child jobs for dedupe.'
);
$record(
    'non_package_archive_members_complete_without_retry',
    str_contains($stagedPackage, 'isDeterministicNonPackage')
        && str_contains($stagedPackage, 'does not contain a supported unreal package header')
        && str_contains($stagedPackage, 'unreal package magic not found')
        && str_contains($stagedPackage, 'CatalogImportOutcome::INVALID_UE_PACKAGE'),
    'A successfully extracted member that is definitively not an Unreal package must be an invalid-file outcome, not an archive retry.'
);
$record(
    'reader_validation_failures_are_retained_without_archive_retry',
    str_contains($stagedPackage, 'isReaderValidationFailure')
        && str_contains($stagedPackage, 'JobFailureRetryPolicy::isInvalidPackageContentText')
        && str_contains($policy, "'invalid exports table offset:'")
        && str_contains($policy, "'invalid imports table offset:'")
        && str_contains($policy, "'invalid names table offset:'")
        && str_contains($stagedPackage, 'CatalogImportOutcome::INVALID_UE_PACKAGE')
        && str_contains($stagedPackage, "'source_retained' => true")
        && str_contains($stagedPackage, 'archive extraction completed successfully'),
    'Deterministic UE reader-validation failures must preserve the member as an invalid UE file without making the healthy archive retryable.'
);
$record(
    'completed_retained_sources_survive_storage_cleanup',
    str_contains($storageCleanup, 'SELECT status,result_json FROM ue_background_jobs')
        && str_contains($storageCleanup, 'isRecoveryOwner')
        && str_contains($storageCleanup, "\$result['source_retained']"),
    'A completed unverified job with source_retained=true must remain a storage owner instead of losing its prepared bytes to routine cleanup.'
);
$record(
    'archive_parent_separates_invalid_ue_child_from_extraction_failure',
    str_contains($outcomeProjector, "['invalid_ue_package', 'invalid_files', 'rejected']")
        && str_contains($outcomeProjector, '$summary[\'invalid_ue\']++')
        && str_contains($outcomeProjector, 'CatalogImportOutcome::ARCHIVE_INVALID_FILES')
        && str_contains($outcomeProjector, "['invalid_ue_package', 'invalid_files']"),
    'A bad Unreal member must remain visible while the containing archive is excluded from extraction retry state.'
);
$record(
    'retained_archive_operator_controls_exist',
    str_contains($archiveRecoveryJs, "data-status=\"partial_archive\"")
        || (str_contains($archiveRecoveryJs, "dataset.status = 'partial_archive'")
            && str_contains($archiveRecoveryJs, 'Retained archives')),
    'Background Jobs must expose the retained-archive filter in the administrator UI.'
);
$record(
    'retained_archive_retry_controls_exist',
    str_contains($archiveRecoveryJs, 'Retry archive')
        && str_contains($archiveRecoveryJs, 'Retry retryable archives')
        && str_contains($archiveRecoveryJs, "status: 'partial_archive'")
        && str_contains($archiveRecoveryJs, "action: 'restart'")
        && str_contains($archiveRecoveryJs, 'Decoder-blocked archives'),
    'Administrators need both per-archive retry and all-matching retryable retained archive actions while decoder-blocked sources remain retained.'
);
$record(
    'worker_fingerprint_tracks_reader_and_retry_policy',
    str_contains($fingerprint, '/src/Application/Jobs/JobFailureRetryPolicy.php')
        && str_contains($fingerprint, '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php')
        && str_contains($fingerprint, '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php'),
    'Changing retry classification or either archive/package reader must invalidate detached workers.'
);

$syntaxFailures = [];
foreach ([
    $workerPath,
    $policyPath,
    $batchPath,
    $fingerprintPath,
    $displayStatusPath,
    $countQueryPath,
    $browserQueryPath,
    $bulkActionPath,
    $sequentialReaderPath,
    $stagedPackagePath,
    $storageCleanupPath,
    $outcomeProjectorPath,
] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
