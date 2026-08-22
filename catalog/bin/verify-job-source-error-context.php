#!/usr/bin/env php
<?php
/**
 * Verifies that background-job errors retain actionable source provenance.
 * Source checks are read-only; --job=<id> additionally resolves one live job.
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
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$resolver = $read('src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php');
$locator = $read('bin/locate-job-source.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$record(
    'system_errors_enrich_job_source_context',
    str_contains($factory, 'new CatalogJobSourceContextResolver($db, $config)')
        && str_contains($factory, 'forClaimedJob($job)')
        && str_contains($factory, "\$context['archive_full_path']")
        && str_contains($factory, "\$message .= ' Archive: ' . \$archivePath"),
    'Worker failure reporting must add resolved source provenance and put the archive path in the human-readable error message.'
);

$record(
    'archive_children_resolve_parent_source',
    str_contains($resolver, "'archive_parent_job_id'")
        && str_contains($resolver, "SELECT id,job_type,parent_job_id,payload_json FROM ue_background_jobs")
        && str_contains($resolver, "\$context['archive_source_relative_path']")
        && str_contains($resolver, "\$context['archive_full_path']")
        && str_contains($resolver, 'CatalogIncomingFileStore'),
    'Archive child provenance must resolve the retained parent payload and its controlled server-side staged path.'
);

$record(
    'job_source_locator_reuses_resolver',
    str_contains($locator, 'CatalogJobSourceContextResolver')
        && str_contains($locator, 'forJobId($jobId)')
        && str_contains($locator, "'read_only' => true"),
    'Operators must be able to locate a source from a child or parent job id without mutating queue state.'
);

$record(
    'worker_fingerprint_tracks_source_resolver',
    str_contains($fingerprint, '/Jobs/CatalogJobSourceContextResolver.php'),
    'Detached workers must reconcile when source-provenance resolution changes.'
);

$syntaxTargets = [
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
    'src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    'bin/locate-job-source.php',
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
    $syntaxFailures === [] ? 'All source-provenance PHP files parse.' : implode(' | ', $syntaxFailures)
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
        $record(
            'live_job_source_resolves',
            (int)($liveSource['job_id'] ?? 0) === $jobId,
            json_encode($liveSource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
        );
    } catch (Throwable $error) {
        $record('live_job_source_resolves', false, get_class($error) . ': ' . $error->getMessage());
    }
}

fwrite(STDOUT, json_encode([
    'ok' => $failures === [],
    'job_id' => $jobId > 0 ? $jobId : null,
    'source' => $liveSource,
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($failures === [] ? 0 : 2);
