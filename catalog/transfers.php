<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/lib/FederationBaseGamePolicy.php';

try {
    $db = catalog_db(catalog_config());
    if (!catalog_require_admin_page('Transfers')) {
        exit;
    }

    $visible = federation_visible_transfer_job_sql($db, 'j');
    $queued = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="queued" AND ' . $visible);
    $running = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="running" AND ' . $visible);
    $downloaded = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="downloaded" AND ' . $visible);
    $failed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="failed" AND ' . $visible);
    $mirrorWaiting = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin","uploading")');
    $mirrorFailed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status="failed"');

    catalog_head('Transfers');
    catalog_page_header(
        'Transfers',
        'Monitor federation transfers and external mirror jobs from their consolidated administration pages.',
        [
            'Federation Transfers' => 'federation/queue.php',
            'Federation Worker' => 'federation/diagnostics.php?tab=worker',
            'Federation Logs' => 'federation/diagnostics.php?tab=logs',
            'Mirror Queue' => 'mirror-queue.php',
        ]
    );

    echo '<div class="grid">';
    catalog_stat_card('Queued federation jobs', $queued, '', $queued > 0 ? 'attention' : '');
    catalog_stat_card('Running federation jobs', $running);
    catalog_stat_card('Downloaded waiting import', $downloaded, '', $downloaded > 0 ? 'attention' : '');
    catalog_stat_card('Failed federation jobs', $failed, '', $failed > 0 ? 'warning' : '');
    catalog_stat_card('Mirror jobs waiting', $mirrorWaiting, '', $mirrorWaiting > 0 ? 'attention' : '');
    catalog_stat_card('Failed mirror jobs', $mirrorFailed, '', $mirrorFailed > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Transfer administration</h2><div class="grid">';
    catalog_tool_card('Federation Transfers', 'federation/queue.php', 'Review active, waiting, failed, completed and cancelled federation transfers.', $queued + $running + $downloaded + $failed > 0 ? (string)($queued + $running + $downloaded + $failed) : '');
    catalog_tool_card('Federation Worker', 'federation/diagnostics.php?tab=worker', 'Run the streaming worker and review worker queue health.');
    catalog_tool_card('Federation Logs', 'federation/diagnostics.php?tab=logs', 'Review transfer, import, pairing and inventory events.');
    catalog_tool_card('Federation Cleanup', 'federation/diagnostics.php?tab=cleanup', 'Prune old federation records and stale inventory cache.');
    catalog_tool_card('Mirror Queue', 'mirror-queue.php', 'Fulfil external mirror jobs or expire stale links.', $mirrorWaiting > 0 ? (string)$mirrorWaiting : '');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Transfers error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
