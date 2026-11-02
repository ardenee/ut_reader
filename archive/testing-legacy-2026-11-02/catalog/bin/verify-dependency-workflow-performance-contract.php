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
$pakTargetPath = $root . '/src/Infrastructure/Jobs/CatalogPakDependencyTargetQuery.php';
$pakTarget = (string)@file_get_contents($pakTargetPath);
$statsCoordinatorPath = $root . '/src/Infrastructure/Jobs/CatalogGameStatsRefreshCoordinator.php';
$statsCoordinator = (string)@file_get_contents($statsCoordinatorPath);
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
        && str_contains($handler, "'workflow_defer_dependency_summary' => true"),
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
        && str_contains($enqueuer, 'implode(\',\', $tuples)')
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
    'pak_dependencies_use_targeted_mode',
    str_contains($handler, 'isPakDependencyWorkflow($job)')
        && str_contains($handler, 'rebuildPakDependencies($job, $context, $gameId)')
        && str_contains($handler, "'pak-dependency:' . \$fileId")
        && str_contains($handler, "'workflow_defer_dependency_summary' => false"),
    'A PAK dependency child must target only imported/provider/affected files rather than invoking the whole-game planner.'
);

$record(
    'pak_target_discovery_uses_compact_term_index',
    str_contains($pakTarget, 'workflow_unit_key LIKE "pak-entry:%"')
        && str_contains($pakTarget, 'ue_file_package_aliases')
        && str_contains($pakTarget, '(value_hash=? AND value_length=?)')
        && str_contains($pakTarget, 'l.required_package_term_id IN ('),
    'PAK target discovery must derive provider packages from durable entry results and use indexed compact dependency term IDs.'
);

$record(
    'pak_game_stats_are_coalesced',
    str_contains($handler, 'CatalogGameStatsRefreshCoordinator::request(')
        && str_contains($handler, "'game_stats_refresh_job_id' => \$statsJobId")
        && str_contains($handler, "'game_stats_refreshed' => false")
        && !str_contains($handler, 'Targeted PAK dependency files completed; refreshing cached game counters once.'),
    'A completed targeted PAK dependency workflow must schedule/reuse one shared stats publisher instead of rescanning the whole game synchronously.'
);

$record(
    'coalesced_stats_job_is_debounced_and_deduplicated',
    str_contains($statsCoordinator, 'private const QUIET_SECONDS = 20;')
        && str_contains($statsCoordinator, 'private const MAX_DEBOUNCE_SECONDS = 90;')
        && str_contains($statsCoordinator, 'private const PRIORITY = 90;')
        && str_contains($statsCoordinator, 'status IN ("queued","running")')
        && str_contains($statsCoordinator, 'postponeQueued(')
        && str_contains($statsCoordinator, "'game_stats_only' => true")
        && str_contains($statsCoordinator, "'game-stats:' . \$gameId"),
    'Burst PAK completions should collapse into a bounded delayed stats job rather than create one whole-game aggregate per PAK.'
);

$record(
    'coalesced_stats_waits_for_dependency_burst',
    str_contains($handler, 'pendingGameDependencyWorkExists($job, $gameId)')
        && str_contains($handler, "'game_stats_wait'")
        && str_contains($handler, "'dependency:game:' . \$gameId")
        && str_contains($handler, "'game-stats:' . \$gameId . ':%'"),
    'The low-priority stats publisher must yield while real dependency coordinators for the same game remain active.'
);

$statsOnlyBranch = strpos($handler, "if (!empty(\$job->payload['game_stats_only']))");
$pakBranch = strpos($handler, 'if ($this->isPakDependencyWorkflow($job))');
$record(
    'stats_only_job_short_circuits_dependency_planning',
    $statsOnlyBranch !== false
        && $pakBranch !== false
        && $statsOnlyBranch < $pakBranch
        && str_contains($handler, 'return $this->rebuildGameStatsOnly($job, $context, $gameId);'),
    'The shared stats job must never enter the whole-game dependency planner.'
);

$record(
    'legacy_pak_whole_game_children_are_superseded',
    str_contains($handler, 'cancelQueuedLegacyChildren($job->id)')
        && str_contains($handler, 'isLegacyPakDependencyFileUnit($job)')
        && str_contains($handler, 'Superseded by targeted PAK dependency refresh.'),
    'Already-queued PAK whole-game children must drain without replaying unrelated game dependencies.'
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
    $pakTargetPath,
    $statsCoordinatorPath,
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
