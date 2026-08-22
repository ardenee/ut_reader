#!/usr/bin/env php
<?php
/** Read-only contract verifier for terminal archive partial-outcome reporting. */
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

$projector = $read('src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php');
$display = $read('src/Infrastructure/Jobs/CatalogJobDisplayStatus.php');
$counts = $read('src/Infrastructure/Persistence/PdoBackgroundJobDisplayCountQuery.php');
$ui = $read('assets/background-jobs-stable.js');
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'partial_archive_projects_partial_badge',
    str_contains($projector, "\$row['display_status'] = 'partial';")
        && str_contains($projector, "\$row['result']['status'] = 'partial';")
        && str_contains($projector, "\$row['progress']['status'] = 'partial';"),
    'A terminal archive with failed/cancelled children must expose partial as its operator-visible status, not completed.'
);

$record(
    'completed_filter_excludes_partial_archives',
    str_contains($display, '$prefix . \'display_status="partial" AND \'')
        && str_contains($display, 'JobType::PROCESS_BUCKET_ARCHIVE')
        && str_contains($display, 'JobType::IMPORT_STAGED_ARCHIVE')
        && str_contains($display, 'AND NOT ('),
    'Completed must mean a successful logical archive outcome; retained partial archives have their own operator filter.'
);

$record(
    'partial_archive_count_is_not_double_counted_completed',
    str_contains($counts, "\$counts['partial_archive'] += \$amount;")
        && str_contains($counts, 'continue;')
        && str_contains($counts, '$displayStatus === \'partial\''),
    'A partial archive must count once under retained archives/needs attention rather than also inflating Completed.'
);

$record(
    'background_jobs_prefers_display_status',
    str_contains($ui, 'job.display_status || job.status || \'unknown\'')
        && str_contains($ui, "'partial_archive'"),
    'The Background Jobs badge must use the projected logical status and expose the retained-archive filter.'
);

$syntaxFailures = [];
foreach ([
    $root . '/src/Infrastructure/Jobs/CatalogArchiveJobOutcomeProjector.php',
    $root . '/src/Infrastructure/Jobs/CatalogJobDisplayStatus.php',
    $root . '/src/Infrastructure/Persistence/PdoBackgroundJobDisplayCountQuery.php',
    __FILE__,
] as $path) {
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
