#!/usr/bin/env php
<?php
/** Verifies Background Jobs can delete the complete filtered source set safely. */
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
    if (!$ok) $failures[] = $name . ': ' . $detail;
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$phpFiles = [
    'background-jobs.php',
    'api/v1/job-bulk.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php',
];
$syntaxFailures = [];
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$page = $read('background-jobs.php');
$js = $read('assets/background-jobs-files.js');
$api = $read('api/v1/job-bulk.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$query = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');
$cleanupQueue = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php');
$cleanupHandler = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupJobHandler.php');

$record(
    'background_jobs_exposes_delete_all_matching',
    str_contains($page, "jobs-delete-all-matching")
        && str_contains($page, "Delete all matching"),
    'The file-centric page must expose a separate bulk cleanup action that is not limited to the visible page.'
);

$record(
    'delete_all_matching_uses_exact_file_filters',
    str_contains($js, "action: 'delete'")
        && str_contains($js, "scope: 'file_matching'")
        && str_contains($js, 'file_state: state.filter')
        && str_contains($js, 'job_type: state.jobType')
        && str_contains($js, 'search: state.search')
        && str_contains($js, 'Delete all matching ('),
    'Bulk cleanup must use the current queue/state/job-type/search filters and show the matching source count.'
);

$record(
    'delete_confirmation_describes_destructive_scope',
    str_contains($js, 'complete child job history will be removed')
        && str_contains($js, 'running roots are always skipped')
        && str_contains($js, 'Owned staged job-storage files are also removed'),
    'The confirmation must make subtree deletion, running-job protection and staged-file cleanup explicit.'
);

$record(
    'server_resolves_file_matching_roots',
    str_contains($api, "if (\$scope === 'file_matching')")
        && str_contains($api, 'matchingRootIds(')
        && str_contains($api, "\$result['matching_source_jobs']")
        && str_contains($api, "\$result['selection_limited']"),
    'The server must resolve all matching logical roots independently of the current browser page.'
);

$record(
    'running_roots_are_never_deleted',
    str_contains($bulk, "'delete' => 'j.status IN (\"queued\",\"completed\",\"failed\",\"dead_letter\",\"cancelled\")'")
        && str_contains($bulk, 'Cancelled automatically because the job was selected for deletion.'),
    'Delete matching may cancel queued roots, but running roots must remain outside the cleanup snapshot.'
);

$record(
    'cleanup_snapshot_is_bounded_and_recursive',
    str_contains($query, 'min($limit, 10000)')
        && str_contains($cleanupQueue, 'public const SNAPSHOT_LIMIT = 10000')
        && str_contains($cleanupHandler, 'CatalogBackgroundJobSubtreePruner')
        && str_contains($cleanupHandler, 'deleteWorkflowJobs')
        && str_contains($cleanupHandler, 'deleteTerminalJobs'),
    'Large cleanup must stay bounded to 10,000 roots per snapshot while deleting each root subtree leaf-first.'
);

$record(
    'matching_cleanup_has_operator_label_and_remainder_notice',
    str_contains($api, "'Delete all matching source jobs'")
        && str_contains($bulk, 'string $operationLabel =')
        && str_contains($js, '10,000-root safety snapshot')
        && str_contains($js, 'run Delete all matching again for the remainder'),
    'The cleanup job and UI must clearly identify matching cleanup and explain how to drain more than 10,000 roots safely.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
