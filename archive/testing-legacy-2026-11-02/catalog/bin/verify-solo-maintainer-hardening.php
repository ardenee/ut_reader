#!/usr/bin/env php
<?php
/**
 * Umbrella verifier for the P0-P5 solo-maintainer production hardening pass.
 *
 * --run additionally exercises bounded current-job/operator reporting, admin read
 * models and configured database/queue/storage health. It does not create a
 * backup or perform any destructive action.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if (!function_exists('proc_open')) {
    fwrite(STDERR, "proc_open is required.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$run = in_array('--run', array_slice($argv, 1), true);
$definitions = [
    ['phase' => 'P0', 'script' => 'verify-maintainability-guardrails.php', 'run' => false],
    ['phase' => 'P1', 'script' => 'verify-system-operations-contract.php', 'run' => $run],
    ['phase' => 'P1', 'script' => 'verify-background-jobs-count-scope-contract.php', 'run' => false],
    ['phase' => 'P2', 'script' => 'verify-read-model-boundaries.php', 'run' => false],
    ['phase' => 'P3', 'script' => 'verify-package-storage-boundary.php', 'run' => false],
    ['phase' => 'P4', 'script' => 'verify-windows-backup-recovery-contract.php', 'run' => false],
    ['phase' => 'P5', 'script' => 'verify-frontend-js-modules.php', 'run' => false],
    ['phase' => 'operations', 'script' => 'verify-operator-reporting-contract.php', 'run' => $run],
    ['phase' => 'operations', 'script' => 'verify-admin-runtime-smoke.php', 'run' => $run],
    ['phase' => 'baseline', 'script' => 'verify-clean-architecture.php', 'run' => false],
    ['phase' => 'baseline', 'script' => 'verify-performance-contract.php', 'run' => false],
    ['phase' => 'baseline', 'script' => 'verify-production-recovery-contract.php', 'run' => false],
];

$results = [];
$failures = [];
foreach ($definitions as $definition) {
    $path = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $definition['script'];
    if (!is_file($path)) {
        $results[] = [
            'phase' => $definition['phase'],
            'script' => $definition['script'],
            'ok' => false,
            'detail' => 'Verifier is missing.',
        ];
        $failures[] = $definition['script'] . ': missing';
        continue;
    }

    $command = [PHP_BINARY, $path];
    if ($definition['run']) $command[] = '--run';
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        $results[] = [
            'phase' => $definition['phase'],
            'script' => $definition['script'],
            'ok' => false,
            'detail' => 'Could not start verifier.',
        ];
        $failures[] = $definition['script'] . ': could not start';
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    $ok = $exit === 0 && is_array($decoded) && ($decoded['ok'] ?? false) === true;
    $detail = '';
    if (!$ok) {
        if (is_array($decoded) && is_array($decoded['failures'] ?? null) && $decoded['failures'] !== []) {
            $detail = implode(' | ', array_map('strval', $decoded['failures']));
        } elseif (trim((string)$stderr) !== '') {
            $detail = trim((string)$stderr);
        } else {
            $detail = trim((string)$stdout);
        }
        $failures[] = $definition['script'] . ($detail !== '' ? ': ' . $detail : '');
    }
    $results[] = [
        'phase' => $definition['phase'],
        'script' => $definition['script'],
        'ok' => $ok,
        'runtime_checked' => (bool)$definition['run'],
        'detail' => $detail,
    ];
}

$result = [
    'ok' => $failures === [],
    'runtime_checked' => $run,
    'checks' => $results,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
