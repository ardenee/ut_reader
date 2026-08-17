#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for durable-job performance instrumentation,
 * diagnostics and passive worker monitoring.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = @file_get_contents($path);
    return is_string($source) ? $source : '';
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

require_once $root . '/src/Application/Jobs/JobPerformanceTrace.php';
require_once $root . '/src/Application/Jobs/CatalogWorkerMonitoringSummary.php';

$ticks = [0, 100_000_000, 600_000_000, 900_000_000];
$clock = static function () use (&$ticks): int {
    return array_shift($ticks) ?? 900_000_000;
};
$trace = new \UnrealDb\Catalog\Application\Jobs\JobPerformanceTrace(
    $clock,
    static fn(): int => 160 * 1024 * 1024,
    static fn(): int => 192 * 1024 * 1024
);
$trace->observe('prepare');
$trace->observe('scan');
$snapshot = $trace->snapshot();
$record(
    'stage_timing_is_monotonic_and_accumulated',
    abs((float)$snapshot['runtime_ms'] - 900.0) < 0.001
        && abs((float)($snapshot['stage_ms']['prepare'] ?? -1) - 500.0) < 0.001
        && abs((float)($snapshot['stage_ms']['scan'] ?? -1) - 300.0) < 0.001
        && $snapshot['current_stage'] === 'scan'
        && $snapshot['observations'] === 2,
    json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?: 'snapshot unavailable'
);
$record(
    'memory_diagnostics_are_reported',
    $snapshot['memory_bytes'] === 160 * 1024 * 1024
        && $snapshot['peak_memory_bytes'] === 192 * 1024 * 1024
        && $snapshot['memory_delta_bytes'] === 0,
    'current=' . $snapshot['memory_bytes'] . ' peak=' . $snapshot['peak_memory_bytes']
);

$normalizationTicks = [0, 1_000_000, 2_000_000];
$normalizationClock = static function () use (&$normalizationTicks): int {
    return array_shift($normalizationTicks) ?? 2_000_000;
};
$normalizationTrace = new \UnrealDb\Catalog\Application\Jobs\JobPerformanceTrace(
    $normalizationClock,
    static fn(): int => 0,
    static fn(): int => 0
);
$normalizationTrace->observe(' Metadata / DB ');
$normalized = $normalizationTrace->snapshot();
$record(
    'stage_names_are_bounded_and_normalized',
    $normalized['current_stage'] === 'metadata_db',
    'stage=' . (string)$normalized['current_stage']
);

$monitoring = \UnrealDb\Catalog\Application\Jobs\CatalogWorkerMonitoringSummary::fromRunningWork([
    [
        'worker_id' => 'worker-2',
        'runtime_seconds' => 45,
        'activity_age_seconds' => 7,
        'job_telemetry' => ['runtime_ms' => 1000.0],
    ],
    [
        'worker_id' => 'worker-1',
        'runtime_seconds' => 120,
        'activity_age_seconds' => 19,
        'job_telemetry' => [],
    ],
    [
        'worker_id' => 'worker-2',
        'runtime_seconds' => -4,
        'activity_age_seconds' => -1,
        'job_telemetry' => ['runtime_ms' => 500.0],
    ],
]);
$record(
    'worker_monitoring_summary_is_deterministic',
    $monitoring['running_jobs'] === 3
        && $monitoring['telemetry_jobs'] === 2
        && $monitoring['active_worker_ids'] === ['worker-1', 'worker-2']
        && $monitoring['oldest_running_seconds'] === 120
        && $monitoring['max_activity_age_seconds'] === 19,
    json_encode($monitoring, JSON_UNESCAPED_SLASHES) ?: 'summary unavailable'
);

$context = $read('src/Application/Jobs/JobExecutionContext.php');
$operational = $read('src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php');
$statusApi = $read('api/v1/job-worker-status.php');
$monitoringSummary = $read('src/Application/Jobs/CatalogWorkerMonitoringSummary.php');
$record(
    'progress_persistence_carries_job_telemetry',
    str_contains($context, 'private readonly JobPerformanceTrace $performance')
        && str_contains($context, '$snapshot = $this->withTelemetry($this->pendingProgress);')
        && str_contains($context, '$progress[\'job_telemetry\'] = $this->performance->snapshot();')
        && str_contains($context, '$this->queue->heartbeat($this->job, $this->leaseSeconds, $snapshot)'),
    'timing/resource snapshots must travel through the existing durable progress write'
);
$record(
    'instrumentation_is_fail_open',
    str_contains($context, 'private function withTelemetry(array $progress): array')
        && str_contains($context, 'catch (\\Throwable)')
        && str_contains($context, 'Diagnostics are intentionally non-functional and fail open.'),
    'telemetry failure must not change job success/retry/cancellation semantics'
);
$record(
    'running_work_exposes_raw_monitoring_ages_and_telemetry',
    str_contains($operational, 'TIMESTAMPDIFF(SECOND,r.leased_at,UTC_TIMESTAMP())')
        && str_contains($operational, 'activity_age_seconds')
        && str_contains($operational, '\'job_telemetry\' => is_array($progress[\'job_telemetry\'] ?? null)'),
    'worker status should expose measured runtime/activity age without guessing whether work is stuck'
);
$record(
    'worker_monitoring_remains_read_only',
    str_contains($statusApi, 'CatalogWorkerMonitoringSummary::fromRunningWork($working)')
        && str_contains($statusApi, '$worker[\'monitoring\'] = $monitoring;')
        && str_contains($monitoringSummary, "'oldest_running_seconds' => \$oldestRunningSeconds")
        && str_contains($monitoringSummary, "'max_activity_age_seconds' => \$maxActivityAgeSeconds")
        && str_contains($statusApi, '$worker[\'status_read_only\'] = true;')
        && str_contains($statusApi, '$worker[\'auto_recovery\'] = null;')
        && str_contains($statusApi, '$worker[\'auto_start\'] = null;'),
    'monitoring may report evidence but must not launch/recover/kill work'
);

$criticalPhp = [
    'src/Application/Jobs/JobPerformanceTrace.php',
    'src/Application/Jobs/JobExecutionContext.php',
    'src/Application/Jobs/CatalogWorkerMonitoringSummary.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobOperationalQuery.php',
    'api/v1/job-worker-status.php',
    'bin/verify-job-observability-contract.php',
];
if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open unavailable; run php -l manually on observability files');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
