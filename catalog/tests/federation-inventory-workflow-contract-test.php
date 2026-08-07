<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies federation inventory workflow behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function federation_inventory_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$inventoryPage = file_get_contents($root . '/federation/inventories.php');
$inventoryLib = file_get_contents($root . '/lib/FederationInventory.php');
$inventoryRefreshLib = file_get_contents($root . '/lib/FederationInventoryRefresh.php');
$inventoryApi = file_get_contents($root . '/api/federation/inventory-list.php');
$inventoryRefreshApi = file_get_contents($root . '/api/federation/inventory-refresh.php');
$settings = file_get_contents($root . '/federation/settings.php');
$diagnostics = file_get_contents($root . '/federation/diagnostics.php');
$cron = file_get_contents($root . '/federation/cron-worker-streaming.php');

foreach (compact('inventoryPage', 'inventoryLib', 'inventoryRefreshLib', 'inventoryApi', 'inventoryRefreshApi', 'settings', 'diagnostics', 'cron') as $label => $value) {
    federation_inventory_expect(is_string($value), 'Missing federation inventory component: ' . $label);
}

federation_inventory_expect(str_contains($inventoryPage, 'const FI_PARENT_PAGE_SIZE = 100;'), 'Parent inventory page size is not 100.');
federation_inventory_expect(str_contains($inventoryPage, 'const FI_CHILD_PAGE_SIZE = 950;'), 'Child required-package page size is not 950.');
federation_inventory_expect(str_contains($inventoryPage, 'Required by Parent') && str_contains($inventoryPage, 'Missing from Parent'), 'Parent inventory tabs are incomplete.');
federation_inventory_expect(str_contains($inventoryPage, 'Child required files'), 'Child required-file view is missing.');
federation_inventory_expect(str_contains($inventoryPage, 'Refresh both inventories now'), 'Parent inventory refresh is not bidirectional.');
federation_inventory_expect(str_contains($inventoryPage, 'Refresh Parent inventory now'), 'Child cannot refresh Parent inventory.');
federation_inventory_expect(str_contains($inventoryPage, 'GUID / MD5 / SHA1'), 'Inventory identity fields are not combined.');
federation_inventory_expect(str_contains($inventoryPage, 'Needed by Parent files'), 'Parent dependency use count is missing.');
federation_inventory_expect(str_contains($inventoryPage, 'COUNT(DISTINCT needer.id)'), 'Parent dependency use count is not distinct.');
federation_inventory_expect(str_contains($inventoryPage, 'data-check-all="inventory-files"'), 'Parent inventory check-all is missing.');
federation_inventory_expect(str_contains($inventoryPage, 'data-check-all="required-packages"'), 'Child required-package check-all is missing.');
federation_inventory_expect(str_contains($inventoryPage, "['package_statuses' => true]"), 'Child inventory does not fetch active request status.');
federation_inventory_expect(str_contains($inventoryPage, 'already requested'), 'Active duplicate requests are not suppressed.');

federation_inventory_expect(str_contains($inventoryRefreshLib, 'federation_request_child_refresh_parent_inventory'), 'Parent cannot request a Child-side Parent inventory refresh.');
federation_inventory_expect(str_contains($inventoryRefreshApi, "\$localRole !== 'child' || \$peerRole !== 'parent'"), 'Child-side inventory refresh role checks are missing.');
federation_inventory_expect(str_contains($inventoryLib, 'federation_sync_due_inventories'), 'Scheduled inventory synchronization is missing.');
federation_inventory_expect(str_contains($inventoryApi, "\$localRole === 'parent' && \$peerRole === 'child'"), 'Parent-to-Child inventory authorization is missing.');
federation_inventory_expect(str_contains($inventoryApi, "\$localRole === 'child' && \$peerRole === 'parent'"), 'Child-to-Parent inventory authorization is missing.');
federation_inventory_expect(str_contains($settings, 'Automatic refresh interval, hours'), 'Inventory refresh interval setting is missing.');
federation_inventory_expect(str_contains($diagnostics, 'federation_sync_due_inventories($db)'), 'Manual worker does not refresh due inventories.');
federation_inventory_expect(str_contains($cron, 'federation_sync_due_inventories($db)'), 'Scheduled worker does not refresh due inventories.');

echo "Federation inventory workflow contract tests passed.\n";
