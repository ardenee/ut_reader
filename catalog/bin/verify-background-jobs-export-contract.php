#!/usr/bin/env php
<?php
/** Read-only contract for the file-centric Background Jobs Markdown export. */
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

$page = $read('background-jobs.php');
$client = $read('assets/background-jobs-files.js');
$export = $read('background-jobs-export.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'background_jobs_has_export_control',
    str_contains($page, 'id="jobs-file-export"')
        && str_contains($page, 'background-jobs-export.php'),
    'The file-centric Background Jobs page must expose a direct export control.'
);

$record(
    'export_link_tracks_live_filters',
    str_contains($client, "document.getElementById('jobs-file-export')")
        && str_contains($client, "exportParams.set('job_type', state.jobType)")
        && str_contains($client, "exportParams.set('search', state.search)")
        && str_contains($client, 'state: state.filter')
        && str_contains($client, "exportLink.href = 'background-jobs-export.php?'"),
    'Export must use the active queue/state/job-type/search filters rather than exporting an unrelated default view.'
);

$record(
    'export_is_admin_only',
    str_contains($export, "catalog_require_admin_page('Background Jobs Export')"),
    'Background Jobs diagnostics contain internal file/job details and must remain admin-only.'
);

$record(
    'export_uses_same_file_read_model',
    str_contains($export, 'new PdoBackgroundJobFileTreeQuery($db)')
        && str_contains($export, 'new CatalogBackgroundJobResultHydrator($config)')
        && str_contains($export, 'new CatalogArchiveJobOutcomeProjector($db)')
        && str_contains($export, 'new CatalogBackgroundJobFileTreeProjector()'),
    'The export must project exactly the same job/archive semantics as the on-screen file view.'
);

$record(
    'export_includes_descendant_job_tree',
    str_contains($export, 'function background_jobs_export_tree(')
        && str_contains($export, '$query->children($queue, $id, $page, 500)')
        && str_contains($export, '$depth + 1')
        && str_contains($export, "'- Parent job: #'")
        && str_contains($export, "'- Tree depth: '")
        && str_contains($export, 'Exported rows including descendants:'),
    'Nested archive/member failures must be exported recursively instead of stopping at aggregate root summaries.'
);

$record(
    'export_contains_copyable_diagnostics',
    str_contains($export, "'## Job #'")
        && str_contains($export, '**Issue**')
        && str_contains($export, '**Activity**')
        && str_contains($export, '**Result message**')
        && str_contains($export, '**Progress message**')
        && str_contains($export, '**Last error**')
        && str_contains($export, "'- File: '")
        && str_contains($export, "'- Path: '")
        && str_contains($export, "'- Job type: '")
        && str_contains($export, "'- Queue status: '")
        && str_contains($export, "'- Display status: '"),
    'The Markdown report must preserve the details that are difficult to copy from the interactive table.'
);

$record(
    'export_is_bounded_markdown_download',
    str_contains($export, '$limit = 20000;')
        && str_contains($export, "Content-Type: text/markdown")
        && str_contains($export, 'Content-Disposition: attachment')
        && str_contains($export, 'Matching rows:')
        && str_contains($export, 'Exported rows including descendants:'),
    'The export must be a bounded downloadable Markdown report with explicit result counts.'
);

$syntaxFailures = [];
foreach ([
    $root . '/background-jobs-export.php',
    $root . '/background-jobs.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
