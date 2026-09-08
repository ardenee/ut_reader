#!/usr/bin/env php
<?php
/** Read-only contract for generated-package error observability and bounded MySQL log retention. */
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

$endpoint = $read('generated-package-job.php');
$page = $read('download-package.php');
$diagnostic = $read('bin/diagnose-generated-package.php');
$cleanup = $read('bin/cleanup-mysql-logs.php');

$checks = [
    'expected package planner rejection remains user-visible' =>
        str_contains($endpoint, 'if (get_class($error) === RuntimeException::class)')
        && str_contains($endpoint, "'error_type' => 'package_preflight_rejected'")
        && str_contains($endpoint, '], 409);'),
    'unexpected package endpoint failure records System Error' =>
        str_contains($endpoint, "catalog_system_error_record([")
        && str_contains($endpoint, "'source_kind' => 'generated-package'")
        && str_contains($endpoint, "'request_id' => $reference")
        && str_contains($endpoint, "'file_id' => max(0, (int)(\$_POST['file_id'] ?? 0))"),
    'unexpected package endpoint response carries request reference' =>
        str_contains($endpoint, "'Package generation is temporarily unavailable. Reference: ' . $reference")
        && str_contains($endpoint, "'request_id' => $reference"),
    'package page failures also record System Errors' =>
        str_contains($page, "'source_kind' => 'generated-package-page'")
        && str_contains($page, "'request_id' => $reference")
        && str_contains($page, "'file_id' => max(0, (int)(\$_GET['id'] ?? 0))"),
    'generated-package diagnostic is read-only planner preflight' =>
        str_contains($diagnostic, 'PdoCatalogPackageExportPlanner')
        && str_contains($diagnostic, "'would_queue'")
        && !str_contains($diagnostic, '->enqueue('),
    'MySQL cleanup is dry-run by default' =>
        str_contains($cleanup, '$apply = isset($options[\'apply\']);')
        && str_contains($cleanup, "'mode' => $apply ? 'apply' : 'dry_run'"),
    'MySQL cleanup purges through server SQL only on apply' =>
        str_contains($cleanup, "if ($apply && strtoupper((string)$variables['log_bin']) === 'ON')")
        && str_contains($cleanup, "'PURGE BINARY LOGS BEFORE '")
        && !str_contains($cleanup, 'unlink('),
    'MySQL cleanup persists bounded expiry' =>
        str_contains($cleanup, "'SET PERSIST binlog_expire_logs_seconds='")
        && str_contains($cleanup, "'SET GLOBAL binlog_expire_logs_seconds='")
        && str_contains($cleanup, 'my.ini'),
    'MySQL cleanup guards detected replication' =>
        str_contains($cleanup, 'function mysql_replication_state(')
        && str_contains($cleanup, 'Replication was detected. Refusing to purge binary logs without --force.'),
    'MySQL cleanup reports other SQL log sources' =>
        str_contains($cleanup, "'general_log'")
        && str_contains($cleanup, "'slow_query_log'")
        && str_contains($cleanup, "'log_output'"),
];

$failures = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failures[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'generated-package-job.php',
    'download-package.php',
    'bin/diagnose-generated-package.php',
    'bin/cleanup-mysql-logs.php',
    __FILE__,
] as $relative) {
    $file = $relative === __FILE__
        ? __FILE__
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not lint';
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
if ($syntaxFailures !== []) {
    $failures[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

$result = [
    'ok' => $failures === [],
    'checks' => count($checks) + 1,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
