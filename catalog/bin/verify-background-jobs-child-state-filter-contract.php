#!/usr/bin/env php
<?php
/** Read-only contract for state-scoped Background Jobs child expansion. */
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

$query = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');
$endpoint = $read('api/v1/job-file-tree.php');
$ui = $read('assets/background-jobs-files.js');

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'child_requests_inherit_selected_tab',
    str_contains($ui, 'state: state.filter,')
        && str_contains($ui, 'parent_job_id: String(parentId)'),
    'Expanding a row must request children using the same Working/Issues/Completed/Stopped/All state selected at the top of the page.'
);

$check(
    'endpoint_passes_state_to_children',
    str_contains($endpoint, '$query->children($queue, $parentJobId, $state, $page, $perPage)')
        && str_contains($endpoint, "'state' => $state"),
    'The child API must retain and report the selected state instead of dropping it for parent_job_id reads.'
);

$check(
    'child_query_filters_before_pagination',
    str_contains($query, 'public function children(')
        && str_contains($query, 'string $state,')
        && str_contains($query, '$stateCondition = $this->stateCondition($state, $issue, $active, \'j\');')
        && str_contains($query, '$stateWhere = $stateCondition !== \'\' ? \' WHERE \' . $stateCondition : \'\';')
        && substr_count($query, '. $stateWhere') >= 2,
    'Child state filtering must apply to both COUNT and row SELECT before LIMIT/OFFSET, so completed history cannot consume Working pages.'
);

$check(
    'working_children_are_live_only',
    str_contains($query, "'working' => 'NOT (' . $issue . ') AND ' . $active")
        && str_contains($query, '$active = \'j.status IN ("queued","running")\';'),
    'Working child expansion must contain queued/running child jobs rather than completed historical units.'
);

$check(
    'filtered_child_count_is_visible',
    str_contains($ui, "matchingChildren.toLocaleString() + ' ' + state.filter + ' child item(s) · '")
        && str_contains($ui, "No ' + state.filter + ' child files/jobs under this item."),
    'Expanded rows must tell the operator how many children match the active tab and explicitly show when none match.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
