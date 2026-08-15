#!/usr/bin/env php
<?php
/**
 * Read-only architecture/runtime verifier for the scale-ready health boundary.
 *
 * Use --run on a deployment host to exercise the real configured PDO, durable
 * queue table and package storage path. Without --run this remains a source and
 * PHP-syntax contract.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$run = in_array('--run', array_slice($argv, 1), true);
$checks = [];
$failures = [];

$read = static function (string $relative) use ($root): string {
    $source = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($source) ? $source : '';
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$bootstrap = $read('bootstrap.php');
$apiBootstrap = $read('api/v1/_bootstrap.php');
$health = $read('api/v1/health.php');
$readiness = $read('api/v1/readiness.php');
$service = $read('src/Application/System/SystemReadinessService.php');
$probePort = $read('src/Application/System/Contract/ReadinessProbe.php');
$factory = $read('src/Infrastructure/Composition/CatalogSystemReadinessFactory.php');
$dbProbe = $read('src/Infrastructure/Health/PdoDatabaseReadinessProbe.php');
$queueProbe = $read('src/Infrastructure/Health/PdoQueueReadinessProbe.php');
$storageProbe = $read('src/Infrastructure/Health/FilesystemStorageReadinessProbe.php');

$record(
    'bootstrap_honors_session_flag',
    str_contains($bootstrap, 'function catalog_bootstrap(bool $startSession = true)')
        && str_contains($bootstrap, '::boot($startSession)'),
    'catalog_bootstrap(false) must actually suppress session startup'
);
$record(
    'api_bootstrap_honors_session_flag',
    str_contains($apiBootstrap, 'function catalog_api_application(bool $startSession = true)')
        && str_contains($apiBootstrap, 'catalog_bootstrap($startSession)'),
    'API callers must be able to select session-free startup'
);
$record(
    'health_is_session_free',
    str_contains($health, 'catalog_api_application(false)')
        && str_contains($health, "'status' => 'ok'")
        && str_contains($health, "'service' => 'unrealdb-catalog'"),
    'existing health response stays compatible while avoiding session storage'
);
$record(
    'readiness_is_session_free_and_composed',
    str_contains($readiness, 'catalog_api_application(false)')
        && str_contains($readiness, 'CatalogSystemReadinessFactory::create(')
        && str_contains($readiness, '$report->ready ? 200 : 503'),
    'load balancer readiness must be session-free and return 503 when a critical dependency is unavailable'
);
$record(
    'application_readiness_is_port_driven',
    str_contains($probePort, 'interface ReadinessProbe')
        && str_contains($service, 'ReadinessProbe')
        && !str_contains($service, 'PDO')
        && !str_contains($service, 'Infrastructure\\'),
    'Application readiness orchestration must not depend on PDO, filesystem or queue implementations'
);
$record(
    'readiness_composition_is_centralized',
    str_contains($factory, 'PdoDatabaseReadinessProbe')
        && str_contains($factory, 'PdoQueueReadinessProbe')
        && str_contains($factory, 'FilesystemStorageReadinessProbe')
        && str_contains($factory, 'new SystemReadinessService(['),
    'concrete production probes must be wired in one Infrastructure composition root'
);
$record(
    'database_probe_is_bounded',
    str_contains($dbProbe, 'implements ReadinessProbe')
        && str_contains($dbProbe, "query('SELECT 1')"),
    'database readiness must use a constant-time connectivity probe'
);
$record(
    'queue_probe_does_not_scan_jobs',
    str_contains($queueProbe, 'implements ReadinessProbe')
        && str_contains($queueProbe, 'SELECT id FROM ue_background_jobs WHERE 1=0'),
    'queue readiness must validate schema access without counting/scanning queue history'
);
$record(
    'storage_probe_checks_current_role',
    str_contains($storageProbe, 'implements ReadinessProbe')
        && str_contains($storageProbe, 'is_dir($path)')
        && str_contains($storageProbe, 'is_readable($path)')
        && str_contains($storageProbe, 'is_writable($path)'),
    'current all-in-one node requires readable and writable package storage'
);

$syntaxTargets = [
    'bootstrap.php',
    'api/v1/_bootstrap.php',
    'api/v1/health.php',
    'api/v1/readiness.php',
    'src/Application/System/Contract/ReadinessProbe.php',
    'src/Application/System/ReadinessCheck.php',
    'src/Application/System/SystemReadinessReport.php',
    'src/Application/System/SystemReadinessService.php',
    'src/Infrastructure/Health/PdoDatabaseReadinessProbe.php',
    'src/Infrastructure/Health/PdoQueueReadinessProbe.php',
    'src/Infrastructure/Health/FilesystemStorageReadinessProbe.php',
    'src/Infrastructure/Composition/CatalogSystemReadinessFactory.php',
    'bin/verify-system-readiness-contract.php',
];
if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open unavailable; run php -l manually on readiness files.');
} else {
    $syntaxFailures = [];
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' missing';
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

if ($run) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $report = \UnrealDb\Catalog\Infrastructure\Composition\CatalogSystemReadinessFactory::create(
            $application->db,
            $application->config
        )->check();
        $record(
            'runtime_readiness',
            $report->ready,
            json_encode($report->checkData(), JSON_UNESCAPED_SLASHES) ?: ''
        );
    } catch (Throwable $error) {
        $record('runtime_readiness', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'runtime_checked' => $run,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
