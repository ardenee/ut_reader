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

        $unresolvedUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => $zen ? 'unresolved' : 'unindexed',
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
        $nativeScriptBadge = $badge($nativeScriptRefs, 'warning');
        $nativeScriptUrl = 'game-files.php?' . http_build_query([
            'id' => $gameId,
            'dep_filter' => 'native_script',
        ]);

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
                    . '" title="References without a verified same-game indexed candidate for the required package identity; not confirmed missing">'
                    . $unresolvedBadge . '</a>'
                : $unresolvedBadge
        );
        $rows .= $numberCell(
            $unresolvedFiles,
            $unresolvedFiles !== null
                ? '<a href="' . catalog_h($unresolvedUrl)
                    . '" title="Distinct files with at least one required package identity not indexed in this catalogue">'
                    . $unresolvedFileBadge . '</a>'
                : $unresolvedFileBadge
        );
        $rows .= $numberCell(
            $resolved,
            $resolved !== null
                ? '<a href="' . catalog_h($resolvedUrl)
                    . '" title="Browse files with at least one indexed package candidate (' . number_format($resolved) . ' references game-wide; native mount not asserted)">'
                    . $resolvedBadge . '</a>'
                : $resolvedBadge
        );
        $rows .= $numberCell(
            $nativeScriptRefs,
            $nativeScriptRefs !== null && $nativeScriptRefs > 0
                ? '<a href="' . catalog_h($nativeScriptUrl)
                    . '" title="References to native /Script/ module identities; compiled/runtime registration not proven">'
                    . $nativeScriptBadge . '</a>'
                : $nativeScriptBadge
        );
        $rows .= $numberCell(
            $resolvedFiles,
            $resolvedFiles !== null
                ? '<a href="' . catalog_h($completeUrl)
                    . '" title="Browse ' . number_format($resolvedFiles) . ' files without unidentified package dependencies (physical candidates or native script module identities); runtime load not asserted">'
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
        . '<th scope="col" title="Required package identities with no indexed verified same-game candidate. Not confirmed missing from a runtime installation.">Not Indexed</th>'
        . '<th scope="col" title="Files with at least one required package identity absent from this indexed catalogue">Files With Gaps</th>'
        . '<th scope="col" title="References to package identities having a verified same-game catalogue candidate; not a claim of native VerifyImport, mounted provider or matching export">Indexed Packages</th>'
        . '<th scope="col" title="Native /Script/ module references, which are not ordinary physical package files; no claim of runtime registration">Native Script</th>'
        . '<th scope="col" title="Files with every required package identity either indexed or recognised as a native script-module reference; no claim of successful native loading">Files Without Gaps</th>'
        . '<th scope="col">Total size</th><th scope="col">Open</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>';

    echo CatalogUi::section(
        CatalogUi::tableRegion($table, ['label' => 'Catalog games']),
        [
            'title' => 'Catalog games',
            'description' => number_format(count($games)) . ' configured game'
                . (count($games) === 1 ? '' : 's') . '. '
                . 'Indexed Packages means a matching physical package identity '
                . 'is present in the catalogue. Native Script identifies UE4 '
                . '/Script/ module references (not physical packages). '
                . 'Neither proves successful runtime import verification. '
                . 'Not Indexed is not confirmed missing from the game.',
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
