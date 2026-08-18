#!/usr/bin/env php
<?php
/** Read-only contract for dependency-file fan-out and queued-policy repair. */
declare(strict_types=1);

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
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

$profile = JobResourcePolicy::for(JobType::REBUILD_FILE_DEPENDENCIES, ['file_id' => 42]);
$record(
    'dependency_file_units_use_parallel_class',
    $profile->resourceClass === JobResourcePolicy::AFFECTED_DEPENDENCY_BATCH,
    'Independent rebuild_file_dependencies units must not share the single-slot dependency coordinator class.'
);
$record(
    'dependency_file_units_default_to_four_slots',
    $profile->resourceLimit === 4,
    'The bounded dependency-file class should use four slots by default; administrators can still lower the saved class limit.'
);
$record(
    'dependency_file_units_keep_per_file_key',
    $profile->concurrencyKey === 'dependency:file:42',
    'Parallel fan-out must retain exact per-file exclusion.'
);

$storePath = $root . '/src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php';
$store = (string)@file_get_contents($storePath);
$record(
    'queued_dependency_rows_are_reclassified',
    str_contains($store, 'JobType::REBUILD_FILE_DEPENDENCIES')
        && str_contains($store, 'JobResourcePolicy::AFFECTED_DEPENDENCY_BATCH')
        && str_contains($store, '$dependencyFileRows'),
    'Queued rows created under the old serialized policy must be repaired without deleting or recreating jobs.'
);

$factoryPath = $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php';
$factory = (string)@file_get_contents($factoryPath);
$record(
    'worker_start_repairs_persisted_policy',
    str_contains($factory, 'CatalogJobResourceLimitStore($db, $queueName)')
        && str_contains($factory, 'synchronizeQueuedPolicies()'),
    'A normal worker restart must make already-queued rows learn the current code policy.'
);

foreach ([
    'src/Domain/Jobs/JobResourcePolicy.php',
    'src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
] as $relative) {
    $path = $root . '/' . $relative;
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $ok = is_resource($process);
    $detail = '';
    if ($ok) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $ok = $exit === 0;
        $detail = trim((string)$stdout . ' ' . (string)$stderr);
    } else {
        $detail = 'Could not start PHP syntax check.';
    }
    $record('syntax:' . basename($relative), $ok, $ok ? '' : $detail);
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
