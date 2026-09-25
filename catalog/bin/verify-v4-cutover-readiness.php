#!/usr/bin/env php
<?php
/**
 * Final operator gate for switching production to metadata format 4.
 *
 * Without --database it validates source/runtime contracts only.
 * With --database it also requires the live catalog to be fully current-format
 * and operationally ready. It is read-only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$withDatabase = in_array('--database', array_slice($argv, 1), true);

$commands = [
    [
        'name' => 'install_schema_baseline',
        'args' => ['verify-install-schema-baseline.php'],
        'database_args' => ['--database'],
    ],
    [
        'name' => 'v4_metadata_cutover',
        'args' => ['verify-v4-metadata-cutover.php'],
        'database_args' => ['--database'],
    ],
    [
        'name' => 'compact_only_runtime',
        'args' => ['verify-compact-only-metadata-runtime.php'],
        'database_args' => ['--database'],
    ],
    [
        'name' => 'compact_metadata_contention_retry',
        'args' => ['verify-compact-metadata-contention-retry.php'],
        'database_args' => [],
    ],
    [
        'name' => 'worker_startup_side_effect_boundary',
        'args' => ['verify-worker-startup-no-invalid-ue-backfill.php'],
        'database_args' => [],
    ],
    [
        'name' => 'system_readiness',
        'args' => ['verify-system-readiness-contract.php'],
        'database_args' => ['--run'],
    ],
];

if ($withDatabase) {
    $commands[] = [
        'name' => 'queue_runtime_invariants',
        'args' => ['verify-queue-runtime-invariants.php'],
        'database_args' => [],
    ];
}

$results = [];
$failures = [];

foreach ($commands as $definition) {
    $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $definition['args'][0];
    $argv = [PHP_BINARY, $script];
    if ($withDatabase) {
        foreach ($definition['database_args'] as $argument) {
            $argv[] = $argument;
        }
    }

    $pipes = [];
    $process = proc_open(
        $argv,
        [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        $results[] = [
            'check' => $definition['name'],
            'ok' => false,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'Could not start verifier.',
        ];
        $failures[] = $definition['name'];
        continue;
    }

    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $ok = $exit === 0;

    $results[] = [
        'check' => $definition['name'],
        'ok' => $ok,
        'exit_code' => $exit,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
    if (!$ok) {
        $failures[] = $definition['name'];
    }
}

echo json_encode([
    'ok' => $failures === [],
    'database_checked' => $withDatabase,
    'checks' => array_map(
        static fn(array $row): array => [
            'check' => $row['check'],
            'ok' => $row['ok'],
            'exit_code' => $row['exit_code'],
        ],
        $results
    ),
    'failures' => $failures,
    'details' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
