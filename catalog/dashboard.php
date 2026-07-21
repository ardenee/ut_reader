<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogDashboardStats.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    catalog_head('Dashboard');

    if (!catalog_support_is_admin()) {
        catalog_page_header('UnrealDB', 'Browse Unreal package files and dependency information.', ['Games' => 'games.php', 'Search' => 'index.php?page=search', 'Admin Login' => 'index.php?page=login']);
        catalog_foot();
        exit;
    }

    $stats = CatalogDashboardStats::load($db);
    catalog_page_header(
        'Dashboard',
        'Start here: setup files, identify missing packages, create game backups, connect to a parent, request downloads, and monitor background work.',
        ['Setup' => 'setup.php', 'Game Backups' => 'game-backups.php', 'Administrator Security' => 'admin-security.php', 'Missing Files' => 'missing.php', 'Join Main Parent' => 'federation/join-main-parent.php', 'Background Jobs' => 'background-jobs.php']
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
    catalog_tool_card('3. Missing files', 'missing.php', 'Review missing dependencies and request files from a parent site.', $stats['missing'] > 0 ? (string)$stats['missing'] : 'ok');
    catalog_tool_card('4. Game backups', 'game-backups.php', 'Create full-copy game exports with original filenames and restore them on this or another site.', 'backup');
    catalog_tool_card('5. Federation', 'federation/admin.php', 'Join the main parent, manage peers, push inventory, and handle requests.');
    catalog_tool_card('6. Background jobs', 'background-jobs.php', 'Monitor uploads and maintenance jobs; start or stop the detached worker without SSH.', 'primary');
    catalog_tool_card('7. Transfers', 'transfers.php', 'Monitor federation downloads/uploads/imports and mirror work.');
    catalog_tool_card('8. Downloads', 'download-admin.php', 'Control public download mode and external shared-provider links.');
    catalog_tool_card('9. Base game protection', 'base-game-files.php', 'Seed official/base game GUIDs and block them from download, federation transfer, and ZIP bundles.');
    catalog_tool_card('10. Administrator security', 'admin-security.php', 'Enable MFA, generate recovery codes, and renew recent authentication for sensitive actions.');
    echo '</div></div>';

    echo '<div class="card"><h2>Needs attention</h2>';
    if ($stats['missing'] === 0 && $stats['fedFailed'] === 0 && $stats['mirrorWaiting'] === 0 && $stats['joinPending'] === 0 && $stats['fedDownloaded'] === 0) {
        echo '<p class="muted">No major issues currently flagged.</p>';
    } else {
        echo '<ul>';
        if ($stats['missing'] > 0) echo '<li><a href="missing.php">Missing dependencies need review.</a></li>';
        if ($stats['fedDownloaded'] > 0) echo '<li><a href="transfers.php">Downloaded federation files are waiting for import.</a></li>';
        if ($stats['fedFailed'] > 0) echo '<li><a href="federation/queue.php">Failed federation jobs need review.</a></li>';
        if ($stats['mirrorWaiting'] > 0) echo '<li><a href="mirror-queue.php">Mirror jobs need administrator fulfilment or worker action.</a></li>';
        if ($stats['joinPending'] > 0) echo '<li><a href="federation/join-requests.php">Pending child join requests need approval.</a></li>';
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
