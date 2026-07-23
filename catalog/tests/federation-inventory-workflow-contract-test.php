<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function federation_inventory_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$inventoryPage = file_get_contents($root . '/federation/peer-inventory.php');
$inventoryLib = file_get_contents($root . '/lib/FederationInventory.php');
$inventoryRefreshLib = file_get_contents($root . '/lib/FederationInventoryRefresh.php');
$inventoryApi = file_get_contents($root . '/api/federation/inventory-list.php');
$inventoryRefreshApi = file_get_contents($root . '/api/federation/inventory-refresh.php');
$settings = file_get_contents($root . '/federation/settings.php');
$worker = file_get_contents($root . '/federation/worker-run.php');
$cron = file_get_contents($root . '/federation/cron-worker-streaming.php');

foreach ([
    'inventory page' => $inventoryPage,
    'inventory library' => $inventoryLib,
    'inventory refresh library' => $inventoryRefreshLib,
    'inventory API' => $inventoryApi,
    'inventory refresh API' => $inventoryRefreshApi,
    'settings page' => $settings,
    'worker page' => $worker,
    'cron worker' => $cron,
] as $label => $content) {
    federation_inventory_expect(is_string($content), 'Missing ' . $label . '.');
}

federation_inventory_expect(
    str_contains($inventoryPage, 'Parent Dependency Needs')
        && str_contains($inventoryPage, 'Parent Needs')
        && str_contains($inventoryPage, 'Child Dependency Needs'),
    'The simplified three-view inventory workflow is incomplete.'
);
federation_inventory_expect(
    !str_contains($inventoryPage, 'Full child inventory')
        && !str_contains($inventoryPage, 'Already on parent')
        && !str_contains($inventoryPage, 'Recent downloads from this child'),
    'Old inventory-page clutter is still present.'
);
federation_inventory_expect(
    str_contains($inventoryPage, 'The child cannot download anything else.'),
    'Child dependency-only download policy is not explained on the inventory page.'
);
federation_inventory_expect(
    str_contains($inventoryPage, 'onchange="this.form.submit()"')
        && !str_contains($inventoryPage, '>Open child<'),
    'Selecting a child does not open it automatically or the old Open child button remains.'
);
federation_inventory_expect(
    !str_contains($inventoryPage, 'catalog_federation_links()')
        && str_contains($inventoryPage, "'Child Inventory',\n        'Select a child and open one of the three file-need views.',\n        []"),
    'The Child Inventory header still contains shortcut links.'
);
federation_inventory_expect(
    str_contains($inventoryPage, 'federation_pull_inventory_from_child($db, $peerId)')
        && str_contains($inventoryPage, 'federation_request_child_refresh_parent_inventory($db, $peerId)')
        && str_contains($inventoryPage, 'Refresh both inventories now'),
    'The manual refresh does not update both parent and child inventory caches.'
);
federation_inventory_expect(
    str_contains($inventoryRefreshLib, 'federation_request_child_refresh_parent_inventory')
        && str_contains($inventoryRefreshLib, '/api/federation/inventory-refresh.php'),
    'The parent cannot request a child-side parent inventory refresh.'
);
federation_inventory_expect(
    str_contains($inventoryRefreshApi, "$localRole !== 'child' || $peerRole !== 'parent'")
        && str_contains($inventoryRefreshApi, 'federation_pull_inventory_from_parent($db, (int)$peer[\'id\'])'),
    'The child-side refresh endpoint does not enforce roles or pull the parent inventory.'
);
federation_inventory_expect(
    str_contains($inventoryLib, 'federation_pull_inventory_from_peer')
        && str_contains($inventoryLib, 'federation_sync_due_inventories')
        && str_contains($inventoryLib, "inventory_sync_interval_hours', '24"),
    'Bidirectional scheduled inventory synchronization is incomplete.'
);
federation_inventory_expect(
    str_contains($inventoryApi, '$localRole === \'parent\' && $peerRole === \'child\'')
        && str_contains($inventoryApi, '$localRole === \'child\' && $peerRole === \'parent\''),
    'Inventory API does not enforce paired opposite roles.'
);
federation_inventory_expect(
    str_contains($settings, 'Automatic inventory refresh interval, hours')
        && str_contains($settings, 'child transfers still require an approved missing-dependency request'),
    'Inventory interval or child download authority setting text is missing.'
);
federation_inventory_expect(
    str_contains($worker, 'federation_sync_due_inventories($db)')
        && str_contains($cron, 'federation_sync_due_inventories($db)'),
    'Federation workers do not refresh due inventories.'
);

echo "Federation inventory workflow contract tests passed.\n";
