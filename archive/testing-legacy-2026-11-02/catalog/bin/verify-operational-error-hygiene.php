#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies safeguards that keep expected operational states out of System Errors.
 * Role: Read-only source regression check for session startup, worker lifecycle and System Error maintenance UI.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$read = static fn(string $relative): string => (string)@file_get_contents(
    $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)
);
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$files = [
    'lib/CatalogSecurity.php',
    'bin/catalog-worker-detached.php',
    'src/Infrastructure/Jobs/CatalogWorkerPoolReconciler.php',
    'system-errors.php',
];
$syntaxFailures = [];
foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $syntaxFailures[] = $relative . ': ' . implode(' ', $out);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$security = $read('lib/CatalogSecurity.php');
$record(
    'session_ini_changes_are_guarded',
    str_contains($security, 'if (session_status() !== PHP_SESSION_ACTIVE)')
        && str_contains($security, "ini_set('session.use_strict_mode', '1')")
        && str_contains($security, "ini_set('session.use_only_cookies', '1')"),
    'Runtime safeguards must not attempt session ini changes after a PHP session is already active.'
);

$worker = $read('bin/catalog-worker-detached.php');
$record(
    'detached_worker_has_no_execution_timeout',
    str_contains($worker, "ini_set('max_execution_time', '0')")
        && str_contains($worker, 'set_time_limit(0)'),
    'Detached CLI workers must explicitly allow multi-hour durable jobs such as Full Sync.'
);

$reconciler = $read('src/Infrastructure/Jobs/CatalogWorkerPoolReconciler.php');
$record(
    'empty_queue_is_successful_noop',
    str_contains($reconciler, "'reason' => 'queue_empty'")
        && str_contains($reconciler, "'pool_satisfied' => true")
        && str_contains($reconciler, "'no_work' => true")
        && str_contains($reconciler, "\$queueCounts['queued'] === 0")
        && str_contains($reconciler, "\$queueCounts['running'] === 0"),
    'Start/resume on a genuinely empty queue must not produce worker_pool_incomplete.'
);

$systemErrors = $read('system-errors.php');
$record(
    'system_errors_support_permanent_delete',
    str_contains($systemErrors, "['resolve', 'ignore', 'reopen', 'delete']")
        && str_contains($systemErrors, "DELETE FROM ue_system_errors WHERE id IN (")
        && str_contains($systemErrors, '<option value="delete">Delete permanently</option>')
        && str_contains($systemErrors, 'Permanently delete the selected System Error records?'),
    'Selected System Error rows must have an explicit, confirmed permanent-delete action.'
);
$record(
    'system_error_mutations_remain_bounded',
    str_contains($systemErrors, 'if (count($ids) > 1000)')
        && str_contains($systemErrors, 'Update no more than 1,000 errors at once.'),
    'Resolve/ignore/reopen/delete operations remain bounded to 1,000 selected records.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
