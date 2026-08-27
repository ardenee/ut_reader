#!/usr/bin/env php
<?php
/** Read-only source contract for archive outcome reporting and browser error noise suppression. */
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

$projector = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$statusApi = (string)@file_get_contents($root . '/api/v1/job-status.php');
$cursorApi = (string)@file_get_contents($root . '/api/v1/job-status-cursor.php');
$fileTreeApi = (string)@file_get_contents($root . '/api/v1/job-file-tree.php');
$trace = (string)@file_get_contents($root . '/bin/trace-archive-job.php');
$browserErrors = (string)@file_get_contents($root . '/assets/catalog-system-errors.js');
$jobsPage = (string)@file_get_contents($root . '/background-jobs.php');

$record(
    'archive_projector_imports_domain_job_type',
    str_contains($projector, 'use UnrealDb\\Catalog\\Domain\\Jobs\\JobType;'),
    'The archive outcome projector must import the domain JobType class; an unqualified missing import crashes live job-status APIs when an archive row is projected.'
);

$record(
    'archive_parent_reports_child_outcomes',
    str_contains($projector, 'Archive processing complete: ')
        && str_contains($projector, "'successful'")
        && str_contains($projector, "'duplicate'")
        && str_contains($projector, "'failed'")
        && str_contains($projector, "'waiting'")
        && str_contains($projector, "'running'"),
    'Visible archive parents must describe final hidden-member outcomes instead of stopping at an enqueue count.'
);

$record(
    'archive_summary_includes_parent_extraction_failures',
    str_contains($projector, "\$parentResult['failed_files']")
        && str_contains($projector, "\$parentProgress['failed']")
        && str_contains($projector, "\$summary['archive_member_failed']")
        && str_contains($projector, "\$summary['child_failed']")
        && str_contains($projector, "\$summary['total_failed']")
        && str_contains($projector, 'number_format($totalFailed) . \' failed\''),
    'The visible failed count must combine archive-expansion failures retained by the parent with downstream child-job failures, while preserving both component counts for diagnostics.'
);

$record(
    'archive_child_failures_keep_member_identity',
    str_contains($projector, 'payload_json')
        && str_contains($projector, "\$payload['archive_entry_path']")
        && str_contains($projector, "\$payload['original_name']")
        && str_contains($projector, "'failures' => []")
        && str_contains($projector, "'member' => \$member")
        && str_contains($projector, "'job_id' => max(0, \$jobId)")
        && str_contains($projector, 'Failed member(s): '),
    'A retained archive must show the failed child member path, child job id and actual error rather than only an aggregate failed count.'
);

$record(
    'background_jobs_prioritizes_full_source_path',
    str_contains($jobsPage, '.jobs-type,.jobs-col-type,.jobs-table thead th:nth-child(4){display:none!important}')
        && str_contains($jobsPage, '.jobs-target{min-width:0;max-width:none;overflow:visible;text-overflow:clip;white-space:normal!important;')
        && str_contains($jobsPage, '<th scope="col">Full source path</th>')
        && str_contains($jobsPage, 'placeholder="File path, job ID or error"'),
    'The operator table must hide the implementation job-type column and give the complete recorded source path the reclaimed width without ellipsis.'
);

$record(
    'all_background_job_apis_apply_archive_projection',
    str_contains($statusApi, 'new CatalogArchiveJobOutcomeProjector($application->db)')
        && str_contains($cursorApi, 'new CatalogArchiveJobOutcomeProjector($application->db)')
        && str_contains($fileTreeApi, 'new CatalogArchiveJobOutcomeProjector($application->db)'),
    'Offset, cursor and file-tree Background Jobs endpoints must expose the same archive child-outcome detail.'
);

$record(
    'job_status_failures_persist_real_exception_details',
    str_contains($statusApi, "'error_type' => get_class(\$exception)")
        && str_contains($cursorApi, "'error_type' => get_class(\$exception)")
        && str_contains($statusApi, "'source_file' => \$exception->getFile()")
        && str_contains($cursorApi, "'source_file' => \$exception->getFile()")
        && str_contains($statusApi, "'trace_text' => \$exception->getTraceAsString()")
        && str_contains($cursorApi, "'trace_text' => \$exception->getTraceAsString()")
        && str_contains($statusApi, 'catalog_system_error_record([')
        && str_contains($cursorApi, 'catalog_system_error_record(['),
    'A future 503 must persist its underlying exception class/message/file/line/trace instead of leaving only the generic jobs-service-unavailable record.'
);

$record(
    'archive_projection_is_read_only',
    !preg_match('/\b(?:UPDATE|DELETE|INSERT)\s+ue_background_jobs\b/i', $projector)
        && str_contains($projector, 'SELECT id,parent_job_id,status,result_json,last_error,cancel_reason,payload_json '),
    'Archive reporting must derive outcomes and member identity without mutating job history.'
);

$record(
    'trace_separates_queue_wait_from_claimed_runtime',
    str_contains($trace, "'leased_at'")
        && str_contains($trace, "'queue_wait_seconds'")
        && str_contains($trace, "'claimed_runtime_seconds'"),
    'Archive diagnostics must not confuse time waiting for a worker with execution time.'
);

$record(
    'trace_does_not_duplicate_storage_segment',
    str_contains($trace, 'dirname($storageRoot) . DIRECTORY_SEPARATOR . $relativePath')
        && str_contains($trace, 'ue_files.relative_path is catalog-root relative'),
    'A DB relative_path beginning with storage/ must be resolved from the catalog root, not appended to storage_path again.'
);

$record(
    'transient_blob_resources_are_not_system_errors',
    str_contains($browserErrors, 'shouldIgnoreResourceError')
        && str_contains($browserErrors, '/^(?:blob:|data:)/i.test(value)')
        && str_contains($browserErrors, 'if (shouldIgnoreResourceError(source)) return;'),
    'Generated blob:/data: object URLs are short-lived local resources and must not create non-actionable resource_load_error records.'
);

$phpFiles = [
    $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    $root . '/api/v1/job-status.php',
    $root . '/api/v1/job-status-cursor.php',
    $root . '/bin/trace-archive-job.php',
    $root . '/background-jobs.php',
    __FILE__,
];
$syntaxFailures = [];
foreach ($phpFiles as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
