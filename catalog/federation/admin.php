<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
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

    catalog_page_header('Federation Admin', 'Parent/child federation dashboard for identity, join requests, peers, inventory, requests, approvals, transfer queues, imports, uploads, maintenance, conflicts, and logs.', catalog_federation_links() + ['Docs' => 'docs.php', 'Public Join Page' => 'join.php', 'Claim Parent' => 'claim-parent.php']);

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
    catalog_stat_card('Peer inventory rows', $stats['peer_files']);
    catalog_stat_card('File requests', $stats['requests']);
    catalog_stat_card('Pending join requests', $stats['join_pending'], '', $stats['join_pending'] > 0 ? 'attention' : '');
    catalog_stat_card('Queued transfer jobs', $stats['queued_jobs'], '', $stats['queued_jobs'] > 0 ? 'attention' : '');
    catalog_stat_card('Waiting import', $stats['downloaded_jobs'], '', $stats['downloaded_jobs'] > 0 ? 'attention' : '');
    catalog_stat_card('Failed jobs', $stats['failed_jobs'], '', $stats['failed_jobs'] > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Core tools</h2><div class="grid">';
    catalog_tool_card('Federation settings', 'settings.php', 'Set site role, URL, identity, speed limits, delays, transfer defaults, join request toggle, and cron worker token.', 'primary');
    catalog_tool_card('Join requests', 'join-requests.php', 'Parent admin approval page for public child-site pairing requests.', $stats['join_pending'] > 0 ? (string)$stats['join_pending'] : '');
    catalog_tool_card('Public join page', 'join.php', 'Share this URL so new deployments can request access to the master parent.');
    catalog_tool_card('Claim parent', 'claim-parent.php', 'Child-side tool to claim an approved one-time parent pairing URL.');
    catalog_tool_card('Peers', 'peers.php', 'Add/manage parent or child sites and shared secrets.');
    catalog_tool_card('Queue overview', 'queue.php', 'Review queued/running/downloaded/imported/failed transfer jobs.', $stats['queued_jobs'] + $stats['downloaded_jobs'] + $stats['failed_jobs'] > 0 ? (string)($stats['queued_jobs'] + $stats['downloaded_jobs'] + $stats['failed_jobs']) : '');
    catalog_tool_card('Conflict report', 'conflicts.php', 'Review same-name, same-GUID, and hash mismatch conflicts between local and peer files.');
    catalog_tool_card('Maintenance', 'maintenance.php', 'Prune old nonces/logs and review federation incoming storage usage.');
    catalog_tool_card('DSM/cron docs', 'docs.php', 'Setup notes and curl examples for scheduled federation workers.');
    catalog_tool_card('Federation logs', 'logs.php', 'View API, pairing, upload/download and transfer logs.');
    echo '</div></div>';

    echo '<div class="card"><h2>Parent/master tools</h2><div class="grid">';
    catalog_tool_card('Peer inventory', 'peer-inventory.php', 'View each child inventory separately.');
    catalog_tool_card('Parent pull from children', 'parent-pull.php', 'Pull missing dependencies first, then other files the parent does not have.');
    catalog_tool_card('Child file requests', 'requests.php', 'Approve or deny child missing-dependency requests, including selected items.');
    echo '</div></div>';

    echo '<div class="card"><h2>Child tools</h2><div class="grid">';
    catalog_tool_card('Push inventory to parent', 'inventory-push.php', 'Send verified local file metadata to the parent.');
    catalog_tool_card('Upload files to parent', 'upload-to-parent.php', 'Queue selected verified local files for controlled upload to parent.');
    catalog_tool_card('Generate missing dependency request', 'request-generate.php', 'Submit local missing dependency list to the parent.');
    catalog_tool_card('Request status/cancel', 'request-status.php', 'Poll parent status and cancel active requests.');
    catalog_tool_card('Approved downloads', 'approved-downloads.php', 'Queue parent-approved files for controlled download.');
    echo '</div></div>';

    echo '<div class="card"><h2>Workers</h2><div class="grid">';
    catalog_tool_card('Bulk worker', 'worker-run.php', 'Run multiple sequential transfers/imports up to the configured per-run limit.', 'primary');
    catalog_tool_card('Run one transfer', 'transfer-run.php', 'Download or upload one queued federation job.');
    catalog_tool_card('Import one downloaded file', 'import-run.php', 'Import one downloaded federation file into the local catalog.');
    catalog_tool_card('Cron worker endpoint', 'cron-worker.php', 'Token-protected worker endpoint for DSM Task Scheduler.');
    catalog_tool_card('Hello endpoint', '../api/federation/hello.php', 'Public identity/status endpoint used for connection testing.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation admin error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
