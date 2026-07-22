<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Admin')) {
        exit;
    }

    catalog_head('Federation Admin');

    $identity = fed_ensure_identity($db);
    $stats = [
        'peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers')['c'] ?? 0),
        'active_peers' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE is_active=1')['c'] ?? 0),
        'peer_files' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files')['c'] ?? 0),
        'requests' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_requests')['c'] ?? 0),
        'join_pending' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status="pending"')['c'] ?? 0),
        'queued_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0),
        'downloaded_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded"')['c'] ?? 0),
        'failed_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="failed"')['c'] ?? 0),
    ];

    catalog_page_header(
        'Federation Admin',
        'Parent/master federation dashboard. Approved joins pair automatically; parents inspect and pull from children directly; child downloads require parent approval and are limited to missing dependencies.',
        catalog_federation_links() + ['Docs' => 'docs.php', 'Public Join Page' => 'join.php']
    );

    echo '<div class="card"><h2>Local site identity</h2><table>';
    echo '<tr><th>Site name</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>Site URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Role</th><td>' . catalog_h(fed_setting($db, 'site_role', 'standalone')) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="grid">';
    catalog_stat_card('Total peers', $stats['peers']);
    catalog_stat_card('Active peers', $stats['active_peers']);
    catalog_stat_card('Cached child inventory rows', $stats['peer_files']);
    catalog_stat_card('Dependency requests', $stats['requests']);
    catalog_stat_card('Pending join requests', $stats['join_pending'], '', $stats['join_pending'] > 0 ? 'attention' : '');
    catalog_stat_card('Queued transfer jobs', $stats['queued_jobs'], '', $stats['queued_jobs'] > 0 ? 'attention' : '');
    catalog_stat_card('Waiting import', $stats['downloaded_jobs'], '', $stats['downloaded_jobs'] > 0 ? 'attention' : '');
    catalog_stat_card('Failed jobs', $stats['failed_jobs'], '', $stats['failed_jobs'] > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Core tools</h2><div class="grid">';
    catalog_tool_card('Federation settings', 'settings.php', 'Set identity, role, transfer limits, automatic dependency download behavior, and worker settings.', 'primary');
    catalog_tool_card('Join requests', 'join-requests.php', 'Approve or deny child pairing requests. Approval completes on the child automatically.', $stats['join_pending'] > 0 ? (string)$stats['join_pending'] : '');
    catalog_tool_card('Join main parent', 'join-main-parent.php', 'Child-side join request and automatic approval status.');
    catalog_tool_card('Peers', 'peers.php', 'Review automatically paired parent and child sites.');
    catalog_tool_card('Queue overview', 'queue.php', 'Review queued, running, downloaded, imported, and failed transfer jobs.', $stats['queued_jobs'] + $stats['downloaded_jobs'] + $stats['failed_jobs'] > 0 ? (string)($stats['queued_jobs'] + $stats['downloaded_jobs'] + $stats['failed_jobs']) : '');
    catalog_tool_card('Conflict report', 'conflicts.php', 'Review identity/hash conflicts between local and peer files.');
    catalog_tool_card('Maintenance', 'maintenance.php', 'Prune nonces/logs and review federation storage.');
    catalog_tool_card('Federation logs', 'logs.php', 'View pairing, inventory, request, and transfer events.');
    echo '</div></div>';

    echo '<div class="card"><h2>Parent/master tools</h2><div class="grid">';
    catalog_tool_card('Child inventory', 'peer-inventory.php', 'Read child inventory directly and show only needed or otherwise missing files.');
    catalog_tool_card('Parent pull from children', 'parent-pull.php', 'Download selected child files absent from the parent without child approval.');
    catalog_tool_card('Child dependency requests', 'requests.php', 'Approve or deny child requests for missing dependency files.');
    echo '</div></div>';

    echo '<div class="card"><h2>Child tools</h2><div class="grid">';
    catalog_tool_card('Generate missing dependency request', 'request-generate.php', 'Submit local missing dependencies to the parent for approval.');
    catalog_tool_card('Request status/cancel', 'request-status.php', 'Review the latest dependency request decision.');
    catalog_tool_card('Approved dependency downloads', 'approved-downloads.php', 'Review automatically queued parent-approved dependency downloads.');
    echo '</div></div>';

    echo '<div class="card"><h2>Workers</h2><div class="grid">';
    catalog_tool_card('Bulk worker', 'worker-run.php', 'Poll approvals, queue dependency-only child downloads, transfer, and import.', 'primary');
    catalog_tool_card('Run one transfer', 'transfer-run.php', 'Run one queued federation transfer.');
    catalog_tool_card('Import one downloaded file', 'import-run.php', 'Import one downloaded federation file.');
    catalog_tool_card('Cron worker endpoint', 'cron-worker-streaming.php', 'Token-protected scheduled worker endpoint.');
    catalog_tool_card('Documentation', 'docs.php', 'Federation role, approval, and worker setup.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
