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
$trace = (string)@file_get_contents($root . '/bin/trace-archive-job.php');
$browserErrors = (string)@file_get_contents($root . '/assets/catalog-system-errors.js');

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
    'both_job_status_apis_apply_archive_projection',
    str_contains($statusApi, 'new CatalogArchiveJobOutcomeProjector($application->db)')
        && str_contains($cursorApi, 'new CatalogArchiveJobOutcomeProjector($application->db)'),
    'Both offset and cursor Background Jobs endpoints must expose the same archive outcome projection.'
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
        && str_contains($projector, 'SELECT parent_job_id,status,result_json,last_error'),
    'Archive reporting must derive outcomes without mutating job history.'
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
