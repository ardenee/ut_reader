<?php
declare(strict_types=1);

/**
 * User-facing administrator pages grouped for the global header.
 * Detail, download-stream and POST-only action endpoints are intentionally omitted.
 *
 * @return array<string,array<string,string>>
 */
function catalog_admin_navigation_groups(string $root): array
{
    static $clientNavigationLoaded = false;
    if (!$clientNavigationLoaded && function_exists('catalog_h')) {
        $scriptPath = __DIR__ . '/../assets/catalog-navigation.js';
        $scriptVersion = is_file($scriptPath) ? (string)filemtime($scriptPath) : '1';
        echo '<script src="' . catalog_h($root . 'assets/catalog-navigation.js?v=' . $scriptVersion) . '"></script>';
        $clientNavigationLoaded = true;
    }

    return [
        'Admin' => [
            'Dashboard' => $root . 'dashboard.php',
            'Setup' => $root . 'setup.php',
            'Library' => $root . 'library.php',
            'Game Browser' => $root . 'games.php',
            'Search' => $root . 'index.php?page=search',
            'Game Admin' => $root . 'game-manager.php',
            'Game Backups' => $root . 'game-backups.php',
            'PAK Archives' => $root . 'paks.php',
            'UPK Packages' => $root . 'upks.php',
            'Game Profiles' => $root . 'game-profiles.php',
            'Administrator Security' => $root . 'admin-security.php',
        ],
        'Catalog' => [
            'Missing Dependencies' => $root . 'missing.php',
            'Duplicate Files' => $root . 'duplicates.php',
            'Unverified Files' => $root . 'unverified-files.php',
            'Import Existing Unverified DB' => $root . 'unverified-database-import.php',
            'Base Game Protection' => $root . 'base-game-files.php',
            'PAK Archives' => $root . 'paks.php',
            'UPK Packages' => $root . 'upks.php',
            'Legacy Data Audit' => $root . 'legacy-data-audit.php',
        ],
        'Imports' => [
            'Game Sources' => $root . 'sources.php',
            'Local Source Scan' => $root . 'source-scan.php',
            'HTTP Source Scan' => $root . 'http-source-scan.php',
            'Upload Files' => $root . 'profiled-upload.php',
            'Upload Bucket' => $root . 'upload-bucket.php',
            'PAK Import' => $root . 'pak-import.php',
            'PAK Archives' => $root . 'paks.php',
            'Game Backups' => $root . 'game-backups.php',
            'Storage Audit' => $root . 'storage-audit.php',
        ],
        'Maintenance' => [
            'Background Jobs' => $root . 'background-jobs.php',
            'Full Sync' => $root . 'full-sync.php',
            'Dependency Refresh' => $root . 'dependency-refresh.php',
            'Asset Metadata Rebuild' => $root . 'asset-metadata-rebuild.php',
            'Source Identity Repair' => $root . 'source-identity-repair.php',
            'Package Normalizer' => $root . 'package-normalize.php',
            'GUID Normalizer' => $root . 'guid-normalize.php',
            'Maintenance Locks' => $root . 'maintenance-locks.php',
        ],
        'Downloads' => [
            'Transfers' => $root . 'transfers.php',
            'Download Administration' => $root . 'download-admin.php',
            'Package Download Settings' => $root . 'download-package-settings.php',
            'Mirror Providers' => $root . 'mirror-providers.php',
            'Mirror Links' => $root . 'mirror-links.php',
            'Mirror Queue' => $root . 'mirror-queue.php',
        ],
        'Federation' => [
            'Overview' => $root . 'federation/admin.php',
            'Settings' => $root . 'federation/settings.php',
            'Connections' => $root . 'federation/peers.php',
            'Parents' => $root . 'federation/peers.php?role=parent',
            'Join a Parent' => $root . 'federation/join-main-parent.php',
            'Children' => $root . 'federation/peers.php?role=child',
            'Incoming Child Join Requests' => $root . 'federation/join-requests.php',
            'Missing Files' => $root . 'federation/missing-files.php',
            'Requests' => $root . 'federation/request-center.php',
            'Incoming File Requests' => $root . 'federation/requests.php',
            'Outgoing File Requests' => $root . 'federation/request-status.php',
            'Approved Downloads' => $root . 'federation/approved-downloads.php',
            'Child Inventories' => $root . 'federation/peer-inventory.php',
            'Parent Pull' => $root . 'federation/parent-pull.php',
            'Transfer Queue' => $root . 'federation/queue.php',
            'Run Worker' => $root . 'federation/worker-run.php',
            'Conflicts' => $root . 'federation/conflicts.php',
            'Maintenance' => $root . 'federation/maintenance.php',
            'Logs' => $root . 'federation/logs.php',
            'Documentation' => $root . 'federation/docs.php',
        ],
    ];
}
