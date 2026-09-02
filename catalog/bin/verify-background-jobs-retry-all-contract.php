#!/usr/bin/env php
<?php
/**
 * Verifies file-centric Background Jobs can retry the complete filtered source set
 * instead of being limited to the current 200-row page.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'background-jobs.php',
    'api/v1/job-bulk.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$page = $read('background-jobs.php');
$js = $read('assets/background-jobs-files.js');
$api = $read('api/v1/job-bulk.php');
$query = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');

$record(
    'background_jobs_exposes_retry_all_matching',
    str_contains($page, "jobs-retry-all-matching")
        && str_contains($page, "Retry all matching"),
    'The file-centric page must expose a separate all-matching retry action.'
);

$record(
    'browser_sends_exact_file_filters',
    str_contains($js, "scope: 'file_matching'")
        && str_contains($js, 'file_state: state.filter')
        && str_contains($js, 'job_type: state.jobType')
        && str_contains($js, 'search: state.search')
        && str_contains($js, 'state.meta.total'),
    'Retry all matching must use the current queue/state/job-type/search filters rather than the visible 200 rows.'
);

$record(
    'dynamic_bulk_buttons_clear_stale_aria_disabled_state',
    str_contains($js, 'function setButtonDisabled(button, disabled)')
        && str_contains($js, "button.removeAttribute('aria-disabled')")
        && str_contains($js, 'setButtonDisabled(retryAllMatchingButton, matchingSources === 0)')
        && str_contains($js, 'setButtonDisabled(retrySelectedButton, selectedSources === 0)'),
    'Dynamically enabled bulk buttons must clear aria-disabled as well as the native disabled property so they are visibly and semantically enabled.'
);

$record(
    'bulk_api_accepts_file_matching_scope',
    str_contains($api, "['selected', 'matching', 'file_matching']")
        && str_contains($api, 'matchingRootIds(')
        && str_contains($api, "\$sourceSelection = in_array(\$scope, ['selected', 'file_matching'], true)")
        && str_contains($api, "\$result['selection_limited']"),
    'The bulk endpoint must resolve logical source roots server-side and then reuse selected-source retry semantics.'
);

$record(
    'file_tree_query_has_bounded_matching_root_selector',
    str_contains($query, 'public function matchingRootIds(')
        && str_contains($query, '$this->logicalRootExpression')
        && str_contains($query, '$this->rootSearchCondition')
        && str_contains($query, '$this->stateCondition')
        && str_contains($query, 'min($limit, 10000)'),
    'The selector must share file-centric root, search and state rules and retain the 10,000-source safety cap.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
