<?php
declare(strict_types=1);

function admin_navigation_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scriptPath = __DIR__ . '/../assets/catalog-ui.js';
$script = file_get_contents($scriptPath);
admin_navigation_expect(is_string($script), 'Could not read catalog admin navigation script.');

foreach ([
    "label: 'Admin'",
    "label: 'Catalog'",
    "label: 'Imports'",
    "label: 'Maintenance'",
    "label: 'Downloads'",
    "label: 'Federation'",
] as $group) {
    admin_navigation_expect(str_contains($script, $group), 'Missing admin navigation group: ' . $group);
}

foreach ([
    'dashboard.php',
    'setup.php',
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
] as $page) {
    admin_navigation_expect(str_contains($script, "'" . $page . "'"), 'Admin navigation is missing page: ' . $page);
}

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
