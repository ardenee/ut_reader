#!/usr/bin/env php
<?php
/** Read-only contract/runtime verifier for deterministic staged Unreal package failures. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/src/Domain/Jobs/JobType.php';
require_once $root . '/src/Domain/Jobs/ClaimedJob.php';
require_once $root . '/src/Application/Jobs/JobFailureRetryPolicy.php';

use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$job = static function (string $type): ClaimedJob {
    return new ClaimedJob(
        1,
        'catalog:bucket-processing',
        $type,
        ['original_name' => 'sample.upk'],
        'test-lease',
        1,
        3,
        new DateTimeImmutable('+2 minutes'),
        'bucket-processing',
        1
    );
};

$truncated = new RuntimeException('Epic UE3 compressed chunk exceeds physical package size');
$record(
    'bucket_staged_ue3_truncation_is_non_retryable',
    JobFailureRetryPolicy::retryDelaySeconds($job(JobType::PROCESS_BUCKET_STAGED_PACKAGE), $truncated) === 0,
    'A staged Upload Bucket package whose UE3 compressed range extends beyond EOF is immutable structural corruption and must dead-letter on the first failed attempt.'
);
$record(
    'profiled_staged_ue3_truncation_is_non_retryable',
    JobFailureRetryPolicy::retryDelaySeconds($job(JobType::IMPORT_STAGED_PACKAGE), $truncated) === 0,
    'The same immutable UE3 package bytes must not be retried three times through the profiled staged-package route either.'
);
$record(
    'transient_staged_package_failure_still_retries',
    JobFailureRetryPolicy::retryDelaySeconds(
        $job(JobType::PROCESS_BUCKET_STAGED_PACKAGE),
        new RuntimeException('Temporary filesystem I/O error')
    ) > 0,
    'Only known deterministic structural failures should bypass normal retry/backoff behavior.'
);
$record(
    'unrelated_job_is_not_reclassified_by_ue3_message',
    JobFailureRetryPolicy::retryDelaySeconds($job(JobType::FULL_SYNC_FILE), $truncated) > 0,
    'The UE3 structural marker is scoped to immutable staged-package jobs and must not alter unrelated workflow retry semantics.'
);

$policy = (string)@file_get_contents($root . '/src/Application/Jobs/JobFailureRetryPolicy.php');
$record(
    'policy_documents_physical_chunk_boundary_reason',
    str_contains($policy, 'CompressedOffset/CompressedSize')
        && str_contains($policy, 'extends past EOF'),
    'The retry policy should document why a UE3 compressed-range overflow cannot become valid on a later attempt.'
);

$syntax = [];
foreach ([
    $root . '/src/Application/Jobs/JobFailureRetryPolicy.php',
    __FILE__,
] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntax[] = basename($path) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntax[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntax === [], implode(' | ', $syntax));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
