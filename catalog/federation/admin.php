<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../lib/FederationState.php';

try {
    $db = catalog_db(catalog_config());
    if (!catalog_require_admin_page('Federation')) {
        exit;
    }

    federation_reconcile_site_role($db);
    $identity = fed_ensure_identity($db);
    $role = federation_site_role($db);
    $displayRole = federation_display_role($db);
    $parent = federation_parent_peer($db, true);
    $children = federation_child_peers($db, true);
    $pendingJoins = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status="pending"')['c'] ?? 0);
    $visibleItems = federation_visible_request_item_sql($db, 'i');
    $visibleJobs = federation_visible_transfer_job_sql($db, 'j', $parent ?: null);
    $openRequests = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_federation_requests r
         WHERE r.status IN ("submitted","part_approved","approved","downloading")
           AND EXISTS (SELECT 1 FROM ue_federation_request_items i WHERE i.request_id=r.id AND ' . $visibleItems . ')'
    )['c'] ?? 0);
    $activeTransfers = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status IN ("queued","running","downloaded") AND ' . $visibleJobs)['c'] ?? 0);
    $failedTransfers = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="failed" AND ' . $visibleJobs)['c'] ?? 0);
    $peerFiles = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files' . (federation_ignore_base_game_files($db, $parent ?: null) ? ' WHERE COALESCE(is_base_game,0)=0' : ''))['c'] ?? 0);
    $lastInventory = (string)(catalog_one($db, 'SELECT MAX(last_seen_at) t FROM ue_federation_peer_files')['t'] ?? '');
    $lastWorker = catalog_one(
        $db,
        'SELECT * FROM ue_federation_transfer_logs
         WHERE event IN ("INVENTORY_SYNC_FROM_PEER","JOB_IMPORTED","TRANSFER_COMPLETE","WORKER_ERROR")
         ORDER BY created_at DESC,id DESC LIMIT 1'
    );
    $lastError = catalog_one(
        $db,
        'SELECT * FROM ue_federation_transfer_logs WHERE level IN ("ERROR","CRITICAL") ORDER BY created_at DESC,id DESC LIMIT 1'
    );

    catalog_head('Federation');
    catalog_page_header(
        'Federation Overview',
        'Role, connection health, inventory activity, requests, transfers, worker state and administration shortcuts.',
        federation_main_links()
    );

    echo '<div class="grid">';
    catalog_stat_card('Current role', $displayRole);
    catalog_stat_card('Connected Parent', $parent ? 1 : 0);
    catalog_stat_card('Connected Children', count($children));
    catalog_stat_card('Pending Child Joins', $pendingJoins, '', $pendingJoins > 0 ? 'attention' : '');
    catalog_stat_card('Open File Requests', $openRequests, '', $openRequests > 0 ? 'attention' : '');
    catalog_stat_card('Active Transfers', $activeTransfers, '', $activeTransfers > 0 ? 'attention' : '');
    catalog_stat_card('Failed Transfers', $failedTransfers, '', $failedTransfers > 0 ? 'warning' : '');
    catalog_stat_card('Cached Peer Files', $peerFiles);
    echo '</div>';

    echo '<div class="card"><h2>Federation identity and policy</h2><table>';
    echo '<tr><th>Role</th><td><strong>' . catalog_h($displayRole) . '</strong></td></tr>';
    echo '<tr><th>Site</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Base-game policy</th><td>' . catalog_h(federation_base_game_policy_label($db, $parent ?: null)) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h2>Connection summary</h2>';
    if ($role === 'standalone') {
        if (federation_has_pending_parent_join($db)) {
            echo '<p>A request to join <strong>' . catalog_h((string)fed_setting($db, 'main_parent_url', '')) . '</strong> is pending. This server remains Standalone until pairing completes.</p><p><a class="button" href="connections.php">Check or cancel request</a></p>';
        } else {
            echo '<p>This server is Standalone.</p><p><a class="button" href="connections.php">Connect to a Parent or accept Children</a></p>';
        }
    } elseif ($role === 'child') {
        echo '<p>Connected to Parent: <strong>' . catalog_h((string)($parent['site_name'] ?? 'unknown')) . '</strong>.</p><p><a class="button" href="connections.php">Manage Parent</a> <a class="button" href="inventories.php">View required files</a> <a class="button" href="requests.php">View requests</a></p>';
    } else {
        echo '<p>This Parent has <strong>' . count($children) . '</strong> active child connection(s).</p><p><a class="button" href="connections.php">Review connections</a> <a class="button" href="inventories.php">View child inventories</a> <a class="button" href="requests.php">View file requests</a></p>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Inventory and worker health</h2><table>';
    echo '<tr><th>Last cached inventory update</th><td>' . catalog_h($lastInventory !== '' ? $lastInventory : 'never') . '</td></tr>';
    echo '<tr><th>Automatic inventory interval</th><td>' . (int)fed_setting($db, 'inventory_sync_interval_hours', '24') . ' hour(s)</td></tr>';
    echo '<tr><th>Cron worker enabled</th><td>' . ((string)fed_setting($db, 'cron_worker_enabled', '0') === '1' ? 'yes' : 'no') . '</td></tr>';
    echo '<tr><th>Last worker activity</th><td>' . ($lastWorker ? catalog_h($lastWorker['created_at'] . ' — ' . $lastWorker['event']) : 'none recorded') . '</td></tr>';
    echo '<tr><th>Last error</th><td>' . ($lastError ? catalog_h($lastError['created_at'] . ' — ' . $lastError['event'] . ': ' . $lastError['details']) : 'none recorded') . '</td></tr>';
    echo '</table><p><a class="button" href="diagnostics.php?tab=worker">Worker diagnostics</a> <a class="button" href="diagnostics.php?tab=logs">Logs</a></p></div>';

    echo '<div class="card"><h2>Administration</h2><div class="grid">';
    catalog_tool_card('Connections', 'connections.php', 'Join a Parent, approve Children, edit established connections or remove them.');
    catalog_tool_card('Inventories', 'inventories.php', 'View files needed by this server and files missing from it.');
    catalog_tool_card('File Requests', 'requests.php', 'Approve, deny, track and cancel federation file requests.');
    catalog_tool_card('Transfers', 'queue.php', 'Monitor queued, running, downloaded, imported, failed and cancelled transfers.', $failedTransfers > 0 ? $failedTransfers . ' failed' : '');
    catalog_tool_card('Settings', 'settings.php', 'Configure identity, policy, refresh intervals, transfer limits and worker settings.');
    catalog_tool_card('Diagnostics', 'diagnostics.php', 'Logs, cleanup, conflicts, worker controls and connection diagnostics.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
