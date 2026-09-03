<?php
/**
 * Exports current corrupt/non-retryable file sources with copyable filesystem
 * paths and original source-relative provenance.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogCorruptSourceExportQuery;

function corrupt_export_text(string $value): string
{
    $value = trim((string)(preg_replace('/\\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > 500 ? mb_substr($value, 0, 500, 'UTF-8') : $value;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Corrupt File Export')) {
        exit;
    }

    $queue = trim((string)($_GET['queue'] ?? ''));
    if ($queue !== '' && (strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1)) {
        throw new InvalidArgumentException('A valid queue name is required.');
    }

    $jobType = trim((string)($_GET['job_type'] ?? ''));
    if ($jobType !== '' && !in_array($jobType, JobType::all(), true)) {
        $jobType = '';
    }

    $search = corrupt_export_text((string)($_GET['search'] ?? ''));
    if (mb_strlen($search, 'UTF-8') > 200) {
        $search = mb_substr($search, 0, 200, 'UTF-8');
    }

    $result = (new CatalogCorruptSourceExportQuery($db, $config))->fetch($queue, $jobType, $search);
    $filename = 'unrealdb-corrupt-files-' . gmdate('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    $output = fopen('php://output', 'wb');
    if (!is_resource($output)) {
        throw new RuntimeException('Could not open corrupt-file export stream.');
    }

    fputcsv($output, [
        'copy_path',
        'copy_path_exists',
        'source_relative_path',
        'file_name',
        'archive_container_path',
        'archive_source_name',
        'archive_entry_path',
        'path_kind',
        'classification',
        'job_id',
        'queue',
        'job_type',
        'queue_status',
        'display_status',
        'reason',
        'source_resolution_error',
    ]);

    foreach ($result['rows'] as $row) {
        fputcsv($output, [
            (string)$row['copy_path'],
            !empty($row['copy_path_exists']) ? '1' : '0',
            (string)$row['source_relative_path'],
            (string)$row['file_name'],
            (string)$row['archive_container_path'],
            (string)$row['archive_source_name'],
            (string)$row['archive_entry_path'],
            (string)$row['path_kind'],
            (string)$row['classification'],
            (int)$row['job_id'],
            (string)$row['queue'],
            (string)$row['job_type'],
            (string)$row['queue_status'],
            (string)$row['display_status'],
            (string)$row['reason'],
            (string)$row['source_resolution_error'],
        ]);
    }
    fclose($output);
    exit;
} catch (Throwable $error) {
    if (function_exists('catalog_system_error_record_exception')) {
        catalog_system_error_record_exception($error, 'corrupt_file_export');
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo 'Corrupt file export failed: ' . catalog_public_error_message() . "\n";
}
