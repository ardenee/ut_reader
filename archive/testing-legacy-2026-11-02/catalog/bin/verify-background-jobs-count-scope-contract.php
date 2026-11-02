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
$fileTreeQuery = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');

$withoutComments = static function (string $source): string {
    if ($source === '') {
        return '';
    }
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }
    return $code;
};
$displayCountsCode = $withoutComments($displayCounts);

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
    str_contains($searchScope, 'root_job.parent_job_id IS NULL')
        && str_contains($searchScope, 'profiled_source.*')
        && str_contains($searchScope, 'profiled_parent.id=profiled_source.parent_job_id')
        && str_contains($searchScope, 'problem_child.parent_job_id IS NOT NULL')
        && str_contains($searchScope, 'problem_child.status IN ("failed","dead_letter")')
        && !str_contains($searchScope, 'problem_child.status IN ("failed","dead_letter","cancelled")'),
    'Routine workflow children, including cancelled internal history, must stay folded into their source/root; promoted source jobs and failed/dead-letter children remain directly actionable.'
);
$check(
    'background_jobs_counts_use_bounded_operator_scope',
    str_contains($browser, 'PdoBackgroundJobSearchScope')
        && str_contains($browser, 'PdoBackgroundJobDisplayCountQuery')
        && str_contains($browser, '$this->countQuery->counts(')
        && str_contains($displayCountsCode, 'GROUP BY j.status,j.display_status,j.job_type')
        && !str_contains($displayCountsCode, 'GROUP BY operator_status')
        && !str_contains($displayCountsCode, 'BackgroundJobDisplaySql::operatorStatus(')
        && str_contains($displayCountsCode, 'running_child.parent_job_id=j.id')
        && str_contains($displayCountsCode, '$counts[\'queued\'] -= $promoted')
        && str_contains($displayCountsCode, '$counts[\'running\'] += $promoted'),
    'Tabs and totals must use persisted status grouping plus one indexed queued-parent promotion count; never put a correlated child lookup inside GROUP BY.'
);
$check(
    'file_tree_live_counts_are_root_scoped',
    str_contains($fileTreeQuery, '$logicalCountWhere = array_merge($commonWhere, [$logicalRootScope]);')
        && str_contains($fileTreeQuery, '$logicalCountSql = implode(\' AND \', $logicalCountWhere);')
        && str_contains($fileTreeQuery, '\'FROM ue_background_jobs j WHERE \' . $logicalCountSql')
        && str_contains($fileTreeQuery, 'AS working_count')
        && str_contains($fileTreeQuery, '$this->childActiveCountExpression(\'j\')')
        && str_contains($fileTreeQuery, 'ORDER BY j.id DESC LIMIT \' . $perPage'),
    'The current file-centric Background Jobs page must count logical roots globally and only calculate child activity for the bounded visible page.'
);

$check(
    'retained_archive_view_has_direct_count_path',
    str_contains($browser, "if (\$status === 'partial_archive' && \$search === '')")
        && str_contains($browser, 'SELECT COUNT(*) FROM ue_background_jobs j WHERE ')
        && str_contains($browser, '$counts = [\'partial_archive\' => $total]'),
    'The retained-archive view must count its small indexed root subset directly rather than invoke generic operator-history counts.'
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
    'browser_worker_status_is_bounded_and_job_centric',
    str_contains($operationalQuery, 'public function queuePresence(')
        && str_contains($operationalQuery, 'public function operatorActiveCounts(')
        && str_contains($workerEndpoint, '$presence = $operational->queuePresence($queueName);')
        && str_contains($workerEndpoint, '$operatorCounts = $operational->operatorActiveCounts($queueName);')
        && !str_contains($workerEndpoint, '$operational->queueCounts($queueName)')
        && str_contains($workerEndpoint, "['queue_counts'] = \$operatorCounts;")
        && str_contains($workerEndpoint, "['queue_counts_scope'] = 'operator_jobs'")
        && !str_contains($ui, 'Work units'),
    'Browser worker polling must use bounded durable presence plus operator-job counts, never raw multi-million execution-row totals.'
);
$check(
    'exact_durable_counts_remain_diagnostic_only',
    str_contains($operationalQuery, 'public function queueCounts(')
        && str_contains($operationalQuery, 'SUM(status="queued")')
        && str_contains($operationalQuery, 'SUM(status="running")')
        && !str_contains($workerEndpoint, '$operational->queueCounts($queueName)'),
    'Exact durable execution counts remain available for diagnostics without being part of the live browser polling path.'
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
