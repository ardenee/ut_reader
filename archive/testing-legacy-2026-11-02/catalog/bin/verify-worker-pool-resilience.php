#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies detached-worker pool self-healing, accurate partial-pool reporting and bounded live status reads.
 * Role: Read-only worker architecture/performance regression test.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$read = static fn(string $file): string => (string)@file_get_contents($root . '/' . $file);
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$files = [
    'bin/catalog-worker-detached.php',
    'src/Infrastructure/Jobs/CatalogWorkerPoolSelfHealer.php',
    'src/Infrastructure/Jobs/CatalogWorkerProcessLauncher.php',
    'src/Application/Jobs/CatalogWorkerStatusPolicy.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php',
];
$syntax = [];
foreach ($files as $file) {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $out, $code);
    if ($code !== 0) $syntax[] = $file . ': ' . implode(' ', $out);
}
$record('php_syntax', $syntax === [], implode(' | ', $syntax));

$worker = $read('bin/catalog-worker-detached.php');
$healer = $read('src/Infrastructure/Jobs/CatalogWorkerPoolSelfHealer.php');
$launcher = $read('src/Infrastructure/Jobs/CatalogWorkerProcessLauncher.php');
$policy = $read('src/Application/Jobs/CatalogWorkerStatusPolicy.php');
$operational = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php');
$bridge = $read('assets/background-jobs-cursor-bridge.js');
$record(
    'cli_no_session',
    str_contains($worker, 'catalog_bootstrap(false)') && !str_contains($worker, '$application = catalog_bootstrap();'),
    'detached workers do not start browser sessions'
);
$record(
    'worker_php_path_is_portable',
    str_contains($launcher, '$configured = trim((string)($this->config[\'queue\'][\'worker_php_binary\'] ?? \'\'));')
        && str_contains($launcher, 'foreach ([$configured, $environment] as $preferred)')
        && str_contains($launcher, '$resolved = $this->resolveExecutable($preferred);')
        && str_contains($launcher, 'php_ini_loaded_file()')
        && str_contains($launcher, 'PHP_BINDIR')
        && str_contains($launcher, 'PHP_BINARY')
        && str_contains($launcher, '$this->pathDirectories()')
        && !str_contains($launcher, 'for example ')
        && str_contains($launcher, 'Leave queue.worker_php_binary empty for automatic detection'),
    'A stale host-specific worker PHP override must fall back to the current PHP runtime/PATH instead of preventing detached workers from starting.'
);

$record(
    'survivor_self_heal',
    str_contains($worker, 'CatalogWorkerPoolSelfHealer')
        && str_contains($worker, '$poolHealer->heal($queueName, $maxJobs)')
        && str_contains($healer, 'LOCK_EX | LOCK_NB')
        && str_contains($healer, 'status="queued" AND cancel_requested_at IS NULL')
        && str_contains($healer, 'available_at<=UTC_TIMESTAMP()')
        && str_contains($healer, '$launcher->start($queueName, $maxJobs, $desired)'),
    'surviving workers reconcile missing slots only when ready queued work exists, under a non-blocking single-healer lock'
);
$record(
    'degraded_ui_contract',
    str_contains($policy, "'authoritative_status' => 'degraded'")
        && str_contains($bridge, "authority === 'degraded'")
        && str_contains($bridge, 'Worker pool degraded')
        && str_contains($bridge, 'active jobs'),
    'partial worker processes are reported separately from running database jobs'
);
$record(
    'live_status_single_pass',
    str_contains($operational, 'COALESCE(SUM(status="queued"),0) queued')
        && str_contains($operational, 'COALESCE(SUM(status="running"),0) running')
        && str_contains($operational, 'COALESCE(SUM(status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()),0) ready')
        && str_contains($operational, 'status IN ("queued","running")')
        && !str_contains($operational, '$queued = $this->statusCount($queueName, \'queued\');')
        && !str_contains($operational, '$running = $this->statusCount($queueName, \'running\');'),
    'two-second worker status polling collects queued/running/ready values in one indexed aggregate pass'
);

require_once $root . '/bootstrap/autoload.php';
$case = \UnrealDb\Catalog\Application\Jobs\CatalogWorkerStatusPolicy::evaluate(
    [
        'active' => true,
        'active_count' => 2,
        'launching_count' => 0,
        'desired_count' => 4,
        'state' => ['status' => 'running', 'updated_at' => gmdate('c')],
    ],
    ['queued' => 20, 'ready' => 20, 'running' => 1, 'terminal' => 0, 'total' => 21],
    4
);
$record(
    'degraded_policy_contract',
    ($case['authoritative_status'] ?? '') === 'degraded' && empty($case['restart_recommended']),
    '2/4 active workers with ready work is degraded and self-healing, not falsely healthy'
);
$orphaned = \UnrealDb\Catalog\Application\Jobs\CatalogWorkerStatusPolicy::evaluate(
    ['active' => false, 'active_count' => 0, 'desired_count' => 4, 'state' => []],
    ['queued' => 0, 'ready' => 0, 'running' => 2, 'terminal' => 0, 'total' => 2],
    4
);
$record('orphan_contract', ($orphaned['authoritative_status'] ?? '') === 'orphaned', 'orphaned DB-running jobs remain distinguishable');

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
