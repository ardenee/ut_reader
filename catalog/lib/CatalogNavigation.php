<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog navigation.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

/**
 * User-facing administrator pages grouped for the global header.
 * Detail, download-stream and POST-only action endpoints are intentionally omitted.
 *
 * @return array<string,array<string,string>>
 */
function catalog_admin_navigation_groups(string $root): array
{
    $groups = [
        'Admin' => [
            'Dashboard' => $root . 'dashboard.php',
            'Setup' => $root . 'setup.php',
            'Program Settings' => $root . 'program-settings.php',
            'Library' => $root . 'library.php',
            'Game Browser' => $root . 'games.php',
            'Search' => $root . 'index.php?page=search',
            'Game Admin' => $root . 'game-manager.php',
            'Game Backups' => $root . 'game-backups.php',
            'PAK Archives' => $root . 'paks.php',
            'UPK Packages' => $root . 'upks.php',
            'Game Profiles' => $root . 'game-profiles.php',
            'Public Access & Mail' => $root . 'public-access-settings.php',
            'File Feedback' => $root . 'file-feedback-admin.php',
            'Administrator Security' => $root . 'admin-security.php',
        ],
        'Catalog' => [
            'Missing Dependencies' => $root . 'missing.php',
            'Possible Misnamed Files' => $root . 'possible-misnamed-files.php',
            'Cross-Game Dependencies' => $root . 'dependency-cross-examine.php',
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
            'Upload Bucket' => $root . 'upload-bucket-v2.php',
            'Upload Issues' => $root . 'upload-issues.php',
            'PAK Import' => $root . 'pak-import.php',
            'PAK Archives' => $root . 'paks.php',
            'Game Backups' => $root . 'game-backups.php',
            'Storage Audit' => $root . 'storage-audit.php',
        ],
        'Maintenance' => [
            'Background Jobs' => $root . 'background-jobs.php',
            'Job Resource Limits' => $root . 'job-resource-limits.php',
            'Job Logging' => $root . 'job-logging.php',
            'Live Contention' => $root . 'live-contention.php',
            'System Errors' => $root . 'system-errors.php',
            'Basic Page Audit' => $root . 'basic-performance-audit.php',
            'Exact Count Telemetry' => $root . 'query-telemetry.php',
            'Performance Readiness' => $root . 'performance-readiness.php',
            'Workload Tracing' => $root . 'workload-tracing.php',
            'Full Sync' => $root . 'full-sync.php',
            'Dependency Refresh' => $root . 'dependency-refresh.php',
            'Asset Metadata Rebuild' => $root . 'asset-metadata-rebuild.php',
            'Source Identity Repair' => $root . 'source-identity-repair.php',
            'Package Normalizer' => $root . 'package-normalize.php',
            'GUID Normalizer' => $root . 'guid-normalize.php',
            'Maintenance Locks' => $root . 'maintenance-locks.php',
        ],
        'Download' => [
            'Download Administration' => $root . 'download-admin.php',
            'Download Settings' => $root . 'downloads-settings.php',
            'Download Logs' => $root . 'download-logs.php',
            'Transfers' => $root . 'transfers.php',
            'Package Export Settings' => $root . 'download-package-settings.php',
            'Mirror Providers' => $root . 'mirror-providers.php',
            'Mirror Links' => $root . 'mirror-links.php',
            'Mirror Queue' => $root . 'mirror-queue.php',
        ],
        'Federation' => [
            'Overview' => $root . 'federation/admin.php',
            'Connections' => $root . 'federation/connections.php',
            'Inventories' => $root . 'federation/inventories.php',
            'File Requests' => $root . 'federation/requests.php',
            'Transfers' => $root . 'federation/queue.php',
            'Settings' => $root . 'federation/settings.php',
            'Diagnostics' => $root . 'federation/diagnostics.php',
        ],
    ];

    static $clientNavigationLoaded = false;
    if (!$clientNavigationLoaded && function_exists('catalog_h')) {
        $scriptPath = __DIR__ . '/../assets/catalog-navigation.js';
        $scriptVersion = is_file($scriptPath) ? (string)filemtime($scriptPath) : '1';
        $encodedGroups = json_encode(
            $groups,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encodedGroups)) {
            $encodedGroups = '{}';
        }
        echo '<script>window.UnrealDbAdminNavigation=' . $encodedGroups . ';</script>';
        echo '<script src="' . catalog_h($root . 'assets/catalog-navigation.js?v=' . $scriptVersion) . '"></script>';

        $errorScriptPath = __DIR__ . '/../assets/catalog-system-errors.js';
        $errorScriptVersion = is_file($errorScriptPath) ? (string)filemtime($errorScriptPath) : '1';
        echo '<script src="' . catalog_h($root . 'assets/catalog-system-errors.js?v=' . $errorScriptVersion) . '" defer></script>';

        $currentScript = basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
        if ($currentScript === 'game-backups.php') {
            $backupResultScript = __DIR__ . '/../assets/game-backup-results.js';
            $backupResultVersion = is_file($backupResultScript) ? (string)filemtime($backupResultScript) : '1';
            echo '<script src="' . catalog_h($root . 'assets/game-backup-results.js?v=' . $backupResultVersion) . '"></script>';

            $backupGameScript = __DIR__ . '/../assets/game-backup-job-game.js';
            $backupGameVersion = is_file($backupGameScript) ? (string)filemtime($backupGameScript) : '1';
            echo '<script src="' . catalog_h($root . 'assets/game-backup-job-game.js?v=' . $backupGameVersion) . '"></script>';
        }
        $clientNavigationLoaded = true;
    }

    return $groups;
}
