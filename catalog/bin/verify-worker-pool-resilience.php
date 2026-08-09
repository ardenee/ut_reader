#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies detached-worker pool self-healing and accurate partial-pool reporting.
 * Role: Read-only worker architecture regression test.
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
    'src/Application/Jobs/CatalogWorkerStatusPolicy.php',
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
$policy = $read('src/Application/Jobs/CatalogWorkerStatusPolicy.php');
$bridge = $read('assets/background-jobs-cursor-bridge.js');
$record(
    'cli_no_session',
    str_contains($worker, 'catalog_bootstrap(false)') && !str_contains($worker, '$application = catalog_bootstrap();'),
    'detached workers do not start browser sessions'
);
$record(
    'survivor_self_heal',
    str_contains($worker, 'CatalogWorkerPoolSelfHealer')
        && str_contains($worker, '$poolHealer->heal($queueName, $maxJobs)')
        && str_contains($healer, 'LOCK_EX | LOCK_NB')
        && str_contains($healer, 'status IN ("queued","running")')
        && str_contains($healer, '$launcher->start($queueName, $maxJobs, $desired)'),
    'surviving workers reconcile missing slots under a non-blocking single-healer lock'
);
$record(
    'degraded_ui_contract',
    str_contains($policy, "'authoritative_status' => 'degraded'")
        && str_contains($bridge, "authority === 'degraded'")
        && str_contains($bridge, 'Worker pool degraded')
        && str_contains($bridge, 'active jobs'),
    'partial worker processes are reported separately from running database jobs'
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
