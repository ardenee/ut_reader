#!/usr/bin/env php
<?php
/**
 * Read-only contract verifier for archive workflow source ownership.
 *
 * --job=<id> optionally verifies that a live archive parent/child resolves to a
 * retained job-owned archive source after the updated workflow has run.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$jobId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--job=(\d+)$/', (string)$argument, $match) === 1) {
        $jobId = (int)$match[1];
        break;
    }
}

$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$sourceStore = $read('src/Infrastructure/Jobs/CatalogArchiveSourceStore.php');
$workflow = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$resolver = $read('src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php');
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
    'archive_source_is_job_owned_before_extraction',
    str_contains($workflow, '$ownedJob = $this->sources->prepareJob($job);')
        && str_contains($workflow, '$this->extractor->handle($ownedJob, $context)'),
    'Archive extraction must consume the parent job-owned prepared source, never the browser chunk source directly.'
);

$record(
    'archive_source_store_uses_prepared_workspace',
    str_contains($sourceStore, "CatalogPreparedJobFileStore($this->config, $job->id, 'archive-source')")
        && str_contains($sourceStore, "'archive_source_owned' => true")
        && str_contains($sourceStore, "'local-catalog:'"),
    'The immutable archive must be atomically published into jobs/prepared/job-<id>/archive-source and re-enter the reader through a controlled catalog-local reference.'
);

$record(
    'ingress_cleanup_occurs_after_publish',
    str_contains($sourceStore, '$prepared = $store->load();')
        && str_contains($sourceStore, '$prepared = $this->publish($job, $store);')
        && str_contains($sourceStore, '$this->cleanupTransferredIngress($prepared);')
        && str_contains($sourceStore, 'CatalogChunkedUploadCleanup($this->config))->delete($match[1])'),
    'Chunk-upload ingress may be removed only after the durable prepared archive has been published successfully.'
);

$record(
    'read_only_sources_are_copied_not_moved',
    str_contains($sourceStore, '$transferIngress ? $source : $this->copyForPublish($source, $job->id)')
        && str_contains($sourceStore, '!@link($source, $temporary) && !@copy($source, $temporary)'),
    'Catalog-local/read-only sources must remain untouched while the parent takes its own durable copy.'
);

$record(
    'diagnostics_prefer_job_owned_archive',
    str_contains($resolver, "CatalogPreparedJobFileStore($this->config, $jobId, 'archive-source')")
        && str_contains($resolver, "'archive_source_storage'] = 'job-prepared'")
        && str_contains($resolver, "'archive_prepared_path'"),
    'System Errors and locate-job-source must report the retained job-owned archive path even after browser ingress is released.'
);

$record(
    'worker_fingerprint_tracks_archive_source_store',
    str_contains($fingerprint, '/src/Infrastructure/Jobs/CatalogArchiveSourceStore.php'),
    'Detached workers must reconcile when archive source ownership changes.'
);

$syntaxTargets = [
    'src/Infrastructure/Jobs/CatalogArchiveSourceStore.php',
    'src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    'src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
];
$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ': could not run php -l';
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
$record(
    'php_syntax',
    $syntaxFailures === [],
    $syntaxFailures === [] ? 'All archive source ownership PHP files parse.' : implode(' | ', $syntaxFailures)
);

$liveSource = null;
if ($jobId > 0) {
    try {
        require_once $root . '/bootstrap/operational.php';
        $application = catalog_operational_application();
        $liveSource = (new \UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobSourceContextResolver(
            $application->db,
            $application->config
        ))->forJobId($jobId);
        $preparedPath = trim((string)($liveSource['archive_prepared_path'] ?? ''));
        $record(
            'live_job_has_owned_archive_source',
            $preparedPath !== '' && is_file($preparedPath)
                && (string)($liveSource['archive_source_storage'] ?? '') === 'job-prepared',
            json_encode($liveSource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
        );
    } catch (Throwable $error) {
        $record('live_job_has_owned_archive_source', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'job_id' => $jobId > 0 ? $jobId : null,
    'source' => $liveSource,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($failures === [] ? 0 : 2);
