#!/usr/bin/env php
<?php
/** Read-only contract verifier for nested ZIP/RAR/7z archive ingestion. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$helperPath = $root . '/src/Infrastructure/Jobs/CatalogNestedArchiveJobEnqueuer.php';
$workflowPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveWorkflowJobHandler.php';
$workerVersionPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';
$helper = (string)@file_get_contents($helperPath);
$workflow = (string)@file_get_contents($workflowPath);
$workerVersion = (string)@file_get_contents($workerVersionPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'nested_formats_are_zip_rar_7z',
    str_contains($helper, "private const RECURSIVE_EXTENSIONS = ['zip', 'rar', '7z'];"),
    'Embedded ZIP, RAR and 7z members must be recognized as recursive archive workflows.'
);

$record(
    'nested_archives_become_archive_child_jobs',
    str_contains($helper, '$queue->enqueue(')
        && str_contains($helper, '$job->type,')
        && str_contains($helper, "'nested_archive' => true")
        && str_contains($helper, "'archive_parent_job_id' => \$job->id")
        && str_contains($helper, "'archive_depth' => \$depth + 1")
        && str_contains($helper, "'archive_root_job_id' => \$rootJobId")
        && str_contains($helper, "'archive:' . hash('sha256', strtolower(\$entryPath))"),
    'A nested archive must enter the durable archive workflow as a child of its containing archive, not recurse on the same PHP stack.'
);

$record(
    'normal_and_sequential_archive_paths_are_supported',
    str_contains($helper, 'new CatalogArchiveExtractor($this->config)')
        && str_contains($helper, 'new CatalogSequentialArchiveReader($this->config)')
        && str_contains($helper, '$sequential->shouldUse($sourcePath, $originalName)')
        && str_contains($helper, '$reader->walk('),
    'Nested discovery must work for ordinary random-access archives and the sequential ZIP/RAR/7z compatibility path.'
);

$record(
    'nesting_has_bounded_depth',
    str_contains($helper, 'DEFAULT_MAX_NESTING_DEPTH = 4')
        && str_contains($helper, 'MAX_CONFIGURED_NESTING_DEPTH = 16')
        && str_contains($helper, 'UNREALDB_ARCHIVE_MAX_NESTING_DEPTH')
        && str_contains($helper, "\$this->config['archive']['max_nesting_depth']")
        && str_contains($helper, 'if ($depth >= $maxDepth)'),
    'Recursive archive imports must have a configurable, hard-bounded nesting depth; the default must cover the reported three-level archives.'
);

$record(
    'nested_extraction_keeps_existing_size_limits',
    str_contains($helper, '$this->containerLimitBytes()')
        && str_contains($helper, '$this->maxTotalUnpackedBytes()')
        && str_contains($helper, 'UNREALDB_ARCHIVE_MAX_UNPACKED_BYTES')
        && str_contains($helper, 'Nested archive extraction exceeds the configured per-archive unpacked-data limit'),
    'Nested archive members must retain the same per-member and per-archive unpacked-byte protections as top-level archive ingestion.'
);

$record(
    'workflow_discovers_nested_before_existing_extractor',
    str_contains($workflow, 'new CatalogNestedArchiveJobEnqueuer($db, $config)')
        && str_contains($workflow, '$nestedResult = $this->nestedArchives->enqueue($ownedJob, $context);')
        && str_contains($workflow, '$archiveResult = $this->extractor->handle($ownedJob, $context);')
        && strpos($workflow, '$nestedResult = $this->nestedArchives->enqueue($ownedJob, $context);')
            < strpos($workflow, '$archiveResult = $this->extractor->handle($ownedJob, $context);')
        && str_contains($workflow, 'mergeNestedArchiveResult'),
    'The job-owned archive must queue nested containers before the unchanged package-member extractor performs its normal pass.'
);

$record(
    'nested_members_are_not_reported_as_unsupported_after_queueing',
    str_contains($workflow, "\$archiveResult['skipped_files'] = max(0, (int)(\$archiveResult['skipped_files'] ?? 0) - \$handled);")
        && str_contains($workflow, "\$archiveResult['nested_archive_jobs'] = \$nestedChildren;")
        && str_contains($workflow, "\$archiveResult['failed_files']")
        && str_contains($workflow, "\$nestedResult['failed']"),
    'Members taken over by nested archive workflows must be removed from the old unsupported/skipped count and nested failures must remain visible.'
);

$record(
    'worker_fingerprint_tracks_nested_archive_code',
    str_contains($workerVersion, "/src/Infrastructure/Jobs/CatalogNestedArchiveJobEnqueuer.php'"),
    'Detached workers must be reconciled after nested archive ingestion code changes.'
);

$record(
    'no_external_archive_processes',
    preg_match('/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i', $helper) !== 1,
    'Nested archive handling must stay entirely in-process PHP.'
);

$syntaxFailures = [];
foreach ([$helperPath, $workflowPath, $workerVersionPath, __FILE__] as $path) {
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
