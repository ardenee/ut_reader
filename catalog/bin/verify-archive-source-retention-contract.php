#!/usr/bin/env php
<?php
/** Read-only verifier for archive source lifetime and missing-source retry behaviour. */
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

$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$workflowPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php';
$policyPath = $root . '/src/Application/Jobs/JobFailureRetryPolicy.php';
$cleanupPath = $root . '/src/Infrastructure/Import/CatalogChunkedUploadCleanup.php';
$maintenancePath = $root . '/src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php';
$fingerprintPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';

$handler = (string)@file_get_contents($handlerPath);
$workflow = (string)@file_get_contents($workflowPath);
$policy = (string)@file_get_contents($policyPath);
$cleanup = (string)@file_get_contents($cleanupPath);
$maintenance = (string)@file_get_contents($maintenancePath);
$fingerprint = (string)@file_get_contents($fingerprintPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'extractor_marks_clean_archive_source_disposable',
    str_contains($handler, '$sourceRetained = $failed > 0;')
        && str_contains($handler, '\'source_retained\' => $sourceRetained')
        && str_contains($handler, 'source archive released after successful extraction'),
    'Extraction may mark a clean archive disposable, but the coordinator owns the actual source lifetime until child outcomes are known.'
);

$record(
    'archive_extraction_failures_retain_source',
    str_contains($handler, 'source archive retained because extraction had unresolved failures')
        && str_contains($handler, "'source_retained' => true")
        && str_contains($handler, 'terminalArchiveCapabilityResult'),
    'At the extraction layer, unresolved decoder/member failures mark the archive source retained; the coordinator may additionally retain it for child outcomes.'
);

$record(
    'archive_parent_source_waits_for_child_outcomes',
    !str_contains(
        substr(
            $workflow,
            (int)(strpos($workflow, '$childState = $this->children->fetch($job->id);') ?: 0),
            max(
                0,
                (int)(
                    (strpos($workflow, "if (\$childState['total'] < 1)") ?: strlen($workflow))
                    - (strpos($workflow, '$childState = $this->children->fetch($job->id);') ?: 0)
                )
            )
        ),
        '$this->releaseSourceIfDisposable($job, $archiveResult);'
    )
        && str_contains($workflow, 'Keep the parent archive source until every child has reached a')
        && str_contains($workflow, '$waiting[\'source_retained\'] = true;'),
    'The archive source must remain owned while child parser/import jobs are still queued or running.'
);

$record(
    'child_problem_outcomes_retain_parent_archive',
    str_contains($workflow, '$result[\'source_retained\'] = !empty($archiveResult[\'source_retained\'])')
        && str_contains($workflow, '|| $childFailed > 0')
        && str_contains($workflow, '|| $cancelled > 0')
        && str_contains($workflow, '|| $invalidUe > 0'),
    'A child failure/cancellation/invalid-UE classification must retain the parent archive for explicit recovery without re-upload.'
);

$record(
    'clean_terminal_archive_releases_parent_source',
    str_contains($workflow, '$result = $this->finalResult($job, $archiveResult, $childState, $context);')
        && str_contains($workflow, '$this->releaseSourceIfDisposable($job, $result);')
        && str_contains($workflow, 'if (!empty($archiveResult[\'source_retained\']))'),
    'After all child outcomes are known, only a clean disposable archive should release its parent-owned source.'
);

$leaseUntil = new DateTimeImmutable('+2 minutes');
$archiveJob = new ClaimedJob(
    91001,
    'catalog:bucket-processing',
    JobType::PROCESS_BUCKET_ARCHIVE,
    ['staged_path' => 'chunk-upload:' . str_repeat('a', 64), 'original_name' => 'legacy.rar'],
    'retention-test-lease',
    1,
    3,
    $leaseUntil
);
$missingSource = new RuntimeException('Chunked upload was not found.');
$record(
    'missing_archive_source_is_not_retried',
    JobFailureRetryPolicy::retryDelaySeconds($archiveJob, $missingSource) === 0
        && str_contains($policy, "'chunked upload was not found'"),
    'Once durable archive bytes are gone, replay cannot repair the job; do not burn all retry attempts on the same missing source.'
);

$record(
    'maintenance_prunes_only_incomplete_chunk_uploads',
    str_contains($maintenance, 'CatalogChunkedUploadCleanup($this->config))->pruneIncomplete()')
        && str_contains($cleanup, "=== 'complete'")
        && str_contains($cleanup, 'continue;'),
    'Routine stale-artifact maintenance must leave completed chunk-upload sources alone; terminal job-history cleanup owns their eventual deletion.'
);

$record(
    'worker_fingerprint_tracks_archive_retention_logic',
    str_contains($fingerprint, '/src/Application/Jobs/JobFailureRetryPolicy.php')
        && str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php')
        && str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php'),
    'Changing archive source lifetime or missing-source retry policy must mark detached workers stale.'
);

$syntaxFailures = [];
foreach ([$handlerPath, $workflowPath, $policyPath, $cleanupPath, $maintenancePath, $fingerprintPath] as $path) {
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
exit($failures === [] ? 0 : 1);
