<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation')) {
        exit;
    }

    $identity = fed_ensure_identity($db);
    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $ignoreBaseGame = federation_ignore_base_game_files($db);
    $peerFilePolicySql = $ignoreBaseGame ? ' WHERE COALESCE(is_base_game,0)=0' : '';
    $missingPolicySql = $ignoreBaseGame ? ' AND NOT ' . federation_dependency_is_base_game_sql('f', 'd') : '';
    $visibleRequestItemSql = federation_visible_request_item_sql($db, 'i');
    $visibleJobSql = federation_visible_transfer_job_sql($db, 'j');

    $stats = [
        'parents' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1')['c'] ?? 0),
        'children' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE peer_role="child" AND is_active=1')['c'] ?? 0),
        'peer_files' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peer_files' . $peerFilePolicySql)['c'] ?? 0),
        'incoming_open' => (int)(catalog_one(
            $db,
            'SELECT COUNT(*) c FROM ue_federation_requests r
             WHERE r.direction="child_to_parent"
               AND r.status IN ("submitted","part_approved","approved","downloading")
               AND EXISTS (
                   SELECT 1 FROM ue_federation_request_items i
                   WHERE i.request_id=r.id AND ' . $visibleRequestItemSql . '
               )'
        )['c'] ?? 0),
        'join_pending' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_join_requests WHERE status="pending"')['c'] ?? 0),
        'queued_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="queued" AND ' . $visibleJobSql)['c'] ?? 0),
        'downloaded_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="downloaded" AND ' . $visibleJobSql)['c'] ?? 0),
        'failed_jobs' => (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs j WHERE j.status="failed" AND ' . $visibleJobSql)['c'] ?? 0),
        'missing_packages' => (int)(catalog_one(
            $db,
            'SELECT COUNT(*) c FROM (
                SELECT f.game_id, d.required_package
                FROM ue_dependencies d
                JOIN ue_files f ON f.id=d.file_id
                WHERE d.status="missing" AND f.scan_status="verified" AND d.required_package<>""'
                    . $missingPolicySql . '
                GROUP BY f.game_id, d.required_package
            ) missing_packages'
        )['c'] ?? 0),
    ];

    catalog_head('Federation');
    catalog_page_header(
        'Federation',
        'Role-aware federation dashboard. Every inventory, request, missing-file and transfer total follows the effective base-game policy.',
        catalog_federation_links() + ['Missing Files' => 'missing-files.php', 'Requests' => 'request-center.php', 'Documentation' => 'docs.php']
    );

    echo '<div class="card"><h2>Current site mode</h2><table>';
    echo '<tr><th>Role</th><td><strong>' . catalog_h(ucfirst($role)) . '</strong></td></tr>';
    echo '<tr><th>Site</th><td>' . catalog_h($identity['site_name']) . '</td></tr>';
    echo '<tr><th>URL</th><td class="mono path">' . catalog_h($identity['site_url']) . '</td></tr>';
    echo '<tr><th>Site ID</th><td class="mono">' . catalog_h($identity['site_id']) . '</td></tr>';
    echo '<tr><th>Fingerprint</th><td class="mono">' . catalog_h($identity['site_fingerprint']) . '</td></tr>';
    echo '<tr><th>Base-game policy</th><td>' . catalog_h(federation_base_game_policy_label($db)) . '</td></tr>';
    echo '</table><p><a class="button" href="settings.php">Change federation settings</a></p></div>';

    echo '<div class="grid">';
    catalog_stat_card('Active parents', $stats['parents']);
    catalog_stat_card('Active children', $stats['children']);
    catalog_stat_card('Eligible missing packages', $stats['missing_packages'], '', $stats['missing_packages'] > 0 ? 'attention' : '');
    catalog_stat_card('Open eligible requests', $stats['incoming_open'], '', $stats['incoming_open'] > 0 ? 'attention' : '');
    catalog_stat_card('Pending child joins', $stats['join_pending'], '', $stats['join_pending'] > 0 ? 'attention' : '');
    catalog_stat_card('Eligible queued transfers', $stats['queued_jobs'], '', $stats['queued_jobs'] > 0 ? 'attention' : '');
    catalog_stat_card('Eligible waiting import', $stats['downloaded_jobs'], '', $stats['downloaded_jobs'] > 0 ? 'attention' : '');
    catalog_stat_card('Eligible failed transfers', $stats['failed_jobs'], '', $stats['failed_jobs'] > 0 ? 'warning' : '');
    echo '</div>';

    if ($role === 'child') {
        echo '<div class="card"><h2>Child workflow</h2><p>This server requests policy-eligible missing files from a parent.</p><div class="grid">';
        catalog_tool_card('1. Parents', 'peers.php?role=parent', 'Manage parent connections or join a parent.', $stats['parents'] > 0 ? (string)$stats['parents'] : 'setup');
        catalog_tool_card('2. Missing Files', 'missing-files.php', 'Select policy-eligible local missing dependency packages and submit a request to a parent.', $stats['missing_packages'] > 0 ? (string)$stats['missing_packages'] : '');
        catalog_tool_card('3. Outgoing Requests', 'request-status.php', 'Track policy-visible parent decisions and cancel an active request.');
        catalog_tool_card('4. Approved Downloads', 'approved-downloads.php', 'Review policy-visible parent-approved dependency files.');
        catalog_tool_card('5. Queue', 'queue.php', 'Monitor policy-visible download and import progress.', $stats['queued_jobs'] + $stats['downloaded_jobs'] > 0 ? (string)($stats['queued_jobs'] + $stats['downloaded_jobs']) : '');
        echo '</div></div>';

        echo '<div class="card"><h2>Parent-only functions</h2><p class="muted">Children, child join approvals, child inventories, parent pulls, and incoming child request approvals are disabled while this site is in Child mode.</p></div>';
    } elseif ($role === 'parent') {
        echo '<div class="card"><h2>Parent workflow</h2><p>This server manages children, reads policy-filtered child inventories, pulls eligible files, and decides whether it can provide eligible files requested by children.</p><div class="grid">';
        catalog_tool_card('1. Children', 'peers.php?role=child', 'Manage child connections and open inventories.', $stats['children'] > 0 ? (string)$stats['children'] : 'setup');
        catalog_tool_card('2. Child Join Requests', 'join-requests.php', 'Approve or deny new child pairing requests.', $stats['join_pending'] > 0 ? (string)$stats['join_pending'] : '');
        catalog_tool_card('3. Missing Files', 'missing-files.php', 'Find policy-eligible dependency files this parent needs that are available from children.', $stats['missing_packages'] > 0 ? (string)$stats['missing_packages'] : '');
        catalog_tool_card('4. Incoming Requests', 'requests.php', 'Review active policy-eligible dependency requests.', $stats['incoming_open'] > 0 ? (string)$stats['incoming_open'] : '');
        catalog_tool_card('5. Child Inventories', 'peer-inventory.php', 'Compare policy-filtered parent and child inventories and queue parent pulls.', $stats['peer_files'] > 0 ? (string)$stats['peer_files'] : '');
        catalog_tool_card('6. Queue', 'queue.php', 'Monitor policy-visible parent pulls, child downloads, and imports.', $stats['queued_jobs'] + $stats['downloaded_jobs'] > 0 ? (string)($stats['queued_jobs'] + $stats['downloaded_jobs']) : '');
        echo '</div></div>';

        echo '<div class="card"><h2>Child-only functions</h2><p class="muted">Joining a parent, creating outgoing dependency requests, and approved downloads are disabled while this site is in Parent mode.</p></div>';
    } else {
        echo '<div class="card"><h2>Federation setup required</h2><p>This server is in Standalone mode. Select Parent or Child before adding connections or transferring files.</p><p><a class="button" href="settings.php">Open Federation Settings</a></p></div>';
    }

    echo '<div class="card"><h2>Operations and monitoring</h2><div class="grid">';
    catalog_tool_card('Connections', 'peers.php', 'View all configured parent and child peers.');
    catalog_tool_card('Requests', 'request-center.php', 'Open the policy-filtered incoming/outgoing request centre.');
    catalog_tool_card('Transfer Queue', 'queue.php', 'Review queued, running, downloaded, imported, failed, and cancelled jobs.', $stats['failed_jobs'] > 0 ? (string)$stats['failed_jobs'] . ' failed' : '');
    catalog_tool_card('Run Worker', 'worker-run.php', 'Poll approvals, transfer files, and import completed downloads.');
    catalog_tool_card('Conflicts', 'conflicts.php', 'Review policy-visible identity and hash conflicts between local and peer files.');
    catalog_tool_card('Maintenance', 'maintenance.php', 'Clean old federation records and review storage.');
    catalog_tool_card('Logs', 'logs.php', 'View pairing, inventory, request, transfer, and worker events.');
    catalog_tool_card('Documentation', 'docs.php', 'Review role authority and worker setup.');
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
