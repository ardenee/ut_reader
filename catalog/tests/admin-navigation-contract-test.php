<?php
declare(strict_types=1);

function admin_navigation_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../lib/CatalogNavigation.php';

$groups = catalog_admin_navigation_groups('');
admin_navigation_expect(array_keys($groups) === ['Admin', 'Catalog', 'Imports', 'Maintenance', 'Downloads', 'Federation'], 'Administrator navigation groups are missing or out of order.');
admin_navigation_expect(($groups['Admin']['Game Backups'] ?? '') === 'game-backups.php', 'Admin navigation is missing Game Backups.');
admin_navigation_expect(($groups['Imports']['Game Backups'] ?? '') === 'game-backups.php', 'Imports navigation is missing Game Backups.');

$expectedFederation = [
    'Overview' => 'federation/admin.php',
    'Connections' => 'federation/connections.php',
    'Inventories' => 'federation/inventories.php',
    'File Requests' => 'federation/requests.php',
    'Transfers' => 'federation/queue.php',
    'Settings' => 'federation/settings.php',
    'Diagnostics' => 'federation/diagnostics.php',
];
admin_navigation_expect(($groups['Federation'] ?? []) === $expectedFederation, 'Federation navigation is not the seven-page consolidated menu.');

foreach ($groups as $label => $links) {
    admin_navigation_expect($links !== [], 'Administrator navigation group is empty: ' . $label);
    foreach ($links as $title => $href) {
        $path = parse_url($href, PHP_URL_PATH);
        admin_navigation_expect(is_string($path) && $path !== '', 'Navigation link has no path: ' . $title);
        admin_navigation_expect(is_file(__DIR__ . '/../' . ltrim($path, '/')), 'Navigation points to a missing page: ' . $href);
    }
}

$obsolete = [
    'join-main-parent.php', 'join-requests.php', 'peers.php', 'peer-inventory.php',
    'request-generate.php', 'request-status.php', 'approved-downloads.php',
    'parent-pull.php', 'request-center.php', 'missing-files.php', 'worker-run.php',
    'conflicts.php', 'maintenance.php', 'logs.php', 'docs.php', 'claim-parent.php',
    'inventory-push.php', 'upload-to-parent.php', 'transfer-run.php', 'import-run.php',
];
foreach ($obsolete as $page) {
    foreach ($expectedFederation as $href) {
        admin_navigation_expect(!str_contains($href, $page), 'Obsolete federation page remains in navigation: ' . $page);
    }
}

$core = file_get_contents(__DIR__ . '/../lib/CatalogSupportCore.php');
admin_navigation_expect(is_string($core) && str_contains($core, "require_once __DIR__ . '/CatalogNavigation.php';"), 'CatalogSupportCore.php does not load centralized navigation.');
admin_navigation_expect(str_contains($core, 'foreach (catalog_admin_navigation_groups($root) as $label => $links)'), 'The global header is not rendering centralized navigation.');

$navigationSource = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
admin_navigation_expect(is_string($navigationSource) && str_contains($navigationSource, 'window.UnrealDbAdminNavigation='), 'Centralized navigation is not exported to the browser.');

$script = file_get_contents(__DIR__ . '/../assets/catalog-navigation.js');
admin_navigation_expect(is_string($script), 'Could not read catalog-navigation.js.');
admin_navigation_expect(str_contains($script, 'window.UnrealDbAdminNavigation'), 'Client navigation does not consume the centralized server menu.');
admin_navigation_expect(str_contains($script, "details[data-admin-menu]"), 'Client navigation does not restore the complete administrator menu.');
foreach ($obsolete as $page) {
    admin_navigation_expect(!str_contains($script, $page), 'Client navigation still contains obsolete federation page: ' . $page);
}

 echo "Admin navigation contract tests passed.\n";
