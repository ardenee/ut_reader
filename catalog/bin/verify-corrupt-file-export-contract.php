#!/usr/bin/env php
<?php
/** Verifies corrupt/non-retryable source export and path provenance. */
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
    $data = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($data) ? $data : '';
};

$phpFiles = [
    'corrupt-files-export.php',
    'src/Application/Jobs/JobFailureRetryPolicy.php',
    'src/Infrastructure/Jobs/CatalogCorruptSourceExportQuery.php',
    'src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
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
    if ($exit !== 0) $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$policy = $read('src/Application/Jobs/JobFailureRetryPolicy.php');
$query = $read('src/Infrastructure/Jobs/CatalogCorruptSourceExportQuery.php');
$resolver = $read('src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$page = $read('background-jobs.php');
$jobsJs = $read('assets/background-jobs-files.js');
$errorsJs = $read('assets/catalog-system-errors.js');
$export = $read('corrupt-files-export.php');

$record(
    'corrupt_classifier_excludes_missing_sources',
    str_contains($policy, 'public static function isCorruptContentText')
        && str_contains($policy, 'public static function isMissingSourceFailureText')
        && str_contains($policy, 'self::isMissingSourceFailureText($message)'),
    'The export must distinguish corrupt bytes from jobs that are non-retryable only because their source is missing.'
);

$record(
    'operator_retry_and_export_share_missing_source_policy',
    str_contains($bulk, 'JobFailureRetryPolicy::isMissingSourceFailureText($failureText)')
        && !str_contains($bulk, 'private static function isMissingSourceFailureText'),
    'Retry and corrupt-file export must not drift into separate missing-source definitions.'
);

$record(
    'direct_chunk_uploads_resolve_to_full_paths',
    str_contains($resolver, 'CatalogChunkedUploadStore')
        && str_contains($resolver, 'applyCompletedChunkSource')
        && str_contains($resolver, "'job_full_path'")
        && str_contains($resolver, "\$context['job_source_storage'] = 'chunk-upload'"),
    'Direct browser uploads must expose the retained completed chunk path when it still exists.'
);

$record(
    'export_keeps_archive_member_and_container_distinct',
    str_contains($query, "'archive_container_path'")
        && str_contains($query, "'archive_entry_path'")
        && str_contains($query, "'archive_member_only'")
        && str_contains($query, "'copy_path'"),
    'A corrupt archive member must not misleadingly identify the whole containing archive as the standalone file to copy/remove.'
);

$record(
    'csv_contains_copyable_path_and_provenance',
    str_contains($export, "'copy_path'")
        && str_contains($export, "'destination_relative_path'")
        && str_contains($export, "'source_relative_path'")
        && str_contains($export, "'reason'")
        && str_contains($export, "'classification'")
        && str_contains($export, 'text/csv'),
    'CSV must lead with the resolved copy path and include a collision-safe original destination path plus corruption reason.'
);

$record(
    'open_corrupt_system_errors_are_merged',
    str_contains($query, 'openInvalidUeSystemErrors')
        && str_contains($query, 'source_kind IN ("unreal-file-validation","background-job")')
        && str_contains($query, "$disposition === 'invalid_ue_file'")
        && str_contains($query, 'JobFailureRetryPolicy::isCorruptContentText($jobType, $reason)')
        && str_contains($query, 'systemErrorReason')
        && str_contains($query, 'projectSystemErrorOnly'),
    'Corrupt-file export must merge both invalid-UE and background-job System Errors when the shared policy classifies the immutable content as corrupt.'
);

$record(
    'zero_to_space_corruption_is_exportable_corrupt_content',
    str_contains(
        $policy,
        "'unreal package appears to have nul bytes replaced with spaces throughout the payload'"
    ),
    'The known NUL-to-space corruption diagnosis must be part of the deterministic corrupt-content policy.'
);

$record(
    'both_operator_pages_expose_corrupt_export',
    str_contains($page, 'jobs-corrupt-export')
        && str_contains($jobsJs, 'corrupt-files-export.php?')
        && str_contains($errorsJs, "corruptLink.textContent = 'Export corrupt files'")
        && str_contains($errorsJs, "corruptLink.href = 'corrupt-files-export.php'"),
    'Background Jobs and System Errors must both expose the same corrupt-source export.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
