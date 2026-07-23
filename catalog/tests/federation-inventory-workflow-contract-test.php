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
$inventoryApi = file_get_contents($root . '/api/federation/inventory-list.php');
$settings = file_get_contents($root . '/federation/settings.php');
$worker = file_get_contents($root . '/federation/worker-run.php');
$cron = file_get_contents($root . '/federation/cron-worker-streaming.php');

foreach ([
    'inventory page' => $inventoryPage,
    'inventory library' => $inventoryLib,
    'inventory API' => $inventoryApi,
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
