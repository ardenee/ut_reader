<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for UnrealDB.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $db = catalog_db(catalog_config());
    catalog_start_session();
    catalog_head('Dashboard');

    if (!catalog_support_is_admin()) {
        catalog_page_header('UnrealDB', 'Browse Unreal package files and dependency information.', ['Games' => 'games.php', 'Search' => 'index.php?page=search', 'Admin Login' => 'index.php?page=login']);
        catalog_foot();
        exit;
    }

    /*
     * Dashboard statistics are read-only. Release the PHP session-file lock
     * before touching catalogue tables so another tab is never held behind a
     * slow dashboard query or a database stall.
     */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $statsQuery = new \UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardStatsQuery($db);
    $stats = (new \UnrealDb\Catalog\Application\Dashboard\CatalogDashboardStats($statsQuery))->load();
    catalog_page_header(
        'Dashboard',
        'Start here: setup files, identify missing packages, create game backups, manage federation, request downloads, and monitor background work.',
        ['Setup' => 'setup.php', 'System Operations' => 'system-operations.php', 'Game Backups' => 'game-backups.php', 'Administrator Security' => 'admin-security.php', 'Missing Files' => 'missing.php', 'Federation Connections' => 'federation/connections.php', 'Background Jobs' => 'background-jobs.php']
    );

    echo '<div class="grid">';
    catalog_stat_card('Games', $stats['games']);
    catalog_stat_card('Total files', $stats['files']);
    catalog_stat_card('Verified files', $stats['verified'], '', 'good');
    catalog_stat_card('Failed files', $stats['failed'], 'Need review', $stats['failed'] > 0 ? 'warning' : '');
    catalog_stat_card('Missing dependency rows', $stats['missing'], 'Core repair target', $stats['missing'] > 0 ? 'attention' : 'good');
    catalog_stat_card('Resolved dependency rows', $stats['resolved']);
    catalog_stat_card('Queued federation jobs', $stats['fedQueued']);
    catalog_stat_card('Downloaded waiting import', $stats['fedDownloaded'], '', $stats['fedDownloaded'] > 0 ? 'attention' : '');
    catalog_stat_card('Failed federation jobs', $stats['fedFailed'], '', $stats['fedFailed'] > 0 ? 'warning' : '');
    catalog_stat_card('Mirror jobs waiting', $stats['mirrorWaiting'], '', $stats['mirrorWaiting'] > 0 ? 'attention' : '');
    catalog_stat_card('Active mirror links', $stats['mirrorActive']);
    catalog_stat_card('Pending join requests', $stats['joinPending'], '', $stats['joinPending'] > 0 ? 'attention' : '');
    echo '</div>';

    echo '<div class="two-col"><div class="card"><h2>Primary workflow</h2><div class="grid">';
    catalog_tool_card('1. Setup library', 'setup.php', 'Add games, upload files, add storage locations, and scan local/HTTP sources.', 'start');
    catalog_tool_card('2. Browse library', 'library.php', 'View games/files, search package metadata, and inspect file details.');
    catalog_tool_card('3. Missing files', 'missing.php', 'Review missing dependencies and resolve them locally or through federation.', $stats['missing'] > 0 ? (string)$stats['missing'] : 'ok');
    catalog_tool_card('4. Game backups', 'game-backups.php', 'Create full-copy game exports with original filenames and restore them on this or another site.', 'backup');
    catalog_tool_card('5. Federation', 'federation/admin.php', 'Manage connections, inventories, file requests, transfers, settings and diagnostics.');
    catalog_tool_card('6. Background jobs', 'background-jobs.php', 'Monitor uploads and maintenance jobs; start or stop the detached worker without SSH.', 'primary');
    catalog_tool_card('7. System operations', 'system-operations.php', 'See worker, queue, resource-limit, database and package-storage health from one read-only console.', 'primary');
    catalog_tool_card('8. Transfers', 'transfers.php', 'Monitor federation downloads/uploads/imports and mirror work.');
    catalog_tool_card('9. Downloads', 'download-admin.php', 'Control public download mode and external shared-provider links.');
    catalog_tool_card('10. Base game protection', 'base-game-files.php', 'Seed official/base game GUIDs and block them from download, federation transfer, and ZIP bundles.');
    catalog_tool_card('11. Administrator security', 'admin-security.php', 'Enable MFA, generate recovery codes, and manage administrator authentication.');
    echo '</div></div>';

    echo '<div class="card"><h2>Needs attention</h2>';
    if ($stats['missing'] === 0 && $stats['fedFailed'] === 0 && $stats['mirrorWaiting'] === 0 && $stats['joinPending'] === 0 && $stats['fedDownloaded'] === 0) {
        echo '<p class="muted">No major issues currently flagged.</p>';
    } else {
        echo '<ul>';
        if ($stats['missing'] > 0) echo '<li><a href="missing.php">Missing dependencies need review.</a></li>';
        if ($stats['fedDownloaded'] > 0) echo '<li><a href="federation/queue.php?tab=waiting">Downloaded federation files are waiting for import.</a></li>';
        if ($stats['fedFailed'] > 0) echo '<li><a href="federation/queue.php?tab=failed">Failed federation transfers need review.</a></li>';
        if ($stats['mirrorWaiting'] > 0) echo '<li><a href="mirror-queue.php">Mirror jobs need administrator action.</a></li>';
        if ($stats['joinPending'] > 0) echo '<li><a href="federation/connections.php">Pending child join requests need approval.</a></li>';
        echo '</ul>';
    }
    echo '</div></div>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB dashboard][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) catalog_head('Dashboard error');
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Dashboard request failed.');
    catalog_foot();
}
