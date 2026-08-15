<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for metrics.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point backed by focused Infrastructure read models.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoMetricsSnapshotQuery;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogOperationalStorageMetrics;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

function catalog_metrics_authorized(): bool
{
    $configured = trim((string)(getenv('UNREALDB_METRICS_TOKEN') ?: ''));
    if ($configured !== '') {
        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1 && hash_equals($configured, trim($match[1]))) {
            return true;
        }
    }
    return catalog_support_is_admin();
}

function catalog_metrics_line(string $name, int|float $value, array $labels = []): string
{
    $parts = [];
    foreach ($labels as $key => $label) {
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$key) ?: 'label';
        $safeValue = addcslashes((string)$label, "\\\"\n");
        $parts[] = $safeKey . '="' . $safeValue . '"';
    }
    return $name . ($parts !== [] ? '{' . implode(',', $parts) . '}' : '') . ' ' . $value . "\n";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }
    if (!catalog_metrics_authorized()) {
        header('WWW-Authenticate: Bearer realm="UnrealDB metrics"');
        JsonResponse::error('unauthorized', 'Metrics authentication is required.', 401);
    }

    $application = catalog_api_application();
    $db = $application->db;
    $storageRoot = rtrim((string)$application->config['storage_path'], DIRECTORY_SEPARATOR);
    $snapshot = new PdoMetricsSnapshotQuery($db);
    $storage = new CatalogOperationalStorageMetrics($storageRoot);

    $output = "# HELP unrealdb_up UnrealDB metrics endpoint availability.\n"
        . "# TYPE unrealdb_up gauge\n"
        . catalog_metrics_line('unrealdb_up', 1);

    $output .= "# HELP unrealdb_jobs Background jobs by queue, status and resource class.\n# TYPE unrealdb_jobs gauge\n";
    $output .= "# HELP unrealdb_job_recoveries Recovery count represented by current jobs.\n# TYPE unrealdb_job_recoveries gauge\n";
    foreach ($snapshot->jobs() as $row) {
        $labels = [
            'queue' => (string)$row['queue_name'],
            'status' => (string)$row['status'],
            'resource_class' => (string)$row['resource_class'],
        ];
        $output .= catalog_metrics_line('unrealdb_jobs', (int)$row['count'], $labels);
        $output .= catalog_metrics_line('unrealdb_job_recoveries', (int)$row['recoveries'], $labels);
    }

    $output .= "# HELP unrealdb_oldest_queued_job_age_seconds Age of the oldest queued job.\n# TYPE unrealdb_oldest_queued_job_age_seconds gauge\n";
    foreach ($snapshot->oldestQueued() as $row) {
        $output .= catalog_metrics_line('unrealdb_oldest_queued_job_age_seconds', max(0, (int)$row['age_seconds']), ['queue' => (string)$row['queue_name']]);
    }

    $output .= "# HELP unrealdb_catalog_files Catalog files by scan status.\n# TYPE unrealdb_catalog_files gauge\n";
    $output .= "# HELP unrealdb_catalog_file_bytes Catalog file bytes by scan status.\n# TYPE unrealdb_catalog_file_bytes gauge\n";
    foreach ($snapshot->files() as $row) {
        $labels = ['status' => (string)$row['scan_status']];
        $output .= catalog_metrics_line('unrealdb_catalog_files', (int)$row['count'], $labels);
        $output .= catalog_metrics_line('unrealdb_catalog_file_bytes', (int)$row['bytes'], $labels);
    }

    $output .= "# HELP unrealdb_storage_files Physical files in controlled operational storage.\n# TYPE unrealdb_storage_files gauge\n";
    $output .= "# HELP unrealdb_storage_bytes Physical bytes in controlled operational storage.\n# TYPE unrealdb_storage_bytes gauge\n";
    foreach ($storage->controlledDirectories() as $kind => $usage) {
        $output .= catalog_metrics_line('unrealdb_storage_files', $usage['files'], ['kind' => $kind]);
        $output .= catalog_metrics_line('unrealdb_storage_bytes', $usage['bytes'], ['kind' => $kind]);
    }

    $capacity = $storage->capacity();
    if ($capacity['total_bytes'] !== null) {
        $output .= catalog_metrics_line('unrealdb_storage_capacity_bytes', $capacity['total_bytes']);
    }
    if ($capacity['free_bytes'] !== null) {
        $output .= catalog_metrics_line('unrealdb_storage_free_bytes', $capacity['free_bytes']);
    }

    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $output;
} catch (Throwable $error) {
    error_log('[UnrealDB metrics][' . catalog_request_id() . '] ' . $error->getMessage());
    JsonResponse::error('unavailable', 'Metrics are temporarily unavailable.', 503);
}
