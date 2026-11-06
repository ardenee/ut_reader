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

        // Display indexed catalogue package availability, NOT unproven
        // engine VerifyImport or the historical common-name bypass.
        // Classic: an indexed, verified same-game candidate for the required
        // package identity; Zen: exact registered package/object/ScriptObjects.
        $zen = strtoupper($engine) === 'UE5';
        $unresolved = $current && isset($fileCounts['unindexed_references'])
            ? (int)$fileCounts['unindexed_references'] : null;
        $totalDependencies = $current && isset($fileCounts['total_references'])
            ? (int)$fileCounts['total_references'] : null;
        $resolved = $current && isset($fileCounts['indexed_references'])
            ? (int)$fileCounts['indexed_references'] : null;
        $unresolvedFiles = $current && isset($fileCounts['unindexed_files'])
            ? (int)$fileCounts['unindexed_files'] : null;
        $resolvedFiles = $current && isset($fileCounts['no_gaps_files'])
            ? (int)$fileCounts['no_gaps_files'] : null;
        $nativeScriptRefs = $current && isset($fileCounts['native_script_references'])
            ? (int)$fileCounts['native_script_references'] : null;
        $nativeSourceMatched = $current && $gameId === 7
            && isset($fileCounts['source_matched_script_references'])
                ? (int)$fileCounts['source_matched_script_references'] : null;
        $nativeSourceUnmatched = $current && $gameId === 7
            && isset($fileCounts['source_unmatched_script_references'])
                ? (int)$fileCounts['source_unmatched_script_references'] : null;

        $unresolvedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => 'missing',
        ]);
        $resolvedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => $zen ? 'resolved' : 'indexed',
        ]);
        $completeUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => $zen ? 'fully_resolved' : 'no_gaps',
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
        $nativeSourceMatchedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId, 'dep_filter' => 'script_source_matched',
        ]);
        $nativeSourceUnmatchedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId, 'dep_filter' => 'script_source_unmatched',
        ]);
        $nativeSourceDisplay = '&mdash;';
        if ($nativeSourceMatched !== null && $nativeSourceUnmatched !== null) {
            $nativeSourceDisplay = '<a href="' . catalog_h($nativeSourceUnmatchedUrl)
                . '" title="Not in source: module declarations absent from the retained UT4 game/engine source; 32 references across 8 files. They may exist in another compiled build.">'
                . number_format($nativeSourceUnmatched) . '</a>'
                . ' / <a href="' . catalog_h($nativeSourceMatchedUrl)
                . '" title="In source: module declarations found in the retained UT4 game/engine source. Runtime registration and imported classes are not verified.">'
                . number_format($nativeSourceMatched) . '</a>';
        }

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
                    . '" title="Required dependency packages missing from this game&#039;s indexed catalogue; may exist in other games or installations">'
                    . $unresolvedBadge . '</a>'
                : $unresolvedBadge
        );
        $rows .= $numberCell(
            $unresolvedFiles,
            $unresolvedFiles !== null
                ? '<a href="' . catalog_h($unresolvedUrl)
                    . '" title="Files with one or more missing dependency packages in this game&#039;s catalogue">'
                    . $unresolvedFileBadge . '</a>'
                : $unresolvedFileBadge
        );
        $rows .= $numberCell(
            $resolved,
            $resolved !== null
                ? '<a href="' . catalog_h($resolvedUrl)
                    . '" title="Browse files with available package-name candidates (' . number_format($resolved) . ' references game-wide; imported objects and native mount not yet verified)">'
                    . $resolvedBadge . '</a>'
                : $resolvedBadge
        );
        $rows .= $numberCell($nativeScriptRefs, $nativeSourceDisplay);
        $rows .= $numberCell(
            $resolvedFiles,
            $resolvedFiles !== null
                ? '<a href="' . catalog_h($completeUrl)
                    . '" title="Browse ' . number_format($resolvedFiles) . ' files with no missing physical dependency packages in the catalogue; runtime load and source-only native modules are assessed separately">'
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
        . '<th scope="col" title="Dependency packages missing from this game&#039;s catalogue. Not proof that an original installation lacks them.">Missing</th>'
        . '<th scope="col" title="Files referring to one or more packages missing from this game&#039;s catalogue">Files With Gaps</th>'
        . '<th scope="col" title="Dependencies with matching verified package-name candidates in the game catalogue; not a claim of matching exports or native runtime resolution">Packages Found</th>'
        . '<th scope="col" title="UT4 native script reference counts: not in source / in source. Hover each number for its meaning. Source presence does not prove runtime loading.">Native Script</th>'
        . '<th scope="col" title="Files with no missing physical dependency packages; UT4 native script source matches are reported separately">Files Without Gaps</th>'
        . '<th scope="col">Total size</th><th scope="col">Open</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';

    echo CatalogUi::section(
        CatalogUi::tableRegion($table, ['label' => 'Catalog games']),
        [
            'title' => 'Catalog games',
            'description' => number_format(count($games)) . ' configured game'
                . (count($games) === 1 ? '' : 's') . '. '
                . 'Missing means a required package is absent from this game&#039;s catalogue, '
                . 'even if that package is indexed under another game. '
                . 'Packages Found counts names with a verified catalogue candidate, '
                . 'not proven loaded objects. UT4 native /Script/ modules are matched '
                . 'against retained UT4 game/engine source declarations; '
                . 'that check does not prove a compiled module or imported symbol.',
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
