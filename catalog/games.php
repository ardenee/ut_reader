<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the public game catalogue from current source-published dependency results.
 * Role: Thin web UI entry point; aggregate reads and per-file counters live in persistence services.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogListQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameDependencyFileCountQuery;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $games = (new PdoGameCatalogListQuery($db))->all();
    $fileCounter = new PdoGameDependencyFileCountQuery($db, $config);

    catalog_head('Games');
    echo CatalogUi::pageHeader(
        'Games',
        'Browse the public catalog by game.'
    );

    if ($games === []) {
        echo CatalogUi::section(
            CatalogUi::emptyState(
                'No games available',
                'The catalog does not currently contain any configured games.',
                ['label' => 'Search catalog', 'href' => 'index.php?page=search'],
                '–'
            ),
            ['title' => 'Catalog games']
        );
        catalog_foot();
        return;
    }

    $rows = '';
    foreach ($games as $game) {
        $gameId = (int)$game['id'];
        $engine = trim((string)($game['profile_engine'] ?? ''));
        $engineBadge = CatalogUi::badge(
            $engine !== '' ? $engine : 'no profile',
            $engine !== '' ? 'success' : 'warning'
        );

        $current = $game['unresolved_dependency_count'] !== null
            && $game['resolved_dependency_count'] !== null;
        $fileCounts = $current
            ? $fileCounter->forCurrentGame(
                $gameId,
                $engine,
                (string)($game['dependency_stats_updated_at'] ?? '')
            )
            : null;

        // "Resolved" means every reference not currently unresolved, including
        // common and package-only references; it is not the old resolved_count
        // field, which requires a unique engine-selected physical provider.
        // Zen counts use exact indexed ScriptObjects, packages and exports.
        $zen = strtoupper($engine) === 'UE5';
        $unresolved = $current
            ? ($zen ? ($fileCounts['unresolved_references'] ?? null)
                : (int)$game['unresolved_dependency_count'])
            : null;
        $totalDependencies = $current
            ? ($zen ? ($fileCounts['dependency_count'] ?? null)
                : (int)$game['dependency_count'])
            : null;
        $resolved = $unresolved !== null && $totalDependencies !== null
            ? max(0, $totalDependencies - $unresolved)
            : null;
        $unresolvedFiles = $fileCounts['unresolved_files'] ?? null;
        $resolvedFiles = $fileCounts['resolved_files'] ?? null;

        $unresolvedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => 'unresolved',
        ]);
        $resolvedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => 'resolved',
        ]);
        $completeUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => 'fully_resolved',
        ]);

        $badge = static function (?int $count, string $positiveTone = 'success'): string {
            return $count === null
                ? CatalogUi::badge('Unavailable', 'warning')
                : CatalogUi::badge(
                    number_format($count),
                    $count > 0 ? $positiveTone : 'neutral'
                );
        };
        $numberCell = static function (?int $count, string $content): string {
            return '<td data-sort-value="' . ($count ?? -1) . '">' . $content . '</td>';
        };

        $unresolvedBadge = $badge($unresolved, 'warning');
        $unresolvedFileBadge = $badge($unresolvedFiles, 'warning');
        $resolvedBadge = $badge($resolved);
        $resolvedFileBadge = $badge($resolvedFiles);

        $rows .= '<tr>';
        $rows .= '<td><strong>' . catalog_h((string)$game['name'])
            . '</strong><br><span class="muted small">' . catalog_h((string)$game['slug'])
            . '</span></td>';
        $rows .= '<td>' . $engineBadge . '</td>';
        $rows .= '<td data-sort-value="' . (int)$game['file_count'] . '">'
            . number_format((int)$game['file_count']) . '</td>';
        $rows .= $numberCell(
            $unresolved,
            $unresolved !== null
                ? '<a href="' . catalog_h($unresolvedUrl)
                    . '" title="Source-published unresolved dependency references">'
                    . $unresolvedBadge . '</a>'
                : $unresolvedBadge
        );
        $rows .= $numberCell(
            $unresolvedFiles,
            $unresolvedFiles !== null
                ? '<a href="' . catalog_h($unresolvedUrl)
                    . '" title="Distinct files with at least one current unresolved reference">'
                    . $unresolvedFileBadge . '</a>'
                : $unresolvedFileBadge
        );
        $rows .= $numberCell(
            $resolved,
            $resolved !== null
                ? '<a href="' . catalog_h($resolvedUrl)
                    . '" title="Browse files with at least one satisfied reference (' . number_format($resolved) . ' references game-wide)">'
                    . $resolvedBadge . '</a>'
                : $resolvedBadge
        );
        $rows .= $numberCell(
            $resolvedFiles,
            $resolvedFiles !== null
                ? '<a href="' . catalog_h($completeUrl)
                    . '" title="Browse ' . number_format($resolvedFiles) . ' files with all indexed dependencies met">'
                    . $resolvedFileBadge . '</a>'
                : $resolvedFileBadge
        );
        $rows .= '<td data-sort-value="' . (int)$game['total_size'] . '">'
            . catalog_h(catalog_bytes((int)$game['total_size'])) . '</td>';
        $rows .= '<td>' . CatalogUi::button('Open files', [
            'href' => 'game-files.php?id=' . $gameId,
            'variant' => 'secondary',
            'size' => 'sm',
        ]) . '</td>';
        $rows .= '</tr>';
    }

    $table = '<table data-sortable-table><caption class="ui-sr-only">Configured games in the UnrealDB catalog</caption>'
        . '<thead><tr>'
        . '<th scope="col">Game</th><th scope="col">Profile engine</th>'
        . '<th scope="col">Files</th>'
        . '<th scope="col" title="References still unresolved against current source publication; UE5 uses exact indexed catalogue target coverage">Unresolved</th>'
        . '<th scope="col" title="Number of distinct files with at least one unresolved reference">Unresolved Files</th>'
        . '<th scope="col" title="Total dependency references minus unresolved references; includes common and package-only references">Resolved</th>'
        . '<th scope="col" title="Files with at least one dependency and no unresolved/missing references; common and package-only count as met">Resolved Files</th>'
        . '<th scope="col">Total size</th><th scope="col">Open</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';

    echo CatalogUi::section(
        CatalogUi::tableRegion($table, ['label' => 'Catalog games']),
        [
            'title' => 'Catalog games',
            'description' => number_format(count($games)) . ' configured game'
                . (count($games) === 1 ? '' : 's') . '.',
        ]
    );
    // Maintenance -> Update All Game Dependencies is in the global admin navigation.
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Error');
    echo CatalogUi::alert(
        'danger',
        catalog_exception_display_message($error),
        'The games page could not be loaded.'
    );
    catalog_foot();
}
