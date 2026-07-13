<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogDashboardStats.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Dashboard');

    if (!catalog_support_is_admin()) {
        catalog_page_header('UnrealDB', 'Browse Unreal package files and dependency information.', ['Games' => 'games.php', 'Search' => 'index.php?page=search', 'Admin Login' => 'index.php?page=login']);
        catalog_foot();
        exit;
    }

    $stats = CatalogDashboardStats::load($db);

    catalog_page_header('Dashboard', 'Start here: setup files, identify missing packages, connect to a parent, request downloads, and monitor background work.', ['Setup' => 'setup.php', 'Missing Files' => 'missing.php', 'Join Main Parent' => 'federation/join-main-parent.php', 'Run Worker' => 'transfers.php']);

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

    echo '<div class="two-col">';
    echo '<div class="card"><h2>Primary workflow</h2><div class="grid">';
    catalog_tool_card('1. Setup library', 'setup.php', 'Add games, upload files, add storage locations, and scan local/HTTP sources.', 'start');
    catalog_tool_card('2. Browse library', 'library.php', 'View games/files, search package metadata, and inspect file details.');
    catalog_tool_card('3. Missing files', 'missing.php', 'Review missing dependencies and request files from a parent site.', $stats['missing'] > 0 ? (string)$stats['missing'] : 'ok');
    catalog_tool_card('4. Federation', 'federation/admin.php', 'Join the main parent, manage peers, push inventory, and handle requests.');
    catalog_tool_card('5. Transfers', 'transfers.php', 'Monitor queued downloads/uploads/imports and run the worker.');
    catalog_tool_card('6. Downloads', 'download-admin.php', 'Control public download mode and external shared-provider links.');
    catalog_tool_card('7. Base game protection', 'base-game-files.php', 'Seed official/base game GUIDs and block them from download, federation transfer, and ZIP bundles.');
    echo '</div></div>';

    echo '<div class="card"><h2>Needs attention</h2>';
    if ($stats['missing'] === 0 && $stats['fedFailed'] === 0 && $stats['mirrorWaiting'] === 0 && $stats['joinPending'] === 0 && $stats['fedDownloaded'] === 0) {
        echo '<p class="muted">No major issues currently flagged.</p>';
    } else {
        echo '<ul>';
        if ($stats['missing'] > 0) echo '<li><a href="missing.php">Missing dependencies need review.</a></li>';
        if ($stats['fedDownloaded'] > 0) echo '<li><a href="transfers.php">Downloaded federation files are waiting for import.</a></li>';
        if ($stats['fedFailed'] > 0) echo '<li><a href="federation/queue.php">Failed federation jobs need review.</a></li>';
        if ($stats['mirrorWaiting'] > 0) echo '<li><a href="mirror-queue.php">Mirror jobs need admin fulfilment/worker action.</a></li>';
        if ($stats['joinPending'] > 0) echo '<li><a href="federation/join-requests.php">Pending child join requests need approval.</a></li>';
        echo '</ul>';
    }
    echo '</div></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Dashboard error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
