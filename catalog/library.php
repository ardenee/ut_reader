<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/BaseGameProtection.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    catalog_head('Library');

    $stats = new PdoGameCatalogStats($db);
    if ($stats->available()) {
        $stats->refreshStale(300);
        $games = catalog_all(
            $db,
            'SELECT g.id,g.name,g.slug,g.description,p.engine_key profile_engine,'
            . 'COALESCE(s.file_count,0) file_count,COALESCE(s.verified_count,0) verified_count,'
            . 'COALESCE(s.failed_count,0) failed_count,'
            . 'COALESCE(s.missing_dependency_count,0) missing_dependency_count,'
            . 'COALESCE(s.missing_base_game_dependency_count,0) missing_base_game_dependency_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id '
            . 'ORDER BY g.name'
        );
        $global = $stats->global();
        $totalFiles = (int)($global['file_count'] ?? 0);
        $verified = (int)($global['verified_count'] ?? 0);
        $duplicates = (int)($global['duplicate_count'] ?? 0);
        $missingDependencies = (int)($global['missing_dependency_count'] ?? 0);
        $missingBaseGameDependencies = (int)($global['missing_base_game_dependency_count'] ?? 0);
    } else {
        $baseGameDependencySql = base_game_dependency_is_official_sql('df', 'd');
        $games = catalog_all(
            $db,
            'SELECT g.id, g.name, g.slug, g.description, p.engine_key profile_engine, '
            . 'COUNT(DISTINCT f.id) file_count, '
            . 'COALESCE(SUM(f.scan_status="verified"),0) verified_count, '
            . 'COALESCE(SUM(f.scan_status="failed"),0) failed_count, '
            . '(SELECT COUNT(*) FROM ue_dependencies d JOIN ue_files df ON df.id=d.file_id WHERE d.status="missing" AND df.game_id=g.id) missing_dependency_count, '
            . '(SELECT COUNT(*) FROM ue_dependencies d JOIN ue_files df ON df.id=d.file_id WHERE d.status="missing" AND df.game_id=g.id AND ' . $baseGameDependencySql . ') missing_base_game_dependency_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_files f ON f.game_id=g.id '
            . 'GROUP BY g.id, p.id ORDER BY g.name'
        );
        $totalFiles = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files');
        $verified = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="verified"');
        $duplicates = catalog_count($db, 'SELECT COUNT(*) c FROM ue_files WHERE scan_status="duplicate"');
        $missingDependencies = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"');
        $missingBaseGameDependencies = catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_dependencies d '
            . 'JOIN ue_files df ON df.id=d.file_id '
            . 'WHERE d.status="missing" AND ' . $baseGameDependencySql
        );
    }

    catalog_page_header('Library', 'Browse local games and files, review duplicates, and check missing dependency status.', ['Games' => 'games.php', 'Search' => 'index.php?page=search', 'Duplicates' => 'duplicates.php', 'Missing Files' => 'missing.php']);

    echo '<div class="grid">';
    catalog_stat_card('Total files', $totalFiles);
    catalog_stat_card('Verified files', $verified, '', 'good');
    catalog_stat_card('Duplicate rows', $duplicates);
    catalog_stat_card('Missing dependencies', $missingDependencies, 'All missing dependency object rows, including official base-game dependencies.', $missingDependencies > 0 ? 'attention' : 'good');
    catalog_stat_card('Missing base-game dependencies', $missingBaseGameDependencies, 'Missing dependency rows that reference official base-game packages.', $missingBaseGameDependencies > 0 ? 'attention' : 'good');
    catalog_stat_card('Games', count($games));
    echo '</div>';

    echo '<div class="card"><h2>Library tools</h2><div class="grid">';
    catalog_tool_card('Game browser', 'games.php', 'Browse the public game catalog and open file lists.', 'primary');
    catalog_tool_card('Search catalog', 'index.php?page=search', 'Search package names, filenames, hashes, GUIDs, imports, and exports.');
    catalog_tool_card('Duplicate files', 'duplicates.php', 'Review duplicate files and package GUID collisions.');
    $missingToolDescription = 'Review dependency gaps and request files from a parent site.';
    if ($missingBaseGameDependencies > 0) {
        $missingToolDescription .= ' ' . number_format($missingBaseGameDependencies) . ' row(s) reference official base-game packages.';
    }
    catalog_tool_card('Missing files', 'missing.php', $missingToolDescription, $missingDependencies > 0 ? (string)$missingDependencies : '');
    echo '</div></div>';

    echo '<div class="card"><h2>Games</h2>';
    if (!$games) {
        echo '<p class="muted">No games configured yet. Start in <a href="game-manager.php">Game Admin</a>.</p>';
    } else {
        echo '<table><tr><th>Game</th><th>Profile engine</th><th>Files</th><th>Verified</th><th>Failed</th><th>Missing dependencies</th><th>Missing base-game dependencies</th><th>Open</th></tr>';
        foreach ($games as $game) {
            $engine = $game['profile_engine'] ?: 'missing profile';
            $engineClass = $game['profile_engine'] ? 'good-pill' : 'bad-pill';
            $missingCount = (int)($game['missing_dependency_count'] ?? 0);
            $baseGameMissingCount = (int)($game['missing_base_game_dependency_count'] ?? 0);
            $missingClass = $missingCount > 0 ? 'amber' : 'good-pill';
            $baseGameMissingClass = $baseGameMissingCount > 0 ? 'amber' : 'good-pill';
            echo '<tr><td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td><td><span class="pill ' . $engineClass . '">' . catalog_h($engine) . '</span></td><td>' . number_format((int)$game['file_count']) . '</td><td>' . number_format((int)$game['verified_count']) . '</td><td>' . number_format((int)$game['failed_count']) . '</td><td><a href="missing.php" title="All missing dependency object rows, including base-game dependencies"><span class="pill ' . $missingClass . '">' . number_format($missingCount) . '</span></a></td><td><a href="missing.php" title="Missing dependency rows that reference official base-game packages"><span class="pill ' . $baseGameMissingClass . '">' . number_format($baseGameMissingCount) . '</span></a></td><td><a class="button" href="game-files.php?id=' . (int)$game['id'] . '">Files</a> <a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">Upload</a> <a class="button" href="game-manager.php?game_id=' . (int)$game['id'] . '">Edit</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Library error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
