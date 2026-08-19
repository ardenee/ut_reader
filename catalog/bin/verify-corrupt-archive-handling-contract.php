#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for corrupt archive retry and duplicate admission handling. */
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
$worker = (string)@file_get_contents($workerPath);
$policy = (string)@file_get_contents($policyPath);
$batch = (string)@file_get_contents($batchPath);
$fingerprint = (string)@file_get_contents($fingerprintPath);

$record(
    'worker_uses_failure_retry_policy',
    str_contains($worker, 'JobFailureRetryPolicy::retryDelaySeconds($job, $exception)'),
    'Worker exception handling must ask the central policy before scheduling a retry.'
);
$record(
    'corrupt_zip_markers_are_explicit',
    str_contains($policy, "'extra data overflow'")
        && str_contains($policy, "'ziparchive code 21'")
        && str_contains($policy, 'PROCESS_BUCKET_ARCHIVE')
        && str_contains($policy, 'IMPORT_STAGED_ARCHIVE'),
    'The exact structural failure seen in dmsludge.zip must remain classified as deterministic archive data.'
);
$record(
    'archive_uploads_dedupe_by_content_and_source_path',
    str_contains($batch, "'bucket-archive-source:'")
        && str_contains($batch, '$archiveSourceIdentity = hash(')
        && str_contains($batch, '$fingerprint')
        && str_contains($batch, 'strtolower($relativePath)')
        && str_contains($batch, 'activeSourceForDedupe(')
        && !str_contains($batch, "'bucket-archive-upload:' . $uploadId"),
    'Two active submissions of identical archive bytes at the same logical mirror path must share one archive job; different paths retain distinct provenance.'
);
$record(
    'deduped_archive_upload_removes_redundant_staging',
    str_contains($batch, '$existingSourceAvailable')
        && str_contains($batch, 'CatalogChunkedUploadCleanup($this->config))->delete($uploadId)'),
    'When an active archive job already owns valid staged bytes, the redundant second upload source should be removed after the job is reused.'
);
$record(
    'worker_fingerprint_tracks_retry_policy',
    str_contains($fingerprint, '/src/Application/Jobs/JobFailureRetryPolicy.php'),
    'Changing deterministic failure classification must invalidate detached workers.'
);

$syntaxFailures = [];
foreach ([$workerPath, $policyPath, $batchPath, $fingerprintPath] as $path) {
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
