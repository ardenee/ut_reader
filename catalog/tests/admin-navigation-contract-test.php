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
admin_navigation_expect(array_keys($groups) === [
    'Admin',
    'Catalog',
    'Imports',
    'Maintenance',
    'Downloads',
    'Federation',
], 'Administrator navigation groups are missing or out of order.');
admin_navigation_expect(
    ($groups['Admin']['Game Backups'] ?? '') === 'game-backups.php',
    'The main Admin menu does not link to Game Backups.'
);
admin_navigation_expect(
    ($groups['Imports']['Game Backups'] ?? '') === 'game-backups.php',
    'The Imports menu does not link to Game Backups.'
);
admin_navigation_expect(
    ($groups['Admin']['PAK Archives'] ?? '') === 'paks.php',
    'The main Admin menu does not link to PAK Archives.'
);
admin_navigation_expect(
    ($groups['Imports']['PAK Archives'] ?? '') === 'paks.php',
    'The Imports menu does not link to PAK Archives.'
);

$expectedPages = [
    'dashboard.php',
    'setup.php',
    'library.php',
    'games.php',
    'index.php?page=search',
    'game-manager.php',
    'game-profiles.php',
    'admin-security.php',
    'missing.php',
    'duplicates.php',
    'unverified-files.php',
    'unverified-database-import.php',
    'base-game-files.php',
    'legacy-data-audit.php',
    'sources.php',
    'source-scan.php',
    'http-source-scan.php',
    'profiled-upload.php',
    'upload-bucket.php',
    'pak-import.php',
    'paks.php',
    'game-backups.php',
    'storage-audit.php',
    'background-jobs.php',
    'full-sync.php',
    'dependency-refresh.php',
    'asset-metadata-rebuild.php',
    'source-identity-repair.php',
    'package-normalize.php',
    'guid-normalize.php',
    'maintenance-locks.php',
    'transfers.php',
    'download-admin.php',
    'download-package-settings.php',
    'mirror-providers.php',
    'mirror-links.php',
    'mirror-queue.php',
    'federation/admin.php',
    'federation/join-main-parent.php',
    'federation/settings.php',
    'federation/peers.php',
    'federation/peer-inventory.php',
    'federation/requests.php',
    'federation/approved-downloads.php',
    'federation/join-requests.php',
    'federation/queue.php',
    'federation/worker-run.php',
    'federation/conflicts.php',
    'federation/maintenance.php',
    'federation/logs.php',
    'federation/docs.php',
    'federation/parent-pull.php',
    'federation/inventory-push.php',
    'federation/upload-to-parent.php',
    'federation/claim-parent.php',
];

$actualPages = [];
foreach ($groups as $label => $links) {
    admin_navigation_expect($links !== [], 'Administrator navigation group is empty: ' . $label);
    foreach ($links as $title => $href) {
        $actualPages[] = $href;
        $path = parse_url($href, PHP_URL_PATH);
        admin_navigation_expect(is_string($path) && $path !== '', 'Navigation link has no path: ' . $title);
        admin_navigation_expect(
            is_file(__DIR__ . '/../' . ltrim($path, '/')),
            'Navigation points to a missing page: ' . $href
        );
    }
}

foreach ($expectedPages as $page) {
    admin_navigation_expect(in_array($page, $actualPages, true), 'Administrator navigation is missing page: ' . $page);
}

$core = file_get_contents(__DIR__ . '/../lib/CatalogSupportCore.php');
admin_navigation_expect(is_string($core), 'Could not read CatalogSupportCore.php.');
admin_navigation_expect(
    str_contains($core, "require_once __DIR__ . '/CatalogNavigation.php';"),
    'CatalogSupportCore.php does not load the centralized navigation definition.'
);
admin_navigation_expect(
    str_contains($core, 'foreach (catalog_admin_navigation_groups($root) as $label => $links)'),
    'The global header is not rendering every centralized navigation group.'
);
admin_navigation_expect(
    str_contains($core, 'data-admin-menu='),
    'Server-rendered dropdowns do not expose the admin-menu hook.'
);

$script = file_get_contents(__DIR__ . '/../assets/catalog-ui.js');
admin_navigation_expect(is_string($script), 'Could not read catalog admin navigation script.');
foreach ([
    "details.addEventListener('toggle'",
    'closeNavigationMenus(nav, details)',
    "event.key !== 'Escape'",
    "event.target.closest('nav.primary-nav details[data-admin-menu]')",
    "event.target.closest('.nav-menu a')",
] as $behaviour) {
    admin_navigation_expect(str_contains($script, $behaviour), 'Admin dropdown behaviour is missing: ' . $behaviour);
}
admin_navigation_expect(
    str_contains($script, 'max-height: min(72vh, 640px)'),
    'Long admin dropdowns are not height-limited and scrollable.'
);

echo "Admin navigation contract tests passed.\n";
