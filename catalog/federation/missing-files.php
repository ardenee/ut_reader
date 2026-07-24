<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Federation Missing Files')) {
        exit;
    }

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $ignoreBaseGame = federation_ignore_base_game_files($db);
    $missingPolicySql = $ignoreBaseGame ? ' AND NOT ' . federation_dependency_is_base_game_sql('f', 'd') : '';
    $missingPackages = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM (
            SELECT f.game_id, d.required_package
            FROM ue_dependencies d
            JOIN ue_files f ON f.id=d.file_id
            WHERE d.status="missing" AND f.scan_status="verified" AND d.required_package<>""'
                . $missingPolicySql . '
            GROUP BY f.game_id, d.required_package
        ) missing_packages'
    )['c'] ?? 0);
    $activeParents = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1')['c'] ?? 0);
    $activeChildren = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_peers WHERE peer_role="child" AND is_active=1')['c'] ?? 0);

    catalog_head('Federation Missing Files');
    catalog_page_header(
        'Missing Files',
        'One place to resolve policy-eligible files this server needs. The displayed total follows the effective parent-controlled base-game policy.',
        catalog_federation_links() + ['Requests' => 'request-center.php', 'Queue' => 'queue.php']
    );

    echo '<div class="grid">';
    catalog_stat_card('Eligible missing packages', $missingPackages, 'Distinct missing dependency packages across verified files after applying the base-game policy.', $missingPackages > 0 ? 'attention' : '');
    catalog_stat_card('Active parents', $activeParents);
    catalog_stat_card('Active children', $activeChildren);
    catalog_stat_card('Current role', ucfirst($role));
    echo '</div>';
    echo '<div class="card"><p>' . catalog_h(federation_base_game_policy_label($db)) . '</p></div>';

    if ($role === 'child') {
        echo '<div class="card"><h2>Child workflow</h2>';
        echo '<p>This server needs files from a parent. Select policy-eligible missing dependency packages, submit one outgoing request, then monitor the parent decision and approved downloads.</p>';
        echo '<div class="grid">';
        catalog_tool_card('1. Select missing files', 'request-generate.php', 'Review policy-eligible local missing dependency packages and request selected files from an active parent.', $missingPackages > 0 ? (string)$missingPackages : '');
        catalog_tool_card('2. Track outgoing request', 'request-status.php', 'See whether the parent can supply each policy-visible requested package and whether it was approved.');
        catalog_tool_card('3. Approved downloads', 'approved-downloads.php', 'Review policy-visible files approved by the parent and queued for dependency download.');
        catalog_tool_card('4. Transfer queue', 'queue.php', 'Monitor policy-visible download and import progress.');
        echo '</div></div>';

        echo '<div class="card"><h2>Children</h2><p class="muted">Child management is disabled while this site is in Child mode. A child connects to a parent; it does not manage child peers.</p></div>';
    } elseif ($role === 'parent') {
        echo '<div class="card"><h2>Parent workflow</h2>';
        echo '<p>This server may inspect policy-filtered child inventories and pull files it does not have. Parent pulls do not require child approval.</p>';
        echo '<div class="grid">';
        catalog_tool_card('1. Review child inventories', 'peer-inventory.php?filter=parent_dependency', 'Show policy-eligible child files that satisfy missing dependencies on this parent.', $activeChildren > 0 ? (string)$activeChildren : '');
        catalog_tool_card('2. Queue parent pulls', 'parent-pull.php', 'Download selected eligible files from children under the current parent policy.');
        catalog_tool_card('Incoming child requests', 'requests.php', 'Approve policy-eligible dependency files this parent can supply to a child.');
        catalog_tool_card('Transfer queue', 'queue.php', 'Monitor policy-visible parent pulls, child downloads, and imports.');
        echo '</div></div>';

        if ($activeChildren === 0) {
            echo CatalogUi::alert('warning', 'No active child connections are configured. Approve a child join request first.', 'No children available');
        }
    } else {
        echo '<div class="card"><h2>Federation is not active</h2><p>Select Parent or Child in Federation Settings before using missing-file workflows.</p><p><a class="button" href="settings.php">Open Federation Settings</a></p></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation missing files error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
