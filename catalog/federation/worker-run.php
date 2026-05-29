<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationWorker.php';

function fw_csrf(): string
{
    $_SESSION['fed_worker_run_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_worker_run_csrf'];
}

function fw_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_worker_run_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function fw_run_bulk(PDO $db, array $config): array
{
    $limit = max(1, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1));
    $results = [
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
            fed_log($db, null, null, 'ERROR', 'AUTO_INVENTORY_PUSH_FAIL', $e->getMessage());
        }
    }

    return $results;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        fw_check_csrf();
        $_SESSION['fed_worker_result'] = fw_run_bulk($db, $config);
        header('Location: worker-run.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Bulk Worker')) {
        exit;
    }

    catalog_head('Federation Bulk Worker');

    $limit = max(1, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1));
    $queued = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0);
    $downloaded = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"')['c'] ?? 0);

    catalog_page_header('Federation Bulk Worker', 'Runs multiple controlled worker steps in one click. It runs up to the configured max files per run for transfers, then up to the same limit for imports. Transfers still run sequentially and respect throttling/delay settings.', catalog_federation_links() + ['Run One Transfer' => 'transfer-run.php', 'Import One Download' => 'import-run.php']);

    if (isset($_SESSION['fed_worker_result'])) {
        echo '<div class="card"><h2>Last worker result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_worker_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
        unset($_SESSION['fed_worker_result']);
    }

    echo '<div class="card"><h2>Run worker</h2><table>';
    echo '<tr><th>Max files per transfer run</th><td>' . $limit . '</td></tr>';
    echo '<tr><th>Queued transfers</th><td>' . $queued . '</td></tr>';
    echo '<tr><th>Downloaded files waiting import</th><td>' . $downloaded . '</td></tr>';
    echo '<tr><th>Child auto inventory push after import</th><td>' . ((string)fed_setting($db, 'site_role', 'standalone') === 'child' ? 'yes' : 'not child site') . '</td></tr>';
    echo '</table><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(fw_csrf()) . '"><button>Run bulk worker now</button></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation worker error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
