#!/usr/bin/env php
<?php
/**
 * Verifies that archive parent jobs remain non-terminal until all archive-member
 * child jobs have reached terminal states. Source checks are always read-only;
 * --run additionally checks the live database invariant without mutating data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$run = in_array('--run', array_slice($argv, 1), true);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$coordinator = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$factory = $read('src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
$outcomes = $read('src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php');
$repair = $read('src/Infrastructure/Persistence/PdoArchiveParentLifecycleRepair.php');
$maintenance = $read('bin/repair-background-job-compatibility.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$leaseStore = $read('src/Infrastructure/Persistence/PdoJobLeaseStore.php');

$record(
    'archive_routes_use_workflow_coordinator',
    substr_count($factory, 'new CatalogArchiveWorkflowJobHandler($db, $trustedImportConfig)') >= 2
        && !str_contains($factory, 'JobType::IMPORT_STAGED_ARCHIVE => static fn() => new CatalogArchiveImportJobHandler')
        && !str_contains($factory, 'JobType::PROCESS_BUCKET_ARCHIVE => static fn() => new CatalogArchiveImportJobHandler'),
    'Both archive parent job types must route through the lifecycle coordinator rather than completing directly after extraction.'
);

$record(
    'archive_parent_waits_for_children',
    str_contains($coordinator, "private const WAIT_STAGE = 'archive_wait_children'")
        && str_contains($coordinator, "(\$childState['queued'] + \$childState['running']) > 0")
        && str_contains($coordinator, '$context->checkpoint($waiting)')
        && str_contains($coordinator, '$context->defer(2, $waiting, true)')
        && str_contains($coordinator, '$this->children->fetch($job->id)'),
    'An archive parent with queued/running member children must persist its waiting state and defer instead of returning a terminal result.'
);

$record(
    'waiting_parent_releases_worker_without_spending_attempt',
    str_contains($leaseStore, 'status="queued",attempts=GREATEST(attempts-1,0)')
        && str_contains($coordinator, '$context->defer(2, $waiting, true)'),
    'Waiting for children must release the worker and must not consume retry attempts.'
);

$record(
    'parent_finalizes_from_terminal_child_outcomes',
    str_contains($outcomes, 'GROUP BY status,display_status')
        && str_contains($outcomes, "'successful'")
        && str_contains($outcomes, "'duplicate'")
        && str_contains($outcomes, "'failed'")
        && str_contains($outcomes, "'cancelled'")
        && str_contains($coordinator, '$totalFailed = $extractionFailed + $childFailed')
        && str_contains($coordinator, '$result[\'status\'] = $partial ? \'partial\' : \'completed\';'),
    'Final archive status must be based on extraction failures plus the terminal outcomes of all child jobs.'
);

$record(
    'waiting_resume_does_not_reextract_archive',
    str_contains($coordinator, "(string)(\$resume['stage'] ?? '') === self::WAIT_STAGE")
        && str_contains($coordinator, "is_array(\$resume['archive_result'] ?? null)")
        && str_contains($coordinator, "return \$resume['archive_result'];"),
    'A deferred archive parent must resume from its stored extraction result instead of walking the archive again.'
);

$record(
    'legacy_completed_parent_repair_is_explicit',
    !str_contains($factory, 'PdoArchiveParentLifecycleRepair')
        && str_contains($maintenance, 'PdoArchiveParentLifecycleRepair')
        && str_contains($maintenance, "array_key_exists('execute', $options)")
        && str_contains($maintenance, 'reopenCompletedParentsWithActiveChildren($queue)')
        && str_contains($repair, 'p.status="completed"')
        && str_contains($repair, 'c.status IN ("queued","running")')
        && str_contains($repair, 'status="queued",attempts=GREATEST(attempts-1,0)')
        && str_contains($repair, 'result_json=NULL')
        && str_contains($repair, "'archive_result' => \$archiveResult"),
    'Historical parent repair must remain explicit maintenance; ordinary worker construction must not reopen old rows.'
);

$record(
    'worker_fingerprint_tracks_runtime_not_maintenance',
    str_contains($fingerprint, '/Persistence/PdoArchiveChildOutcomeQuery.php')
        && !str_contains($fingerprint, '/Persistence/PdoArchiveParentLifecycleRepair.php')
        && str_contains($fingerprint, '/Jobs/CatalogArchiveWorkflowJobHandler.php'),
    'Detached workers must track archive runtime code, while maintenance-only repair changes must not make live workers stale.'
);

$syntaxTargets = [
    'bin/repair-background-job-compatibility.php',
    'src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    'src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
    'src/Infrastructure/Persistence/PdoArchiveParentLifecycleRepair.php',
    'src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
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
$record('php_syntax', $syntaxFailures === [], $syntaxFailures === [] ? 'All lifecycle PHP files parse.' : implode(' | ', $syntaxFailures));

if ($run) {
    try {
        require_once $root . '/bootstrap/operational.php';
        $application = catalog_operational_application();
        $queue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $statement = $application->db->prepare(
            'SELECT COUNT(DISTINCT p.id) FROM ue_background_jobs p '
            . 'JOIN ue_background_jobs c ON c.parent_job_id=p.id AND c.queue_name=p.queue_name '
            . 'WHERE p.queue_name=? AND p.parent_job_id IS NULL AND p.status="completed" '
            . 'AND p.job_type IN (?,?) AND c.status IN ("queued","running")'
        );
        $statement->execute([
            $queue,
            \UnrealDb\Catalog\Domain\Jobs\JobType::PROCESS_BUCKET_ARCHIVE,
            \UnrealDb\Catalog\Domain\Jobs\JobType::IMPORT_STAGED_ARCHIVE,
        ]);
        $violations = max(0, (int)$statement->fetchColumn());
        $record(
            'live_no_completed_archive_parent_has_active_children',
            $violations === 0,
            $violations === 0
                ? 'No completed archive parent has queued/running children.'
                : number_format($violations) . ' completed archive parent(s) still have active children; restart/reconcile workers once after deployment.'
        );
    } catch (Throwable $error) {
        $record('live_no_completed_archive_parent_has_active_children', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'runtime_checked' => $run,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
