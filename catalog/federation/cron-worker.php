<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationWorker.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/ExternalMirrors.php';

function cron_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cron_legacy_query_tokens_enabled(): bool
{
    return in_array(strtolower(trim((string)(getenv('UNREALDB_ALLOW_LEGACY_QUERY_TOKENS') ?: '0'))), ['1', 'true', 'yes', 'on'], true);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $legacyQueryAllowed = cron_legacy_query_tokens_enabled();
    if ($method !== 'POST' && !($legacyQueryAllowed && $method === 'GET')) {
        header('Allow: POST');
        cron_json(['ok' => false, 'error' => 'Cron worker requires POST.'], 405);
    }

    $enabled = (string)fed_setting($db, 'cron_worker_enabled', '0');
    $storedToken = (string)fed_setting($db, 'cron_worker_token', '');
    $givenToken = trim((string)($_SERVER['HTTP_X_FEDERATION_CRON_TOKEN'] ?? ''));
    if ($givenToken === '' && $legacyQueryAllowed) {
        $givenToken = trim((string)($_GET['token'] ?? ''));
        if ($givenToken !== '') {
            error_log('[UnrealDB][' . catalog_request_id() . '] deprecated federation cron query token used');
        }
    }

    if ($enabled !== '1') {
        cron_json(['ok' => false, 'error' => 'Cron worker is unavailable.'], 403);
    }
    if ($storedToken === '' || $givenToken === '' || !hash_equals($storedToken, $givenToken)) {
        cron_json(['ok' => false, 'error' => 'Cron worker is unavailable.'], 403);
    }

    $limit = max(1, min(100, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1)));
    $results = [
        'ok' => true,
        'started_at' => date('c'),
        'transfer_limit' => $limit,
        'import_limit' => $limit,
        'inventory_sync' => federation_sync_due_inventories($db),
        'mirror_maintenance' => external_mirror_maintenance($db),
        'transfers' => [],
        'imports' => [],
    ];

    for ($i = 0; $i < $limit; $i++) {
        $result = federation_worker_run_one_transfer($db, $config);
        $results['transfers'][] = $result;
        if (!empty($result['skipped'])) {
            break;
        }
    }

    for ($i = 0; $i < $limit; $i++) {
        $result = federation_worker_run_one_import($db, $config);
        $results['imports'][] = $result;
        if (!empty($result['skipped'])) {
            break;
        }
    }

    $results['finished_at'] = date('c');
    fed_log(
        $db,
        null,
        null,
        'INFO',
        'CRON_WORKER_RUN',
        json_encode([
            'inventories_synchronized' => (int)($results['inventory_sync']['synchronized'] ?? 0),
            'transfers' => count($results['transfers']),
            'imports' => count($results['imports']),
            'mirror' => $results['mirror_maintenance'],
        ], JSON_UNESCAPED_SLASHES)
    );
    cron_json($results);
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] federation cron failed: ' . get_class($error) . ': ' . $error->getMessage());
    cron_json(['ok' => false, 'error' => 'Cron worker failed.', 'reference' => catalog_request_id()], 500);
}
