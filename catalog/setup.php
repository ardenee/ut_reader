<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function setup_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Setup');

    if (!setup_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $games = catalog_count($db, 'SELECT COUNT(*) c FROM ue_games');
    $sources = catalog_count($db, 'SELECT COUNT(*) c FROM ue_sources');
    $files = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files');
    $failed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="failed"');

    echo '<div class="card hero"><h1>Setup</h1><p class="muted">First-time setup and file ingestion: add games, add storage/source locations, upload or scan files, then move to Missing Files.</p>';
    catalog_page_links(['Upload / Game Admin' => 'index.php?page=admin', 'Storage Sources' => 'sources.php', 'Scan Local' => 'source-scan.php', 'Scan HTTP' => 'http-source-scan.php', 'Missing Files' => 'missing.php']);
    echo '</div>';

    echo '<div class="grid">';
    catalog_stat_card('Configured games', $games, $games === 0 ? 'Add a game first' : '', $games === 0 ? 'attention' : 'good');
    catalog_stat_card('Storage/source locations', $sources, $sources === 0 ? 'Optional, but useful for server folders' : '');
    catalog_stat_card('Files in catalog', $files);
    catalog_stat_card('Failed scans', $failed, 'Review/import issues', $failed > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Setup workflow</h2><div class="grid">';
    catalog_tool_card('1. Add/manage games', 'index.php?page=admin', 'Create games and upload packages directly through the legacy upload/admin page.', 'start');
    catalog_tool_card('2. Add storage locations', 'sources.php', 'Register local folders, server paths, HTTP mirrors, or redirect sources.');
    catalog_tool_card('3. Scan local storage', 'source-scan.php', 'Scan configured local paths and link files by MD5/GUID.');
    catalog_tool_card('4. Scan HTTP/redirect source', 'http-source-scan.php', 'Scan remote manifests or redirect-server sources.');
    catalog_tool_card('5. Browse imported files', 'library.php', 'Check game/library status after import.');
    catalog_tool_card('6. Identify missing files', 'missing.php', 'Move to dependency repair after scanning.');
    echo '</div></div>';

    echo '<div class="card"><h2>Notes</h2><p class="muted">The old upload/admin page is still used for direct uploads and game management. This Setup page is the cleaner entry point for the workflow.</p></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Setup error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
