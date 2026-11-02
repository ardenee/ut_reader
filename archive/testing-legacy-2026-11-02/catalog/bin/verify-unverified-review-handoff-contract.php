#!/usr/bin/env php
<?php
/**
 * Verifies that successful handoff to Unverified Files is a completed review
 * outcome rather than a Background Jobs issue/failure.
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
    if (!$ok) $failures[] = $name . ': ' . $detail;
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$files = [
    'src/Infrastructure/Jobs/CatalogJobDisplayStatus.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
    'src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php',
    'src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    'src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php',
    'src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php',
    'src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php',
];
$syntaxFailures = [];
foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = $relative . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$display = $read('src/Infrastructure/Jobs/CatalogJobDisplayStatus.php');
$fileTree = $read('src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php');
$query = $read('src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$children = $read('src/Infrastructure/Persistence/PdoArchiveChildOutcomeQuery.php');
$archiveProjector = $read('src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$workflow = $read('src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php');
$cleanup = $read('src/Infrastructure/Jobs/CatalogBackgroundJobHistoryCleanupQueue.php');
$publicUpload = $read('src/Infrastructure/Jobs/CatalogPublicUploadJobHandler.php');

$record(
    'public_upload_handoff_is_explicitly_unverified_review',
    str_contains($publicUpload, "'status' => 'unverified'")
        && str_contains($publicUpload, 'Public contribution staged as unverified file #')
        && str_contains($publicUpload, 'for administrator review.'),
    'A validated public contribution must deliberately terminate by handing the file to Unverified Files for administrator review.'
);

$record(
    'unverified_is_not_a_failed_display_outcome',
    str_contains($display, "private const FAILED_OUTCOMES = ['failed', 'rejected', 'invalid_ue_package']")
        && str_contains($display, 'display_status IN ("failed","rejected","invalid_ue_package")')
        && str_contains($display, 'display_status NOT IN ("failed","rejected","invalid_ue_package")'),
    'Completed display_status=unverified must group with Completed, not Failed.'
);

$record(
    'file_tree_does_not_count_unverified_as_issue',
    str_contains($query, "ISSUE_DISPLAY_STATUSES = '\"failed\",\"rejected\",\"invalid_ue_package\",\"partial\",\"error\"'")
        && str_contains($fileTree, "ISSUE_DISPLAY_STATUSES = ['failed', 'rejected', 'invalid_ue_package', 'partial', 'error']")
        && str_contains($fileTree, "'unverified', 'unverified_profile_mismatch' => 'Stored in Unverified'")
        && str_contains($fileTree, "'unverified' => 'Unverified · review'"),
    'Background Jobs must show successful Unverified handoff as Completed / Stored in Unverified rather than Could not process.'
);

$record(
    'unverified_review_is_not_bulk_retry_candidate',
    !str_contains($bulk, 'display_status IN ("failed","rejected","unverified","invalid_ue_package")')
        && str_contains($bulk, 'display_status IN ("failed","rejected","invalid_ue_package")'),
    'Retry controls must not send already-staged Unverified files back through the job pipeline; review/import belongs on Unverified Files.'
);

$record(
    'retained_archive_problem_retry_leaves_unverified_children_terminal',
    !str_contains($bulk, 'display_status IN ("failed","rejected","unverified","partial","error")')
        && str_contains($bulk, 'display_status IN ("failed","rejected","partial","error")'),
    'Retrying a retained partial archive must not reactivate children already handed off to Unverified Files.'
);

$record(
    'archive_children_treat_unverified_as_review_not_failure',
    str_contains($children, "['unverified', 'unverified_profile_mismatch']")
        && str_contains($children, "['failed', 'partial', 'error']")
        && !str_contains($children, "['failed', 'unverified', 'partial', 'error']")
        && str_contains($archiveProjector, "['unverified', 'unverified_profile_mismatch']")
        && str_contains($archiveProjector, "['failed', 'partial', 'error']")
        && str_contains($workflow, ' unverified/review, '),
    'An archive member handed to Unverified Files must not make an otherwise healthy archive partial/failed.'
);

$record(
    'resolved_unverified_job_history_is_retention_eligible',
    str_contains($cleanup, 'NOT IN ("failed","rejected","partial","error")')
        && !str_contains($cleanup, 'NOT IN ("failed","rejected","unverified","partial","error")'),
    'Once the file is durably owned by Unverified Files, its completed background-job history may use normal retention cleanup.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
