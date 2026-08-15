#!/usr/bin/env php
<?php
/**
 * Read-only architecture/runtime verifier for the production health boundary.
 *
 * Use --run on the Windows deployment host to exercise the real configured PDO,
 * durable queue table and package storage path. Without --run this remains a
 * source and PHP-syntax contract.
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
$operationalBootstrap = $read('bootstrap/operational.php');
$apiBootstrap = $read('api/v1/_bootstrap.php');
$live = $read('api/v1/live.php');
$health = $read('api/v1/health.php');
$readiness = $read('api/v1/readiness.php');
$support = $read('lib/CatalogSupport.php');
$performance = $read('lib/CatalogPerformance.php');
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
    'catalog_bootstrap(false) must actually suppress session startup for existing CLI callers'
);
$record(
    'api_bootstrap_honors_session_flag',
    str_contains($apiBootstrap, 'function catalog_api_application(bool $startSession = true)')
        && str_contains($apiBootstrap, 'catalog_bootstrap($startSession)'),
    'normal API callers must be able to select session-free startup when appropriate'
);
$record(
    'operational_bootstrap_is_minimal',
    str_contains($operationalBootstrap, "'/lib/CatalogSupportCore.php'")
        && str_contains($operationalBootstrap, "'/autoload.php'")
        && str_contains($operationalBootstrap, 'CatalogApplication::boot(false)')
        && !str_contains($operationalBootstrap, 'CatalogSupport.php')
        && !str_contains($operationalBootstrap, 'CatalogMfa.php'),
    'machine probes must not traverse page/cache/abuse/MFA bootstrap layers'
);
$record(
    'operational_probes_do_not_persist_telemetry',
    str_contains($operationalBootstrap, "$GLOBALS['catalog_performance_persist_disabled'] = true;")
        && str_contains($performance, "empty($GLOBALS['catalog_performance_persist_disabled'])"),
    'infrastructure-generated probes must not randomly write request-performance samples to MySQL'
);
$record(
    'live_is_dependency_free',
    str_contains($live, "'process' => 'live'")
        && !str_contains($live, 'catalog_operational_application()')
        && !str_contains($live, 'PDO'),
    'process liveness must remain independent of MySQL and package storage'
);
$record(
    'health_uses_operational_bootstrap',
    str_contains($health, "'/bootstrap/operational.php'")
        && str_contains($health, 'catalog_operational_application()')
        && str_contains($health, "'status' => 'ok'")
        && str_contains($health, "'service' => 'unrealdb-catalog'"),
    'existing health response stays compatible while using the minimal session-free bootstrap'
);
$record(
    'readiness_uses_operational_bootstrap',
    str_contains($readiness, "'/bootstrap/operational.php'")
        && str_contains($readiness, 'catalog_operational_application()')
        && str_contains($readiness, 'CatalogSystemReadinessFactory::create(')
        && str_contains($readiness, '$report->ready ? 200 : 503'),
    'service-manager readiness must use minimal startup and return 503 when a critical dependency is unavailable'
);
$record(
    'operational_probes_bypass_public_middleware',
    str_contains($support, '$catalogOperationalProbe')
        && str_contains($support, '/api/v1/health.php')
        && str_contains($support, '/api/v1/readiness.php')
        && str_contains($support, 'if (!$catalogOperationalProbe)'),
    'health/readiness must remain outside crawler, burst and public response-cache middleware even if full support is included later'
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
    'single-host production requires readable and writable package storage'
);

$syntaxTargets = [
    'bootstrap.php',
    'bootstrap/operational.php',
    'api/v1/_bootstrap.php',
    'api/v1/live.php',
    'api/v1/health.php',
    'api/v1/readiness.php',
    'lib/CatalogSupport.php',
    'lib/CatalogPerformance.php',
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
        require_once $root . '/bootstrap/operational.php';
        $application = catalog_operational_application();
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
