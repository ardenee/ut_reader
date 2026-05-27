<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function missing_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Missing Files');

    if (!missing_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $missingObjects = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"');
    $missingPackages = catalog_count($db, 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependencies WHERE status="missing" AND required_package IS NOT NULL AND required_package<>""');
    $resolved = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="resolved"');
    $approved = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE status="approved"');
    $imported = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE status="imported"');

    echo '<div class="card hero"><h1>Missing Files</h1><p class="muted">Find what your library needs, check what the parent has, request missing files, and track approved downloads.</p>';
    catalog_page_links(['Generate Request' => 'federation/request-generate.php', 'Request Status' => 'federation/request-status.php', 'Approved Downloads' => 'federation/approved-downloads.php', 'Parent Inventory' => 'federation/peer-inventory.php', 'Conflicts' => 'federation/conflicts.php']);
    echo '</div>';

    echo '<div class="grid">';
    catalog_stat_card('Missing dependency objects', $missingObjects, '', $missingObjects > 0 ? 'attention' : 'good');
    catalog_stat_card('Missing packages', $missingPackages, '', $missingPackages > 0 ? 'attention' : 'good');
    catalog_stat_card('Resolved dependency rows', $resolved);
    catalog_stat_card('Approved request items', $approved);
    catalog_stat_card('Imported request items', $imported);
    echo '</div>';

    echo '<div class="card"><h2>Repair workflow</h2><div class="grid">';
    catalog_tool_card('1. View parent inventory', 'federation/peer-inventory.php', 'See what the parent/peers have compared with this site.');
    catalog_tool_card('2. Generate missing-file request', 'federation/request-generate.php', 'Submit missing dependency list to the parent for approval.');
    catalog_tool_card('3. Check request status', 'federation/request-status.php', 'Poll parent approval/denial status and cancel active requests.');
    catalog_tool_card('4. Queue approved downloads', 'federation/approved-downloads.php', 'Queue parent-approved files for controlled federation download.');
    catalog_tool_card('5. Run transfers/imports', 'transfers.php', 'Download/import approved files using the worker.');
    catalog_tool_card('6. Review conflicts', 'federation/conflicts.php', 'Check GUID/package/hash mismatches before trusting matches.');
    echo '</div></div>';

    $rows = catalog_all($db, 'SELECT required_package, COUNT(*) c FROM ue_dependencies WHERE status="missing" AND required_package IS NOT NULL AND required_package<>"" GROUP BY required_package ORDER BY c DESC, required_package LIMIT 100');
    echo '<div class="card"><h2>Top missing packages</h2>';
    if (!$rows) {
        echo '<p class="muted">No missing packages currently recorded.</p>';
    } else {
        echo '<table><tr><th>Package</th><th>Missing object rows</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td class="mono">' . catalog_h($row['required_package']) . '</td><td>' . (int)$row['c'] . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Missing files error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
