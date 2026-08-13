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
$bridge = $read('assets/background-jobs-cursor-bridge.js');
$displayQuery = $read('src/Infrastructure/Persistence/PdoBackgroundJobOffsetQuery.php');
$operationalQuery = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php');
$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'queue_selector_is_navigation_not_stale_telemetry',
    str_contains($ui, 'The queue selector is navigation, not telemetry')
        && str_contains($ui, 'option.textContent = queueName')
        && str_contains($ui, 'Live raw work-unit counts are shown in the worker status line'),
    'The queue selector must not keep server-rendered counts that immediately diverge from live polling.'
);
$check(
    'operator_view_scope_is_explicit',
    str_contains($ui, 'Operator view — parent workflows plus child units requiring attention')
        && str_contains($displayQuery, 'j.parent_job_id IS NULL OR j.status IN ("failed","dead_letter","cancelled")'),
    'Tabs/table intentionally fold routine child units into parent workflows and must say so.'
);
$check(
    'raw_work_units_use_authoritative_live_counts',
    str_contains($bridge, 'const counts = worker.queue_counts || {}')
        && str_contains($bridge, "String(counts.running || 0)")
        && str_contains($bridge, "String(counts.queued || 0)")
        && str_contains($operationalQuery, 'SUM(status="queued") queued')
        && str_contains($operationalQuery, 'SUM(status="running") running')
        && str_contains($operationalQuery, 'available_at<=UTC_TIMESTAMP()'),
    'Live active/queued counts must come from the raw durable queue-unit read model used by the authoritative worker-status endpoint.'
);
$check(
    'duplicate_pool_summary_is_hidden',
    str_contains($ui, "document.getElementById('jobs-worker-pool-state')")
        && str_contains($ui, 'poolState.hidden = true')
        && str_contains($ui, "poolState.setAttribute('aria-hidden', 'true')"),
    'The page must show one worker-process summary, not the worker banner plus a second Pool x/y label.'
);
$check(
    'worker_processed_scope_is_explained',
    str_contains($ui, 'processed = jobs completed by the current worker processes')
        && str_contains($ui, 'active/queued = raw durable work units'),
    'Processed is a worker-session counter, not a queue total; its scope must be explicit.'
);
$check(
    'clarity_layer_adds_no_status_poll',
    !str_contains($ui, 'workerStatusUrl')
        && !str_contains($ui, 'job-worker-status.php'),
    'The count-clarity layer must reuse the established clients and must not add another status polling request.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
