#!/usr/bin/env php
<?php
/** Read-only contract for dependency workflow summary batching and planner performance. */
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

$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php';
$handler = (string)@file_get_contents($handlerPath);
$rebuilderPath = $root . '/src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php';
$rebuilder = (string)@file_get_contents($rebuilderPath);
$summaryPath = $root . '/src/Infrastructure/Persistence/PdoDependencyPackageSummary.php';
$summary = (string)@file_get_contents($summaryPath);
$queuePath = $root . '/src/Infrastructure/Persistence/PdoJobQueue.php';
$queue = (string)@file_get_contents($queuePath);
$enqueuerPath = $root . '/src/Infrastructure/Persistence/PdoJobEnqueuer.php';
$enqueuer = (string)@file_get_contents($enqueuerPath);

$record(
    'dependency_handler_uses_direct_rebuilder_without_implicit_summary',
    str_contains($handler, 'new PdoCatalogDependencyRebuilder($this->db, $this->config)')
        && preg_match("/'Refreshing file dependency links',\\s*false\\s*\\)/", $handler) === 1
        && !str_contains($handler, 'scanner_rebuild_dependencies('),
    'The file job must disable the rebuilder summary hook so summary publication has one explicit owner.'
);

$record(
    'standalone_file_summary_is_rebuilt_once',
    substr_count($handler, '->rebuildFile($fileId)') === 1,
    'Standalone/post-import dependency jobs should publish one per-file summary, not rebuild the same summary twice.'
);

$record(
    'whole_game_children_defer_summary_publication',
    str_contains($handler, '$deferWorkflowSummary')
        && str_contains($handler, "'dependency_summary_deferred'")
        && str_contains($handler, 'parent workflow bulk publisher'),
    'Whole-game file units should finish dependency resolution without opening one summary transaction per child.'
);

$record(
    'whole_game_summary_phase_is_durable_and_bounded',
    str_contains($handler, 'private const SUMMARY_BATCH_SIZE = 1000;')
        && str_contains($handler, "'dependency_game_summary'")
        && str_contains($handler, 'summary_last_child_job_id')
        && str_contains($handler, 'rebuildGameSummaryBatch(')
        && str_contains($handler, '$context->defer(1,'),
    'Summary publication must use a durable cursor so a large game does not hold one lease for the entire final projection pass.'
);

$record(
    'bulk_summary_api_is_used',
    str_contains($handler, '->rebuildFiles($fileIds)')
        && str_contains($summary, 'private const BULK_FILE_BATCH = 250;')
        && str_contains($summary, 'foreach (array_chunk($fileIds, self::BULK_FILE_BATCH) as $chunk)'),
    'The coordinator should amortise summary SQL over bounded 250-file transactions instead of one transaction per file.'
);

$record(
    'dependency_planner_uses_bulk_child_enqueue',
    str_contains($handler, '->enqueueWorkflowUnits(')
        && !str_contains($handler, '$queue->enqueue('),
    'A 500-file planning page must not perform 500 separate child INSERT statements.'
);

$record(
    'workflow_enqueuer_batches_rows',
    str_contains($enqueuer, 'private const WORKFLOW_INSERT_BATCH_SIZE = 100;')
        && str_contains($enqueuer, 'foreach (array_chunk(array_values($units), self::WORKFLOW_INSERT_BATCH_SIZE) as $chunk)')
        && str_contains($enqueuer, "' VALUES ' . implode(',', $tuples)")
        && str_contains($enqueuer, 'ON DUPLICATE KEY UPDATE id=id,updated_at=updated_at'),
    'Workflow child creation must use bounded multi-row inserts and retain idempotent replay.'
);

$record(
    'workflow_batch_preserves_resource_policy',
    str_contains($enqueuer, 'JobResourcePolicy::for($type, $payload)')
        && str_contains($enqueuer, '$resource->resourceClass')
        && str_contains($enqueuer, '$resource->limit')
        && str_contains($enqueuer, '$resource->concurrencyKey'),
    'Batch enqueue must preserve each child resource class, limit and per-file concurrency key.'
);

$record(
    'queue_exposes_workflow_batch_api',
    str_contains($queue, 'public function enqueueWorkflowUnits(')
        && str_contains($queue, '$this->enqueuer->enqueueWorkflowUnits('),
    'The durable queue facade should own the workflow batching API rather than embedding queue SQL in a handler.'
);

$record(
    'legacy_finalize_resume_gets_summary_phase',
    str_contains($handler, '$stage === \'dependency_game_finalize\' && empty($resume[\'dependency_summary_complete\'])'),
    'Already-running version-2 workflows must receive the idempotent bulk-summary phase after deployment.'
);

$record(
    'rebuilder_default_behavior_remains_compatible',
    str_contains($rebuilder, 'bool $refreshSummary = true')
        && str_contains($rebuilder, 'if ($refreshSummary) {'),
    'Other rebuilder callers retain the existing summary behavior unless they explicitly opt out.'
);

foreach ([
    $handlerPath,
    $rebuilderPath,
    $summaryPath,
    $queuePath,
    $enqueuerPath,
] as $path) {
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
    $record('syntax:' . basename($path), $ok, $ok ? '' : $detail);
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
