<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationWorker.php';

function cron_json(array $data, int $status = 200): void
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
    $storedToken = (string)fed_setting($db, 'cron_worker_token', '');
    $givenToken = (string)($_GET['token'] ?? $_SERVER['HTTP_X_FEDERATION_CRON_TOKEN'] ?? '');

    if ($enabled !== '1') {
        cron_json(['ok' => false, 'error' => 'Cron worker is disabled. Enable cron_worker_enabled in federation settings.'], 403);
    }
    if ($storedToken === '') {
        cron_json(['ok' => false, 'error' => 'cron_worker_token is not set in federation settings.'], 403);
    }
    if (!hash_equals($storedToken, $givenToken)) {
        cron_json(['ok' => false, 'error' => 'Bad cron token.'], 403);
    }

    $limit = max(1, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1));
    $results = [
        'ok' => true,
        'started_at' => date('c'),
        'transfer_limit' => $limit,
        'import_limit' => $limit,
        'transfers' => [],
        'imports' => [],
        'auto_inventory_push' => null,
    ];

    for ($i = 0; $i < $limit; $i++) {
        $result = federation_worker_run_one_transfer($db, $config);
        $results['transfers'][] = $result;
        if (!empty($result['skipped'])) {
            break;
        }
    }

    $importedSomething = false;
    for ($i = 0; $i < $limit; $i++) {
        $result = federation_worker_run_one_import($db, $config);
        $results['imports'][] = $result;
        if (!empty($result['skipped'])) {
            break;
        }
        if (!empty($result['result']['file_id'])) {
            $importedSomething = true;
        }
    }

    if ($importedSomething && (string)fed_setting($db, 'site_role', 'standalone') === 'child') {
        try {
            $results['auto_inventory_push'] = federation_auto_push_inventory_to_parent($db);
        } catch (Throwable $e) {
            $results['auto_inventory_push'] = ['ok' => false, 'error' => $e->getMessage()];
            fed_log($db, null, null, 'ERROR', 'CRON_AUTO_INVENTORY_PUSH_FAIL', $e->getMessage());
        }
    }

    $results['finished_at'] = date('c');
    fed_log($db, null, null, 'INFO', 'CRON_WORKER_RUN', json_encode(['transfers' => count($results['transfers']), 'imports' => count($results['imports'])], JSON_UNESCAPED_SLASHES));
    cron_json($results);
} catch (Throwable $e) {
    cron_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
