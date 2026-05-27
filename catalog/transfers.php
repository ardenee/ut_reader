<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function transfers_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Transfers');

    if (!transfers_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $queued = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"');
    $running = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="running"');
    $downloaded = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"');
    $failed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"');
    $mirrorWaiting = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin","uploading")');
    $mirrorFailed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status="failed"');

    echo '<div class="card hero"><h1>Transfers</h1><p class="muted">Monitor background jobs: federation downloads/uploads/imports, mirror jobs, failures, and maintenance.</p>';
    catalog_page_links(['Queue' => 'federation/queue.php', 'Bulk Worker' => 'federation/worker-run.php', 'Import Downloaded' => 'federation/import-run.php', 'Mirror Queue' => 'mirror-queue.php', 'Maintenance' => 'federation/maintenance.php']);
    echo '</div>';

    echo '<div class="grid">';
    catalog_stat_card('Queued federation jobs', $queued, '', $queued > 0 ? 'attention' : '');
    catalog_stat_card('Running federation jobs', $running);
    catalog_stat_card('Downloaded waiting import', $downloaded, '', $downloaded > 0 ? 'attention' : '');
    catalog_stat_card('Failed federation jobs', $failed, '', $failed > 0 ? 'warning' : '');
    catalog_stat_card('Mirror jobs waiting', $mirrorWaiting, '', $mirrorWaiting > 0 ? 'attention' : '');
    catalog_stat_card('Failed mirror jobs', $mirrorFailed, '', $mirrorFailed > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Transfer tools</h2><div class="grid">';
    catalog_tool_card('Queue overview', 'federation/queue.php', 'Review queued/running/downloaded/imported/failed federation transfer jobs.', $queued + $downloaded + $failed > 0 ? (string)($queued + $downloaded + $failed) : '');
    catalog_tool_card('Bulk worker', 'federation/worker-run.php', 'Run multiple sequential transfers/imports up to the configured per-run limit.', 'primary');
    catalog_tool_card('Run one transfer', 'federation/transfer-run.php', 'Run one queued federation download/upload job.');
    catalog_tool_card('Import one downloaded file', 'federation/import-run.php', 'Import one downloaded federation file into the local catalog.');
    catalog_tool_card('Mirror queue', 'mirror-queue.php', 'Fulfil external mirror jobs or expire stale links.', $mirrorWaiting > 0 ? (string)$mirrorWaiting : '');
    catalog_tool_card('Maintenance', 'federation/maintenance.php', 'Prune nonces/logs, run mirror maintenance, and review incoming storage.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Transfers error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
