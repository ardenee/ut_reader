<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationWorker.php';
require_once __DIR__ . '/../lib/FederationStreamingWorker.php';
require_once __DIR__ . '/../lib/FederationDependencyDownloads.php';

function fw_run_bulk(PDO $db, array $config): array
{
    $limit = max(1, (int)(fed_setting($db, 'max_files_per_transfer_run', '1') ?: 1));
    $results = [
        'transfer_limit' => $limit,
        'import_limit' => $limit,
        'approved_dependency_queue' => federation_queue_approved_dependency_downloads($db),
        'transfers' => [],
        'imports' => [],
    ];

    for ($i = 0; $i < $limit; $i++) {
        $result = federation_streaming_run_one_transfer($db, $config);
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

    return $results;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_worker_run');
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

    catalog_page_header(
        'Federation Bulk Worker',
        'Polls parent approvals, automatically queues only dependency-needed child downloads, then runs streaming transfers and imports sequentially.',
        catalog_federation_links() + ['Run One Transfer' => 'transfer-run.php', 'Import One Download' => 'import-run.php']
    );

    if (isset($_SESSION['fed_worker_result'])) {
        echo '<div class="card"><h2>Last worker result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_worker_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
        unset($_SESSION['fed_worker_result']);
    }

    echo '<div class="card"><h2>Run worker</h2><table>';
    echo '<tr><th>Transfer mode</th><td>Streaming cURL</td></tr>';
    echo '<tr><th>Child download policy</th><td>Parent-approved missing dependencies only</td></tr>';
    echo '<tr><th>Max files per run</th><td>' . $limit . '</td></tr>';
    echo '<tr><th>Queued transfers</th><td>' . $queued . '</td></tr>';
    echo '<tr><th>Downloaded files waiting import</th><td>' . $downloaded . '</td></tr>';
    echo '</table><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_worker_run')) . '"><button>Run federation worker now</button></form></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation worker error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
