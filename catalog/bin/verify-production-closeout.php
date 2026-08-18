#!/usr/bin/env php
<?php
/**
 * Purpose: Runs the final read-only production regression suite for the August 2026 architecture/performance close-out.
 * Role: One command covering syntax, runtime prerequisites, architecture, compact metadata, unverified staging, federation, workers, upload/import and optional live DB/runtime reads.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$withDatabase = in_array('--database', array_slice($argv, 1), true);
$checks = [];
$failures = [];

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$run = static function (array $command, string $cwd): array {
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'exit' => 127, 'output' => 'proc_open is unavailable'];
    }
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return ['ok' => false, 'exit' => 127, 'output' => 'process could not be started'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return [
        'ok' => $exit === 0,
        'exit' => $exit,
        'output' => trim((string)$stdout . ((string)$stderr !== '' ? "\n" . (string)$stderr : '')),
    ];
};

$syntaxFailures = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $item) {
    if (!$item instanceof SplFileInfo || !$item->isFile() || strtolower($item->getExtension()) !== 'php') continue;
    $path = $item->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (str_starts_with($relative, 'storage/') || str_starts_with($relative, 'vendor/')) continue;
    $result = $run([PHP_BINARY, '-l', $path], $root);
    if (!$result['ok']) {
        $syntaxFailures[] = $relative . ': ' . substr((string)$result['output'], -1200);
        if (count($syntaxFailures) >= 50) break;
    }
}
$record('full_php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$record(
    'runtime_php_zip_extension',
    class_exists(ZipArchive::class),
    class_exists(ZipArchive::class)
        ? 'ZipArchive available'
        : 'ZipArchive unavailable; enable the PHP zip extension for generated ZIP downloads'
);

$sourceSuites = [
    'verify-architecture-refactor.php',
    'verify-controller-boundaries.php',
    'verify-source-scan-boundaries.php',
    'verify-package-writer-boundaries.php',
    'verify-package-export-boundaries.php',
    'verify-game-lifecycle-boundaries.php',
    'verify-upload-worker-contracts.php',
    'audit-legacy-runtime-references.php',
    'verify-compact-only-metadata-runtime.php',
    'verify-unverified-metadata-staging.php',
    'verify-federation-boundaries.php',
    'verify-federation-worker-boundaries.php',
    'verify-federation-transfer-auth-boundary.php',
    'verify-federation-signed-request-auth-boundary.php',
    'verify-federation-secret-boundary.php',
    'verify-federation-settings-identity-boundary.php',
    'verify-federation-http-boundary.php',
    'verify-worker-pool-resilience.php',
    'verify-job-observability-contract.php',
    'verify-dependency-worker-parallelism-contract.php',
    'verify-unverified-match-performance-contract.php',
    'verify-staged-import-performance-boundary.php',
    'verify-legacy-lib-facades.php',
];
foreach ($sourceSuites as $script) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) {
        $record('suite:' . $script, false, 'missing');
        continue;
    }
    $result = $run([PHP_BINARY, $path], $root);
    $detail = $result['ok']
        ? 'passed'
        : ('exit=' . $result['exit'] . ' ' . substr((string)$result['output'], -3000));
    $record('suite:' . $script, $result['ok'], $detail);
}

$bridge = $root . '/assets/background-jobs-cursor-bridge.js';
$bridgeSource = (string)@file_get_contents($bridge);
$record(
    'worker_ui_source_contract',
    str_contains($bridgeSource, "authority === 'degraded'")
        && str_contains($bridgeSource, 'Worker pool degraded')
        && str_contains($bridgeSource, 'active jobs'),
    'worker UI distinguishes process-pool health from active DB jobs'
);
$node = '';
if (function_exists('shell_exec')) {
    $node = trim((string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}
if ($node !== '') {
    $nodePath = preg_split('/\R/', $node)[0] ?? 'node';
    $result = $run([$nodePath, '--check', $bridge], $root);
    $record('background_jobs_js_syntax', $result['ok'], $result['ok'] ? 'passed' : substr((string)$result['output'], -1200));
} else {
    $checks[] = ['check' => 'background_jobs_js_syntax', 'ok' => true, 'detail' => 'Node.js unavailable; structural JS contract checked instead.'];
}

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $db = $application->db;
        $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
        $record('db_connection', $version !== '', $version);

        try {
            $dependencySource = \UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource::sql($db);
            $statement = $db->query(
                'SELECT COUNT(*) FROM ' . $dependencySource . ' runtime_dependencies WHERE 1=0'
            );
            $statement->fetchColumn();
            $record(
                'db_dependency_read_source_compiles',
                true,
                'mode=compact-only compact=' . (
                    \UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource::compactAvailable($db)
                    ? 'yes'
                    : 'no'
                )
            );
        } catch (Throwable $error) {
            $record('db_dependency_read_source_compiles', false, $error->getMessage());
        }

        $queue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $counts = (new \UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOperationalQuery(
            $db,
            $application->config
        ))->queueCounts($queue);
        $record(
            'db_worker_live_count_query',
            isset($counts['queued'], $counts['ready'], $counts['running'], $counts['terminal'], $counts['total']),
            json_encode($counts, JSON_UNESCAPED_SLASHES) ?: ''
        );

        $worker = (new \UnrealDb\Catalog\Infrastructure\Jobs\CatalogDetachedWorker($application->config))->status($queue, false);
        $record(
            'runtime_worker_status',
            isset($worker['active_count'], $worker['desired_count']),
            'active=' . (int)($worker['active_count'] ?? 0) . ' desired=' . (int)($worker['desired_count'] ?? 0)
        );

        $settings = new \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationSettingsStore($db);
        $settings->get('site_id', '');
        $record('db_federation_settings_read', true, 'read-only settings query passed');

        $unverified = $db->query('SELECT COUNT(*) FROM ue_files WHERE scan_status="unverified"')->fetchColumn();
        $record('db_unverified_index_read', $unverified !== false, 'unverified=' . (int)$unverified);

        $compactDb = $run([PHP_BINARY, __DIR__ . '/verify-compact-only-metadata-runtime.php', '--database'], $root);
        $record(
            'suite:verify-compact-only-metadata-runtime.php --database',
            $compactDb['ok'],
            $compactDb['ok'] ? 'passed' : substr((string)$compactDb['output'], -3000)
        );

        $unverifiedDb = $run([PHP_BINARY, __DIR__ . '/verify-unverified-metadata-staging.php', '--database'], $root);
        $record(
            'suite:verify-unverified-metadata-staging.php --database',
            $unverifiedDb['ok'],
            $unverifiedDb['ok'] ? 'passed' : substr((string)$unverifiedDb['output'], -3000)
        );

        $architectureDb = $run([PHP_BINARY, __DIR__ . '/verify-architecture-refactor.php', '--database'], $root);
        $record(
            'suite:verify-architecture-refactor.php --database',
            $architectureDb['ok'],
            $architectureDb['ok'] ? 'passed' : substr((string)$architectureDb['output'], -3000)
        );
    } catch (Throwable $error) {
        $record('database_runtime_checks', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'database_checked' => $withDatabase,
    'php' => PHP_VERSION,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
