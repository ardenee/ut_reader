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
$policyPath = $root . '/src/Application/Jobs/JobFailureRetryPolicy.php';
$cleanupPath = $root . '/src/Infrastructure/Import/CatalogChunkedUploadCleanup.php';
$maintenancePath = $root . '/src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php';
$fingerprintPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';

$handler = (string)@file_get_contents($handlerPath);
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
    'archive_parent_releases_source_after_clean_expansion',
    str_contains($handler, '$sourceRetained = $failed > 0;')
        && str_contains($handler, "'source_retained' => $sourceRetained")
        && str_contains($handler, 'source archive released after successful extraction'),
    'Once extraction has handed every selected member to durable child staging, the parent archive must not be retained.'
);

$record(
    'archive_extraction_failures_retain_source',
    str_contains($handler, 'source archive retained because extraction had unresolved failures')
        && str_contains($handler, "'source_retained' => true")
        && str_contains($handler, 'terminalArchiveCapabilityResult'),
    'Only unresolved extraction/decoder failures should retain the archive recovery source.'
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
        && str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php'),
    'Changing archive source lifetime or missing-source retry policy must mark detached workers stale.'
);

$syntaxFailures = [];
foreach ([$handlerPath, $policyPath, $cleanupPath, $maintenancePath, $fingerprintPath] as $path) {
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
