<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

/** @return array{files:int,bytes:int} */
function catalog_metrics_directory(string $directory): array
{
    if (!is_dir($directory)) {
        return ['files' => 0, 'bytes' => 0];
    }
    $files = 0;
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $files++;
        $bytes += max(0, (int)$entry->getSize());
    }
    return ['files' => $files, 'bytes' => $bytes];
}

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
    $storage = rtrim((string)$application->config['storage_path'], DIRECTORY_SEPARATOR);
    $output = "# HELP unrealdb_up UnrealDB metrics endpoint availability.\n"
        . "# TYPE unrealdb_up gauge\n"
        . catalog_metrics_line('unrealdb_up', 1);

    $jobs = catalog_all(
        $db,
        'SELECT queue_name,status,resource_class,COUNT(*) count,COALESCE(SUM(recovery_count),0) recoveries '
        . 'FROM ue_background_jobs GROUP BY queue_name,status,resource_class ORDER BY queue_name,status,resource_class'
    );
    $output .= "# HELP unrealdb_jobs Background jobs by queue, status and resource class.\n# TYPE unrealdb_jobs gauge\n";
    $output .= "# HELP unrealdb_job_recoveries Lease recoveries represented by current jobs.\n# TYPE unrealdb_job_recoveries gauge\n";
    foreach ($jobs as $row) {
        $labels = [
            'queue' => (string)$row['queue_name'],
            'status' => (string)$row['status'],
            'resource_class' => (string)$row['resource_class'],
        ];
        $output .= catalog_metrics_line('unrealdb_jobs', (int)$row['count'], $labels);
        $output .= catalog_metrics_line('unrealdb_job_recoveries', (int)$row['recoveries'], $labels);
    }

    $oldest = catalog_all(
        $db,
        'SELECT queue_name,COALESCE(TIMESTAMPDIFF(SECOND,MIN(created_at),UTC_TIMESTAMP()),0) age_seconds '
        . 'FROM ue_background_jobs WHERE status="queued" GROUP BY queue_name'
    );
    $output .= "# HELP unrealdb_oldest_queued_job_age_seconds Age of the oldest queued job.\n# TYPE unrealdb_oldest_queued_job_age_seconds gauge\n";
    foreach ($oldest as $row) {
        $output .= catalog_metrics_line('unrealdb_oldest_queued_job_age_seconds', max(0, (int)$row['age_seconds']), ['queue' => (string)$row['queue_name']]);
    }

    $files = catalog_all($db, 'SELECT scan_status,COUNT(*) count,COALESCE(SUM(file_size),0) bytes FROM ue_files GROUP BY scan_status');
    $output .= "# HELP unrealdb_catalog_files Catalog files by scan status.\n# TYPE unrealdb_catalog_files gauge\n";
    $output .= "# HELP unrealdb_catalog_file_bytes Catalog file bytes by scan status.\n# TYPE unrealdb_catalog_file_bytes gauge\n";
    foreach ($files as $row) {
        $labels = ['status' => (string)$row['scan_status']];
        $output .= catalog_metrics_line('unrealdb_catalog_files', (int)$row['count'], $labels);
        $output .= catalog_metrics_line('unrealdb_catalog_file_bytes', (int)$row['bytes'], $labels);
    }

    $directories = [
        'generated_packages' => $storage . DIRECTORY_SEPARATOR . 'generated-packages',
        'staged_imports' => $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'incoming',
        'incoming_federation' => $storage . DIRECTORY_SEPARATOR . 'federation' . DIRECTORY_SEPARATOR . 'incoming',
    ];
    $output .= "# HELP unrealdb_storage_files Physical files in controlled operational storage.\n# TYPE unrealdb_storage_files gauge\n";
    $output .= "# HELP unrealdb_storage_bytes Physical bytes in controlled operational storage.\n# TYPE unrealdb_storage_bytes gauge\n";
    foreach ($directories as $kind => $directory) {
        $usage = catalog_metrics_directory($directory);
        $output .= catalog_metrics_line('unrealdb_storage_files', $usage['files'], ['kind' => $kind]);
        $output .= catalog_metrics_line('unrealdb_storage_bytes', $usage['bytes'], ['kind' => $kind]);
    }

    $total = @disk_total_space($storage);
    $free = @disk_free_space($storage);
    if (is_float($total) || is_int($total)) {
        $output .= catalog_metrics_line('unrealdb_storage_capacity_bytes', (int)$total);
    }
    if (is_float($free) || is_int($free)) {
        $output .= catalog_metrics_line('unrealdb_storage_free_bytes', (int)$free);
    }

    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $output;
} catch (Throwable $error) {
    error_log('[UnrealDB metrics][' . catalog_request_id() . '] ' . $error->getMessage());
    JsonResponse::error('unavailable', 'Metrics are temporarily unavailable.', 503);
}
