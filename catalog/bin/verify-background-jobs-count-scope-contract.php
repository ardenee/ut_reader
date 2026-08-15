#!/usr/bin/env php
<?php
/** Read-only source contract for Background Jobs count/reporting scopes. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$ui = $read('assets/background-jobs-async-cleanup.js');
$page = $read('background-jobs.php');
$searchScope = $read('src/Infrastructure/Persistence/PdoBackgroundJobSearchScope.php');
$displayCounts = $read('src/Infrastructure/Persistence/PdoBackgroundJobDisplayCountQuery.php');
$browser = $read('src/Infrastructure/Persistence/PdoBackgroundJobBrowserQuery.php');
$operatorSnapshot = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperatorSnapshotQuery.php');
$operationalQuery = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php');
$workerEndpoint = $read('api/v1/job-worker-status.php');
$systemOperations = $read('system-operations.php');
$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'queue_selector_is_navigation_not_telemetry',
    str_contains($ui, 'option.textContent = name')
        && str_contains($ui, "queueSelect.title = 'Queue selector'")
        && str_contains($page, '. catalog_h($name) . \'</option>\'')
        && !str_contains($page, 'active database row')
        && !str_contains($page, '$label = $name'),
    'The server-rendered and enhanced queue selector must display only the queue identity; raw durable-row counts must never flash as job telemetry.'
);
$check(
    'system_operations_is_directly_reachable',
    str_contains($page, "'System Operations' => 'system-operations.php'"),
    'Background Jobs must provide a direct path to the production diagnostics view.'
);
$check(
    'operator_job_scope_is_explicit',
    str_contains($searchScope, 'j.parent_job_id IS NULL')
        && str_contains($searchScope, 'j.parent_job_id IS NOT NULL')
        && str_contains($searchScope, 'failed')
        && str_contains($searchScope, 'dead_letter')
        && str_contains($searchScope, 'cancelled')
        && str_contains($page, 'routine child rows stay hidden unless they need attention'),
    'Routine workflow children must stay folded into their parent while failed/dead-letter/cancelled children remain actionable.'
);
$check(
    'background_jobs_counts_use_operator_scope',
    str_contains($browser, 'PdoBackgroundJobSearchScope')
        && str_contains($browser, 'PdoBackgroundJobDisplayCountQuery')
        && str_contains($browser, '$this->countQuery->counts(')
        && str_contains($displayCounts, 'BackgroundJobDisplaySql::operatorStatus('),
    'Tabs and result totals must derive from the same operator-visible scope/status rules as the displayed rows.'
);
$check(
    'system_operations_reuses_operator_count_policy',
    str_contains($operatorSnapshot, 'PdoBackgroundJobSearchScope')
        && str_contains($operatorSnapshot, 'PdoBackgroundJobDisplayCountQuery')
        && str_contains($operatorSnapshot, '$this->counts->counts(')
        && str_contains($systemOperations, 'same rolled-up operator scope as Background Jobs'),
    'System Operations must not invent a competing queued/running job count.'
);
$check(
    'durable_execution_counts_are_health_only',
    str_contains($operationalQuery, 'SUM(status="queued") queued')
        && str_contains($operationalQuery, 'SUM(status="running") running')
        && str_contains($workerEndpoint, '$counts = $operational->queueCounts($queueName);')
        && str_contains($workerEndpoint, 'CatalogWorkerStatusPolicy::evaluate(')
        && str_contains($workerEndpoint, "['queue_counts'] = \$counts;")
        && !str_contains($ui, 'queue_counts')
        && !str_contains($ui, 'Work units'),
    'Raw durable execution rows may drive worker health/admission but must not appear as a competing operator headline count.'
);
$check(
    'one_visible_worker_summary',
    str_contains($ui, 'legacyWorkerState.hidden = true')
        && str_contains($ui, "readableWorkerState.id = 'jobs-worker-summary-readable'")
        && str_contains($ui, "document.getElementById('jobs-worker-pool-state')")
        && str_contains($ui, 'poolState.hidden = true'),
    'Internal status elements may remain for control logic, but only one worker-process summary should be visible.'
);
$check(
    'operator_language_is_job_centric',
    !str_contains($page, 'Work units')
        && !str_contains($page, 'work units')
        && !str_contains($ui, 'Work units')
        && !str_contains($ui, 'work units')
        && !str_contains($systemOperations, 'Work units')
        && !str_contains($systemOperations, 'work units'),
    'Operator-facing queue reporting must use jobs; internal workflow/execution detail must not compete with the job count.'
);
$check(
    'worker_session_counter_is_not_a_queue_total',
    !str_contains($ui, 'completed this worker session'),
    'The streamlined worker summary must not present a process-session counter beside durable job totals.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
