#!/usr/bin/env php
<?php
/** Read-only contract for retrying failed workflow children without replaying successful siblings. */
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

$endpoint = $read('api/v1/job-action.php');
$ui = $read('assets/background-jobs-files.js');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'parent_control_targets_failed_children',
    str_contains($ui, "action: 'retry_children'")
        && str_contains($ui, 'file.child_issue_count')
        && str_contains($ui, 'without replaying successful children'),
    'A parent workflow with child issues must expose a targeted retry control rather than forcing the whole workflow to restart.'
);

$check(
    'endpoint_selects_terminal_problem_children_only',
    str_contains($endpoint, 'parent_job_id=?')
        && str_contains($endpoint, 'status IN ("cancelled","failed","dead_letter")')
        && str_contains($endpoint, 'ORDER BY id ASC LIMIT 10000'),
    'Retry-children must select only direct failed/cancelled/dead-letter children under the chosen parent and remain bounded.'
);

$check(
    'retry_reuses_shared_bulk_restart',
    str_contains($endpoint, 'new PdoBackgroundJobBulkAction')
        && str_contains($endpoint, "'restart'")
        && str_contains($bulk, 'status="queued",attempts=0'),
    'Child retry must reuse the existing restart policy so attempts/leases/results are reset consistently.'
);

$check(
    'successful_children_are_not_replayed',
    !str_contains(
        substr(
            $endpoint,
            (int)strpos($endpoint, "if ($action === 'retry_children')"),
            max(0, (int)strpos($endpoint, "if ($action === 'revalidate')") - (int)strpos($endpoint, "if ($action === 'retry_children')"))
        ),
        'status="completed"'
    ),
    'The targeted child retry path must never select successful completed siblings.'
);

$check(
    'worker_is_started_after_requeue',
    str_contains($endpoint, 'CatalogQueueWorkerStarter')
        && str_contains($endpoint, "'requeued' => $affected"),
    'A successful child retry must wake/start the worker pool and report the number requeued.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
