<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Requests')) {
        exit;
    }

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $incoming = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_federation_requests WHERE direction="child_to_parent" AND status IN ("submitted","part_approved")'
    )['c'] ?? 0);
    $allIncoming = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_requests WHERE direction="child_to_parent"')['c'] ?? 0);
    $approvedJobs = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE direction="download_from_parent" AND status IN ("queued","running","downloaded")'
    )['c'] ?? 0);
    $parents = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1')['c'] ?? 0);
    $children = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE peer_role="child" AND is_active=1')['c'] ?? 0);

    catalog_head('Federation Requests');
    catalog_page_header(
        'Requests',
        'Incoming requests ask this server to provide files. Outgoing requests ask another server to provide files to this server.',
        catalog_federation_links() + ['Missing Files' => 'missing-files.php', 'Queue' => 'queue.php']
    );

    echo '<div class="card"><h2>Direction guide</h2><table>';
    echo '<tr><th>Incoming</th><td>A child is asking this parent to provide missing dependency packages. This parent checks availability and approves or denies the request.</td></tr>';
    echo '<tr><th>Outgoing</th><td>This child has asked a parent to provide packages that are missing locally. The parent decides what it can supply.</td></tr>';
    echo '</table></div>';

    echo '<div class="grid">';
    catalog_stat_card('Current role', ucfirst($role));
    catalog_stat_card('Open incoming requests', $incoming, '', $incoming > 0 ? 'attention' : '');
    catalog_stat_card('All incoming requests', $allIncoming);
    catalog_stat_card('Active approved downloads', $approvedJobs, '', $approvedJobs > 0 ? 'attention' : '');
    echo '</div>';

    if ($role === 'parent') {
        echo '<div class="card"><h2>Parent request workflow</h2><div class="grid">';
        catalog_tool_card('Incoming requests from children', 'requests.php', 'Review exactly what each child is asking this parent to provide, check parent availability, then approve or deny.', $incoming > 0 ? (string)$incoming : '');
        catalog_tool_card('Children', 'peers.php?role=child', 'Manage child connections and open inventories or join requests.', $children > 0 ? (string)$children : '');
        catalog_tool_card('Child inventories', 'peer-inventory.php', 'Review files held by children and files needed by either side.');
        catalog_tool_card('Transfer queue', 'queue.php', 'Monitor approved transfers and imports.');
        echo '</div></div>';

        echo '<div class="card"><h2>Outgoing requests</h2><p class="muted">Outgoing dependency requests are disabled while this site is in Parent mode. A parent obtains files from children through Child Inventories and Parent Pull.</p></div>';
    } elseif ($role === 'child') {
        echo '<div class="card"><h2>Child request workflow</h2><div class="grid">';
        catalog_tool_card('Create request from missing files', 'request-generate.php', 'Select local missing packages and submit one request to a parent.');
        catalog_tool_card('Outgoing request status', 'request-status.php', 'See the parent decision for each requested package and cancel an active request.');
        catalog_tool_card('Approved downloads', 'approved-downloads.php', 'Review parent-approved files and download/import progress.', $approvedJobs > 0 ? (string)$approvedJobs : '');
        catalog_tool_card('Parents', 'peers.php?role=parent', 'Manage parent connections or join another parent.', $parents > 0 ? (string)$parents : '');
        echo '</div></div>';

        echo '<div class="card"><h2>Incoming child requests</h2><p class="muted">Incoming child requests are disabled while this site is in Child mode. A child does not approve requests from other children.</p></div>';
    } else {
        echo '<div class="card"><h2>Federation is not active</h2><p>Select Parent or Child in Federation Settings before using request workflows.</p><p><a class="button" href="settings.php">Open Federation Settings</a></p></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation request center error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
