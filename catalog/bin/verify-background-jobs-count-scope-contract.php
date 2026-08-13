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
        && str_contains($ui, 'Live work-unit counts are shown in the worker status line'),
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
    str_contains($ui, 'worker.queue_counts')
        && str_contains($ui, "'Work units: '")
        && str_contains($ui, "' available now'")
        && str_contains($operationalQuery, 'SUM(status="queued") queued')
        && str_contains($operationalQuery, 'SUM(status="running") running')
        && str_contains($operationalQuery, 'available_at<=UTC_TIMESTAMP()'),
    'Live running/queued/available counts must come from the raw durable queue-unit read model.'
);
$check(
    'count_clarity_reuses_existing_worker_poll',
    str_contains($ui, 'Reuse the status request the established client already makes')
        && str_contains($ui, "url.includes(workerStatusUrl)")
        && str_contains($ui, 'response.clone().json()'),
    'Count clarity must not add another two-second database/status polling request.'
);
$check(
    'worker_processed_label_has_scope',
    str_contains($ui, 'completed this worker session'),
    'The processed counter is a worker-session completion counter, not a queue total, and must be labelled as such.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
