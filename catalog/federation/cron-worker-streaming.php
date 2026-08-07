<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders or processes the federation interface for cron worker streaming.
 * Why: It keeps parent/child federation administration, inventory, requests, and transfer workflows separate from
 *      general catalog pages.
 * Role: Federation UI/administration entry point backed by shared federation services.
 * Audit: Federation-specific route; consolidate shared behavior into services rather than merging distinct
 *        parent/child screens blindly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationWorker.php';
require_once __DIR__ . '/../lib/FederationStreamingWorker.php';
require_once __DIR__ . '/../lib/FederationDependencyDownloads.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/ExternalMirrors.php';

function cron_stream_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cron_stream_legacy_query_tokens_enabled(): bool
{
    return in_array(strtolower(trim((string)(getenv('UNREALDB_ALLOW_LEGACY_QUERY_TOKENS') ?: '0'))), ['1', 'true', 'yes', 'on'], true);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $legacyQueryAllowed = cron_stream_legacy_query_tokens_enabled();
    if ($method !== 'POST' && !($legacyQueryAllowed && $method === 'GET')) {
        header('Allow: POST');
        cron_stream_json(['ok' => false, 'error' => 'Federation cron worker requires POST.'], 405);
    }

    $enabled = (string)fed_setting($db, 'cron_worker_enabled', '0');
    $expectedToken = (string)fed_setting($db, 'cron_worker_token', '');
    $providedToken = trim((string)($_SERVER['HTTP_X_FEDERATION_CRON_TOKEN'] ?? ''));
    if ($providedToken === '' && $legacyQueryAllowed) {
        $providedToken = trim((string)($_GET['token'] ?? ''));
        if ($providedToken !== '') {
            error_log('[UnrealDB][' . catalog_request_id() . '] deprecated streaming cron query token used');
        }
    }
    if ($enabled !== '1' || $expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        cron_stream_json(['ok' => false, 'error' => 'Federation cron worker is unavailable.'], 403);
    }

    $limit = max(1, min(100, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1)));
    $result = [
        'ok' => true,
        'mode' => 'streaming',
        'started_at' => date('c'),
        'inventory_sync' => federation_sync_due_inventories($db),
        'approved_dependency_queue' => federation_queue_approved_dependency_downloads($db),
        'transfers' => [],
        'imports' => [],
        'mirror_maintenance' => external_mirror_maintenance($db),
    ];

    for ($i = 0; $i < $limit; $i++) {
        $transfer = federation_streaming_run_one_transfer($db, $config);
        $result['transfers'][] = $transfer;
        if (!empty($transfer['skipped'])) {
            break;
        }
    }
    for ($i = 0; $i < $limit; $i++) {
        $import = federation_worker_run_one_import($db, $config);
        $result['imports'][] = $import;
        if (!empty($import['skipped'])) {
            break;
        }
    }

    $result['finished_at'] = date('c');
    fed_log(
        $db,
        null,
        null,
        'INFO',
        'CRON_STREAMING_WORKER_RUN',
        json_encode([
            'inventories_synchronized' => (int)($result['inventory_sync']['synchronized'] ?? 0),
            'dependency_downloads_queued' => (int)($result['approved_dependency_queue']['queued'] ?? 0),
            'transfers' => count($result['transfers']),
            'imports' => count($result['imports']),
        ], JSON_UNESCAPED_SLASHES)
    );
    cron_stream_json($result);
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] federation streaming cron failed: ' . get_class($error) . ': ' . $error->getMessage());
    cron_stream_json(['ok' => false, 'error' => 'Federation streaming worker failed.', 'reference' => catalog_request_id()], 500);
}
