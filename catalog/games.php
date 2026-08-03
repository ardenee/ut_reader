<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $stats = new PdoGameCatalogStats($db);
    if ($stats->available()) {
        // Public requests must only read the compact cache. Rebuilding stale rows can
        // take many seconds on a large catalogue and belongs in CLI/background work.
        $games = catalog_all(
            $db,
            'SELECT g.id,g.name,g.slug,g.description,p.engine_key profile_engine,'
            . 'COALESCE(s.file_count,0) file_count,COALESCE(s.total_size,0) total_size,'
            . 'COALESCE(s.missing_dependency_count,0) missing_dependency_count,'
            . 'COALESCE(s.missing_base_game_dependency_count,0) missing_base_game_dependency_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id '
            . 'ORDER BY g.name'
        );
    } else {
        // Keep the page usable without scanning the large dependency tables. Missing
        // counters remain unavailable until the compact statistics table is restored.
        $games = catalog_all(
            $db,
            'SELECT g.id,g.name,g.slug,g.description,p.engine_key profile_engine,'
            . 'COUNT(f.id) file_count,COALESCE(SUM(f.file_size),0) total_size,'
            . 'NULL missing_dependency_count,NULL missing_base_game_dependency_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'LEFT JOIN ue_files f ON f.game_id=g.id '
            . 'GROUP BY g.id,g.name,g.slug,g.description,p.id,p.engine_key '
            . 'ORDER BY g.name'
        );
    }

    catalog_head('Games');
    echo CatalogUi::pageHeader(
        'Games',
        'Browse the public catalog by game.',
        ['Search' => 'index.php?page=search', 'Library' => 'library.php']
    );

    if ($games === []) {
        echo CatalogUi::section(
            CatalogUi::emptyState(
                'No games available',
                'The catalog does not currently contain any configured games.',
                ['label' => 'Search catalog', 'href' => 'index.php?page=search'],
                '○'
            ),
            ['title' => 'Catalog games']
        );
        catalog_foot();
        return;
    }

    $rows = '';
    foreach ($games as $game) {
        $engine = trim((string)($game['profile_engine'] ?? ''));
        $engineBadge = CatalogUi::badge($engine !== '' ? $engine : 'no profile', $engine !== '' ? 'success' : 'warning');
        $missingAvailable = $game['missing_dependency_count'] !== null;
        $baseGameMissingAvailable = $game['missing_base_game_dependency_count'] !== null;
        $missingCount = $missingAvailable ? (int)$game['missing_dependency_count'] : 0;
        $baseGameMissingCount = $baseGameMissingAvailable ? (int)$game['missing_base_game_dependency_count'] : 0;
        $missingBadge = $missingAvailable
            ? CatalogUi::badge(number_format($missingCount), $missingCount > 0 ? 'warning' : 'success')
            : CatalogUi::badge('Unavailable', 'warning');
        $baseGameMissingBadge = $baseGameMissingAvailable
            ? CatalogUi::badge(number_format($baseGameMissingCount), $baseGameMissingCount > 0 ? 'warning' : 'success')
            : CatalogUi::badge('Unavailable', 'warning');
        $missingUrl = 'game-missing.php?' . http_build_query([
            'game_id' => (int)$game['id'],
            'dependency_type' => 'all',
        ]);
        $baseGameMissingUrl = 'game-missing.php?' . http_build_query([
            'game_id' => (int)$game['id'],
            'dependency_type' => 'base_game',
        ]);
        $rows .= '<tr>';
        $rows .= '<td><strong>' . catalog_h($game['name']) . '</strong><br><span class="muted small">' . catalog_h($game['slug']) . '</span></td>';
        $rows .= '<td>' . $engineBadge . '</td>';
        $rows .= '<td data-sort-value="' . (int)$game['file_count'] . '">' . number_format((int)$game['file_count']) . '</td>';
        $rows .= '<td data-sort-value="' . $missingCount . '"><a href="' . catalog_h($missingUrl) . '" title="Show all missing dependency object rows for this game">' . $missingBadge . '</a></td>';
        $rows .= '<td data-sort-value="' . $baseGameMissingCount . '"><a href="' . catalog_h($baseGameMissingUrl) . '" title="Show missing official base-game dependency rows for this game">' . $baseGameMissingBadge . '</a></td>';
        $rows .= '<td data-sort-value="' . (int)$game['total_size'] . '">' . catalog_h(catalog_bytes((int)$game['total_size'])) . '</td>';
        $rows .= '<td>' . CatalogUi::button('Open files', [
            'href' => 'game-files.php?id=' . (int)$game['id'],
            'variant' => 'secondary',
            'size' => 'sm',
        ]) . '</td>';
        $rows .= '</tr>';
    }

    $table = '<table data-sortable-table><caption class="ui-sr-only">Configured games in the UnrealDB catalog</caption>'
        . '<thead><tr><th scope="col">Game</th><th scope="col">Profile engine</th><th scope="col">Files</th><th scope="col">Missing dependencies</th><th scope="col">Missing base-game dependencies</th><th scope="col">Total size</th><th scope="col">Open</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

    echo CatalogUi::section(
        CatalogUi::tableRegion($table, ['label' => 'Catalog games']),
        [
            'title' => 'Catalog games',
            'description' => number_format(count($games)) . ' configured game' . (count($games) === 1 ? '' : 's') . '.',
        ]
    );
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'The games page could not be loaded.');
    catalog_foot();
}
