<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationWorker.php';
require_once __DIR__ . '/../lib/FederationStreamingWorker.php';
require_once __DIR__ . '/../lib/ExternalMirrors.php';

function cron_stream_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $enabled = (string)fed_setting($db, 'cron_worker_enabled', '0');
    $expectedToken = (string)fed_setting($db, 'cron_worker_token', '');
    $providedToken = (string)($_GET['token'] ?? $_SERVER['HTTP_X_FEDERATION_CRON_TOKEN'] ?? '');
    if ($enabled !== '1' || $expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        cron_stream_json(['ok' => false, 'error' => 'Federation cron worker is unavailable.'], 403);
    }

    $limit = max(1, min(100, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1)));
    $result = ['ok' => true, 'mode' => 'streaming', 'started_at' => date('c'), 'transfers' => [], 'imports' => [], 'mirror_maintenance' => external_mirror_maintenance($db)];
    for ($i = 0; $i < $limit; $i++) {
        $transfer = federation_streaming_run_one_transfer($db, $config);
        $result['transfers'][] = $transfer;
        if (!empty($transfer['skipped'])) break;
    }
    for ($i = 0; $i < $limit; $i++) {
        $import = federation_worker_run_one_import($db, $config);
        $result['imports'][] = $import;
        if (!empty($import['skipped'])) break;
    }
    $result['finished_at'] = date('c');
    fed_log($db, null, null, 'INFO', 'CRON_STREAMING_WORKER_RUN', json_encode(['transfers' => count($result['transfers']), 'imports' => count($result['imports'])], JSON_UNESCAPED_SLASHES));
    cron_stream_json($result);
} catch (Throwable $e) {
    error_log('[UnrealDB federation streaming cron] ' . get_class($e) . ': ' . $e->getMessage());
    cron_stream_json(['ok' => false, 'error' => 'Federation streaming worker failed.'], 500);
}
