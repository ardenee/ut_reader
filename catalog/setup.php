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
    $profiles = catalog_count($db, 'SELECT COUNT(*) c FROM ue_game_profiles WHERE is_active=1');
    $sources = catalog_count($db, 'SELECT COUNT(*) c FROM ue_sources');
    $files = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files');
    $failed = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="failed"');

    echo '<div class="card hero"><h1>Setup</h1><p class="muted">First-time setup and file ingestion: add games, configure scanner profiles, upload or scan files, then move to Missing Files.</p>';
    catalog_page_links(['Profiled Upload' => 'profiled-upload.php', 'Game Profiles' => 'game-profiles.php', 'Storage Sources' => 'sources.php', 'Scan Local' => 'source-scan.php', 'Scan HTTP' => 'http-source-scan.php', 'Missing Files' => 'missing.php']);
    echo '</div>';

    echo '<div class="grid">';
    catalog_stat_card('Configured games', $games, $games === 0 ? 'Add a game first' : '', $games === 0 ? 'attention' : 'good');
    catalog_stat_card('Active game profiles', $profiles, $profiles === 0 ? 'Import update 012 and configure profiles' : '', $profiles === 0 ? 'attention' : 'good');
    catalog_stat_card('Storage/source locations', $sources, $sources === 0 ? 'Optional, but useful for server folders' : '');
    catalog_stat_card('Files in catalog', $files);
    catalog_stat_card('Failed scans', $failed, 'Review/import issues', $failed > 0 ? 'warning' : '');
    echo '</div>';

    echo '<div class="card"><h2>Setup workflow</h2><div class="grid">';
    catalog_tool_card('1. Game profiles', 'game-profiles.php', 'Define game name, engine family, allowed extensions, and known package version ranges.', 'new');
    catalog_tool_card('2. Profiled upload scanner', 'profiled-upload.php', 'Upload files with engine/version/profile checks before importing as verified.', 'primary');
    catalog_tool_card('3. Legacy game admin', 'index.php?page=admin', 'Older page for creating games and direct uploads. Prefer profiled upload for scanning.', 'legacy');
    catalog_tool_card('4. Add storage locations', 'sources.php', 'Register local folders, server paths, HTTP mirrors, or redirect sources.');
    catalog_tool_card('5. Scan local storage', 'source-scan.php', 'Scan configured local paths and link files by MD5/GUID.');
    catalog_tool_card('6. Scan HTTP/redirect source', 'http-source-scan.php', 'Scan remote manifests or redirect-server sources.');
    catalog_tool_card('7. Browse imported files', 'library.php', 'Check game/library status after import.');
    catalog_tool_card('8. Identify missing files', 'missing.php', 'Move to dependency repair after scanning.');
    echo '</div></div>';

    echo '<div class="card"><h2>Scanner profile note</h2><p class="muted">Profiles are intentionally data-driven so more Unreal Engine games, including future UE5 games, can be added without rewriting scanner logic. Header/version detection is used for high-confidence engine-family checks; exact same-engine game routing still needs admin choice unless future signatures are added.</p></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Setup error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
