<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for game files.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameFileListQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameDependencyFileCountQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameIndexedPackageCoverageQuery;

catalog_start_session();
require_once __DIR__ . '/lib/FederationAuth.php';

function game_files_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function game_files_url(array $params): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'game-files.php?' . http_build_query($query);
}

function game_files_sort_link(string $label, string $key, string $activeSort, string $activeDir): string
{
    $nextDir = ($activeSort === $key && $activeDir === 'asc') ? 'desc' : 'asc';
    $marker = $activeSort === $key ? ($activeDir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a class="sort-link" href="' . catalog_h(game_files_url([
        'sort' => $key,
        'dir' => $nextDir,
        'cursor' => null,
        'cursor_move' => null,
        'cursor_page' => null,
        'file_page' => null,
    ])) . '">' . catalog_h($label . $marker) . '</a>';
}

function game_files_engine_major(string $engineKey): int
{
    if (preg_match('/UE\s*([0-9]+)/i', $engineKey, $match)) {
        return (int)$match[1];
    }
    return 0;
}

function game_files_type_from_extension(string $ext): array
{
    $ext = strtolower(trim($ext, '. '));
    return match ($ext) {
        'unr', 'un2', 'ut2', 'ut3', 'umap' => ['map', 'type-map'],
        'umx' => ['music', 'type-music'],
        'uax' => ['sound', 'type-sound'],
        'utx' => ['texture', 'type-texture'],
        'usx' => ['static mesh', 'type-static-mesh'],
        'ukx' => ['animation', 'type-animation'],
        'upx' => ['particle/effect', 'type-particle-effect'],
        'ugx' => ['gui', 'type-gui'],
        'con' => ['content', 'type-content'],
        'u', 'upk', 'uasset' => ['package', 'type-package'],
        'zenpkg' => ['Zen / IoStore', 'type-package'],
        default => [$ext !== '' ? $ext : 'unknown', 'type-unknown'],
    };
}

function game_files_type_filter_sql(string $type): array
{
    $map = [
        'map' => ['unr', 'un2', 'ut2', 'ut3', 'umap'],
        'music' => ['umx'],
        'sound' => ['uax'],
        'texture' => ['utx'],
        'static_mesh' => ['usx'],
        'animation' => ['ukx'],
        'particle_effect' => ['upx'],
        'gui' => ['ugx'],
        'content' => ['con'],
        'package' => ['u', 'upk', 'uasset', 'zenpkg'],
    ];
    return $map[$type] ?? [];
}

function game_files_status_badge(string $status, int $count): string
{
    $tone = match ($status) {
        'resolved' => 'success',
        'missing' => 'danger',
        'package_only' => 'warning',
        'unresolved' => 'warning',
        default => 'neutral',
    };
    return CatalogUi::badge($status . ': ' . $count, $tone);
}

function game_files_pagination(
    int $pageNo,
    int $totalPages,
    bool $hasPrevious,
    bool $hasNext,
    string $previousCursor,
    string $nextCursor,
    array $jumpFields
): string {
    $first = game_files_url([
        'cursor' => null, 'cursor_move' => null,
        'cursor_page' => null, 'file_page' => null,
    ]);
    $previous = game_files_url([
        'cursor' => $previousCursor, 'cursor_move' => 'prev',
        'cursor_page' => max(1, $pageNo - 1), 'file_page' => null,
    ]);
    $next = game_files_url([
        'cursor' => $nextCursor, 'cursor_move' => 'next',
        'cursor_page' => min($totalPages, $pageNo + 1), 'file_page' => null,
    ]);
    $last = game_files_url([
        'cursor' => null, 'cursor_move' => 'last',
        'cursor_page' => $totalPages, 'file_page' => null,
    ]);
    return CatalogUi::pagination($pageNo, $totalPages, [
        'label' => 'File pagination',
        'first' => $hasPrevious ? $first : '',
        'previous' => $hasPrevious ? $previous : '',
        'next' => $hasNext ? $next : '',
        'last' => $hasNext ? $last : '',
        // Direct page links deliberately drop keyset cursors; adjacent
        // navigation still uses fast context-HMAC-protected keyset cursors.
        'page_url' => static fn(int $p): string => game_files_url([
            'cursor' => null, 'cursor_move' => null,
            'cursor_page' => null, 'file_page' => $p,
        ]),
        'jump' => [
            'action' => 'game-files.php',
            'name' => 'file_page',
            'fields' => $jumpFields,
        ],
    ]);
}

function game_files_actions(int $fileId, string $originalName, string $csrf, bool $isAdmin): string
{
    $confirm = 'Remove ' . $originalName . ' from storage and the catalog? This cannot be undone. Dependency links for the game will then be rebuilt.';
    $html = '<div class="game-files-actions-list">';
    $downloadHref = $isAdmin
        ? 'download.php?id=' . $fileId
        : 'download-info.php?id=' . $fileId;
    $html .= '<a class="game-files-download-link" href="' . catalog_h($downloadHref) . '" title="Download options" aria-label="Download options for ' . catalog_h($originalName) . '">⇩</a>';
    $html .= '<a class="game-files-download-link" href="package-coverage.php?file_id=' . $fileId
        . '" title="Send to Package Coverage" aria-label="Send ' . catalog_h($originalName) . ' to Package Coverage">→</a>';

    if ($isAdmin) {
        $html .= '<form method="post" action="file-maintenance.php" title="Rebuild dependency links for this game">';
        $html .= '<input type="hidden" name="csrf" value="' . catalog_h($csrf) . '">';
        $html .= '<input type="hidden" name="file_id" value="' . $fileId . '">';
        $html .= '<input type="hidden" name="operation" value="rebuild">';
        $html .= '<button type="submit" class="game-files-admin-button" aria-label="Rebuild dependency links" title="Rebuild dependency links for this game">↻</button>';
        $html .= '</form>';
        $html .= '<form method="post" action="file-maintenance.php" onsubmit="return confirm(\'' . catalog_h($confirm) . '\');" title="Delete this package from storage and the catalog">';
        $html .= '<input type="hidden" name="csrf" value="' . catalog_h($csrf) . '">';
        $html .= '<input type="hidden" name="file_id" value="' . $fileId . '">';
        $html .= '<input type="hidden" name="operation" value="remove">';
        $html .= '<button type="submit" class="game-files-admin-button game-files-admin-button--remove" aria-label="Delete package" title="Delete this package from storage and the catalog">×</button>';
        $html .= '</form>';
    }

    return $html . '</div>';
}

function game_files_page_styles(): string
{
    return <<<'CSS'
<style>
html { scroll-behavior: smooth; }
#game-files-top { scroll-margin-top: 86px; }

.game-files-controls {
    display: grid;
    grid-template-columns: minmax(360px, 1fr) max-content max-content max-content max-content;
    align-items: center;
    gap: 10px;
}
.game-files-controls label { display: flex; align-items: center; gap: 6px; margin: 0; white-space: nowrap; }
.game-files-controls .wide-search { width: 100%; min-width: 0; }
.game-files-filter-actions { display: flex; align-items: center; justify-self: end; gap: 6px; white-space: nowrap; }
.game-files-filter-actions .ui-button { margin: 0; }

#game-files-table { width: 100% !important; min-width: 920px !important; table-layout: auto !important; }
#game-files-table.game-files-table--no-compression { min-width: 820px !important; }
#game-files-table th, #game-files-table td { vertical-align: top; }
#game-files-table .game-files-primary {
    width: auto;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
}
#game-files-table .identity-cell,
#game-files-table .game-files-version,
#game-files-table .game-files-size,
#game-files-table .game-files-compression,
#game-files-table .game-files-dependencies,
#game-files-table .game-files-actions {
    width: 1%;
}
#game-files-table .identity-cell { white-space: nowrap; padding-right: 26px; }
#game-files-table .game-files-version { padding-left: 14px; }
#game-files-table .game-files-version,
#game-files-table .game-files-size,
#game-files-table .game-files-compression,
#game-files-table .game-files-actions { white-space: nowrap; }
.game-files-primary-file { margin-bottom: 3px; }
.game-files-primary-package { margin-bottom: 0; }
.game-files-version-type { margin-top: 5px; }
.game-files-file-link, .game-files-package-link { font-weight: 650; overflow-wrap: anywhere; word-break: break-word; }
.game-files-primary-package .game-files-package-link { font-weight: 550; }
.game-files-dependencies { min-width: 130px; white-space: normal; }
.game-files-dependency-list { display: flex; flex-direction: column; align-items: flex-start; row-gap: 1px; }
.game-files-dependency-list .ui-badge { white-space: nowrap; }
.game-files-actions { text-align: center; white-space: nowrap; }
.game-files-actions-list { display: flex; justify-content: center; align-items: center; gap: 8px; }
.game-files-actions-list form { margin: 0; }

.game-files-download-link, .game-files-admin-button {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    margin: 0;
    border: 1px solid var(--line2);
    border-radius: 9px;
    color: var(--blue);
    background: rgba(118, 169, 255, .08);
    font: inherit;
    font-size: 20px;
    font-weight: 800;
    line-height: 1;
    cursor: pointer;
}
.game-files-download-link:hover, .game-files-admin-button:hover { background: rgba(118, 169, 255, .18); text-decoration: none; }
.game-files-admin-button--remove { color: #ffabb4; background: rgba(255, 107, 122, .08); }
.game-files-admin-button--remove:hover { background: rgba(255, 107, 122, .18); }

.game-files-to-top {
    position: fixed; right: 20px; bottom: 20px; z-index: 9; display: grid; place-items: center;
    width: 42px; height: 42px; border: 1px solid var(--line2); border-radius: 50%; color: var(--text);
    background: rgba(16, 24, 39, .94); box-shadow: var(--shadow); font-size: 22px; font-weight: 800;
}
.game-files-to-top:hover { background: rgba(44, 66, 112, .98); text-decoration: none; }

@media (max-width: 1100px) {
    .game-files-controls { grid-template-columns: minmax(280px, 1fr) max-content max-content max-content; }
    .game-files-filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 700px) {
    .game-files-controls { grid-template-columns: 1fr; }
    .game-files-controls label { justify-content: space-between; }
    .game-files-filter-actions { justify-self: start; }
    .game-files-to-top { right: 14px; bottom: 14px; }
}
</style>
CSS;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $gameId = game_files_int('id', 0, 1, PHP_INT_MAX);
    $game = catalog_one(
        $db,
        'SELECT g.*, p.engine_key profile_engine FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
        [$gameId]
    );
    if (!$game) {
        throw new RuntimeException('Game not found');
    }
    $engineMajor = game_files_engine_major((string)($game['profile_engine'] ?? ''));
    // UE4/UE5 package rows do not need the legacy internal-compression column.
    // Keep it only for UE3, where compressed package chunks remain useful file-level metadata.
    $showCompression = $engineMajor === 3;
    $separateUpkContainers = $engineMajor === 3;

    $configuredLimit = (int)(fed_setting($db, 'game_file_display_limit', '100') ?: 100);
    $limit = max(1, min(500, $configuredLimit > 0 ? $configuredLimit : 100));
    $filter = trim((string)($_GET['file_filter'] ?? ''));
    $depFilter = trim((string)($_GET['dep_filter'] ?? ''));
    // Backward-compatible legacy URL; no engine considers 'common' a verified outcome.
    if ($depFilter === 'common') $depFilter = 'unresolved';
    // Legacy bookmarks for the initial package-only coverage prototype.
    if ($depFilter === 'fully_indexed') $depFilter = 'no_gaps';
    $gameDependencyCurrent = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::isGameCurrent(
        $db, (int)$gameId, (string)($game['profile_engine'] ?? '')
    );
    $dependencySelectionUnavailable = !$gameDependencyCurrent
        && in_array($depFilter, ['resolved','fully_resolved','missing','package_only','unresolved','any','indexed','unindexed','no_gaps','native_script','script_source_matched','script_source_unmatched'], true);
    // The Games page uses current catalogue target coverage for UE5 Zen
    // (package/export ID and pinned global ScriptObjects). The file filter
    // must consume the identical cached exception-set, not the source-only
    // "unresolved" projection, or the link would show a different total.
    $zenCoverage = null;
    if (!$dependencySelectionUnavailable && $gameDependencyCurrent
        && $engineMajor === 5
        && in_array($depFilter, ['unresolved','resolved','fully_resolved'], true)) {
        $stats = catalog_one($db,
            'SELECT updated_at FROM ue_game_catalog_stats WHERE game_id=?', [$gameId]);
        if ($stats !== null
            && \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::isGameStatsCurrent(
                $db, $gameId, (string)($game['profile_engine'] ?? '')
            )) {
            $zenCoverage = (new PdoGameDependencyFileCountQuery($db, $config))
                ->forCurrentGame($gameId, (string)$game['profile_engine'],
                    (string)($stats['updated_at'] ?? ''));
        }
        if ($zenCoverage === null
            || !isset($zenCoverage['unresolved_file_ids'], $zenCoverage['no_satisfied_file_ids'])) {
            $dependencySelectionUnavailable = true;
        }
    }
    $typeFilter = trim((string)($_GET['type_filter'] ?? ''));
    $compressionFilter = $showCompression ? trim((string)($_GET['compression_filter'] ?? '')) : '';
    $sort = trim((string)($_GET['sort'] ?? 'package'));
    $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
    $dir = $dir === 'desc' ? 'desc' : 'asc';

    $sortMap = ['package' => true, 'file' => true, 'version' => true, 'size' => true, 'deps' => true, 'uploaded' => true];
    if ($showCompression) {
        $sortMap['compression'] = true;
    }
    if (!isset($sortMap[$sort])) {
        $sort = 'package';
    }
    // Zen rows receive exact catalogue matches after source projection. Those
    // per-file matches cannot be ordered correctly by the old SQL summary key.
    // Do not present an inaccurate keyset sort for Zen until materialised.
    $dependencySortUnavailable = $sort === 'deps'
        && (!$gameDependencyCurrent || $engineMajor === 5);
    if ($dependencySortUnavailable) {
        $sort = 'package';
    }
    if ($dependencySelectionUnavailable) {
        // An unverified dependency filter is unknown, not an empty result set.
        $depFilter = '';
    }

    $where = 'WHERE f.game_id=?';
    $args = [$gameId];
    if ($separateUpkContainers) {
        $where .= ' AND LOWER(f.extension)<>"upk"';
    }
    if ($filter !== '') {
        $where .= ' AND (f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?)';
        $like = '%' . $filter . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if ($engineMajor !== 5 && in_array($depFilter,
        ['indexed','unindexed','no_gaps','native_script',
         'script_source_matched','script_source_unmatched'], true)) {
        // Catalogue package availability is separate from runtime VerifyImport.
        $where .= ' AND f.scan_status="verified" AND '
            . PdoGameIndexedPackageCoverageQuery::fileFilterPredicate(
                $depFilter, $engineMajor === 4 && $gameId === 7);
    } elseif ($engineMajor === 5 && $zenCoverage !== null
        && in_array($depFilter, ['unresolved','resolved','fully_resolved'], true)) {
        // All ID lists are internal integers generated from verified/current
        // SQL, never client-provided identifiers. Exception sets are small
        // relative to the 27k+ published Zen package members.
        $unresolvedIds = array_map('intval', $zenCoverage['unresolved_file_ids']);
        $noSatisfiedIds = array_map('intval', $zenCoverage['no_satisfied_file_ids']);
        $missingIds = $unresolvedIds !== []
            ? implode(',', $unresolvedIds) : '0';
        $unmetIds = $noSatisfiedIds !== []
            ? implode(',', $noSatisfiedIds) : '0';
        $scope = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::currentOwnerPredicate('dx');
        $hasClassic = 'EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
            . 'WHERE dx.file_id=f.id AND dx.dependency_count>0 AND ' . $scope . ')';
        $hasZen = 'EXISTS (SELECT 1 FROM ue_uedb5_dependency_edges e '
            . 'WHERE e.file_id=f.id)';
        if ($depFilter === 'unresolved') {
            $classic = 'EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND (dx.unresolved_count+dx.common_count)>0 AND ' . $scope . ')';
            $zen = 'f.id IN (' . $missingIds . ')';
        } elseif ($depFilter === 'fully_resolved') {
            $classic = $hasClassic
                . ' AND NOT EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND (dx.unresolved_count>0 OR dx.common_count>0 OR dx.missing_count>0) '
                . 'AND ' . $scope . ')';
            $zen = $hasZen . ' AND f.id NOT IN (' . $missingIds . ')';
        } else {
            $classic = 'EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND dx.dependency_count>'
                . '(dx.unresolved_count+dx.common_count+dx.missing_count) AND ' . $scope . ')';
            $zen = $hasZen . ' AND f.id NOT IN (' . $unmetIds . ')';
        }
        $where .= ' AND f.scan_status="verified" AND '
            . '((f.extension="zenpkg" AND ' . $zen . ') OR '
            . '(f.extension<>"zenpkg" AND (' . $classic . ')))';
    } elseif (in_array($depFilter,
        ['resolved', 'fully_resolved', 'missing', 'package_only', 'unresolved', 'any'], true)) {
        $scope = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::currentOwnerPredicate('dx');
        if ($depFilter === 'any') {
            $where .= ' AND (EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx WHERE dx.file_id=f.id AND '
                . $scope . ')'
                . ' OR (f.extension="zenpkg" AND EXISTS (SELECT 1 FROM ue_uedb5_dependency_edges de '
                . 'WHERE de.file_id=f.id AND '
                . \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::currentOwnerPredicate('de') . ')))';
        } elseif ($depFilter === 'unresolved') {
            $where .= ' AND EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND (dx.unresolved_count+dx.common_count)>0 AND ' . $scope . ')';
        } elseif ($depFilter === 'resolved') {
            // A satisfied reference must not include legacy, unverified
            // common-name exemptions: those are now unresolved.
            $where .= ' AND f.scan_status="verified" AND '
                . 'EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND dx.dependency_count>'
                . '(dx.unresolved_count+dx.common_count+dx.missing_count) AND ' . $scope . ')';
        } elseif ($depFilter === 'fully_resolved') {
            $where .= ' AND f.scan_status="verified" AND '
                . 'EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND dx.dependency_count>0 AND ' . $scope . ') '
                . 'AND NOT EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND (dx.unresolved_count>0 OR dx.common_count>0 OR dx.missing_count>0) '
                . 'AND ' . $scope . ')';
        } else {
            $countColumn = match ($depFilter) {
                'missing' => 'missing_count',
                'package_only' => 'package_only_count',
                default => throw new RuntimeException('Unknown dependency filter'),
            };
            $where .= ' AND EXISTS (SELECT 1 FROM ue_uedb5_dependency_packages dx '
                . 'WHERE dx.file_id=f.id AND dx.' . $countColumn . '>0 AND ' . $scope . ')';
        }
    }
    $typeExts = game_files_type_filter_sql($typeFilter);
    if ($typeExts) {
        $where .= ' AND f.extension IN (' . implode(',', array_fill(0, count($typeExts), '?')) . ')';
        foreach ($typeExts as $ext) {
            $args[] = $ext;
        }
    }
    if ($showCompression && $compressionFilter === 'compressed') {
        $where .= ' AND f.is_compressed=1';
    } elseif ($showCompression && $compressionFilter === 'uncompressed') {
        $where .= ' AND f.is_compressed=0';
    }

    $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files f ' . $where, $args)['c'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    $move = strtolower(trim((string)($_GET['cursor_move'] ?? 'first')));
    $move = in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
    $directPage = game_files_int('file_page', 0, 0, $totalPages);
    $pageNo = game_files_int('cursor_page', $move === 'last' ? $totalPages : 1, 1, $totalPages);
    if ($directPage > 0) {
        $pageNo = $directPage;
    } elseif ($move === 'first') {
        $pageNo = 1;
    } elseif ($move === 'last') {
        $pageNo = $totalPages;
    }

    $cursorContext = json_encode([
        'page' => 'game-files',
        'game_id' => $gameId,
        'limit' => $limit,
        'filter' => $filter,
        'dependency' => $depFilter,
        'type' => $typeFilter,
        'compression' => $compressionFilter,
        'sort' => $sort,
        'direction' => $dir,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $cursorToken = trim((string)($_GET['cursor'] ?? ''));
    $cursor = $cursorToken !== '' ? CatalogKeysetPaginator::decode($config, $cursorContext, $cursorToken) : null;
    if ($directPage === 0 && $cursorToken !== '' && $cursor === null) {
        $move = 'first';
        $pageNo = 1;
    }

    $fileListQuery = new PdoGameFileListQuery($db);
    $page = $directPage > 0
        ? $fileListQuery->fetchNumberedPage($where, $args, $sort, $dir, $limit, $pageNo)
        : $fileListQuery->fetchCursorPage($where, $args, $sort, $dir, $limit, $cursor, $move);
    $files = $page['rows'];
    if ($files === [] && $totalRows > 0 && $pageNo > 1) {
        // The source changed between count and page selection: recover to
        // page one instead of showing an impossible empty terminal page.
        $move = 'first';
        $pageNo = 1;
        $page = $fileListQuery->fetchCursorPage($where, $args, $sort, $dir, $limit, null, 'first');
        $files = $page['rows'];
    }

    // Read package identity availability only for this bounded visible page.
    // It must not turn unknown runtime mounts into source-verified imports.
    $pagePackageCoverage = $gameDependencyCurrent && $engineMajor !== 5 && $files !== []
        ? (new PdoGameIndexedPackageCoverageQuery($db))->forFiles(
            $gameId, array_map(static fn(array $f): int => (int)$f['id'], $files),
            $engineMajor === 4 && $gameId === 7)
        : [];

    $previousCursor = is_array($page['first_cursor'])
        ? CatalogKeysetPaginator::encode($config, $cursorContext, $page['first_cursor'])
        : '';
    $nextCursor = is_array($page['last_cursor'])
        ? CatalogKeysetPaginator::encode($config, $cursorContext, $page['last_cursor'])
        : '';
    $pagination = game_files_pagination(
        $pageNo,
        $totalPages,
        (bool)$page['has_previous'],
        (bool)$page['has_next'],
        $previousCursor,
        $nextCursor,
        [
            'id' => $gameId,
            'file_filter' => $filter,
            'dep_filter' => $depFilter,
            'type_filter' => $typeFilter,
            'compression_filter' => $compressionFilter,
            'sort' => $sort,
            'dir' => $dir,
        ]
    );

    $isAdmin = catalog_support_is_admin();
    $maintenanceCsrf = $isAdmin ? catalog_csrf('catalog-maintenance') : '';

    catalog_head((string)$game['name']);
    echo game_files_page_styles();
    echo '<span id="game-files-top" aria-hidden="true"></span>';
    $description = $separateUpkContainers
        ? 'Files, versions, dependency status and downloads. UE3 UPK package containers are shown separately.'
        : 'Files, versions, dependency status and downloads.';
    echo CatalogUi::pageHeader((string)$game['name'], $description, ['Back to games' => 'games.php']);
    if (!$gameDependencyCurrent) {
        echo '<div class="card"><strong>Dependency publication unavailable.</strong> '
            . 'Current unresolved/resolved/missing classifications are unknown until the engine-specific source publication is complete. '
            . 'If requested, dependency filtering or dependency sorting has been skipped to avoid misleading results.</div>';
    }

    $filterDescriptions = [
        'unresolved' => 'runtime-unverified dependency references',
        'resolved' => 'native-verified dependency references',
        'fully_resolved' => 'files with all native-verified references',
        'unindexed' => 'missing package dependencies',
        'indexed' => 'dependency packages found',
        'script_source_matched' => 'native script references with a matching module declaration in source',
        'script_source_unmatched' => 'native script references without a matching module declaration in source',
    ];
    $countDescription = '';
    if (isset($filterDescriptions[$depFilter]) && $filter === '' && $typeFilter === ''
        && $compressionFilter === '' && $gameDependencyCurrent) {
        if ($engineMajor !== 5 && in_array($depFilter,
            ['unindexed','indexed','script_source_matched','script_source_unmatched'], true)) {
            $updated = catalog_one($db,
                'SELECT updated_at FROM ue_game_catalog_stats WHERE game_id=?', [$gameId]);
            $coverage = $updated === null ? null
                : (new PdoGameDependencyFileCountQuery($db, $config))->forCurrentGame(
                    $gameId, (string)($game['profile_engine'] ?? ''),
                    (string)$updated['updated_at']);
            $counter = match ($depFilter) {
                'unindexed' => 'unindexed_references',
                'indexed' => 'indexed_references',
                'script_source_matched' => 'source_matched_script_references',
                'script_source_unmatched' => 'source_unmatched_script_references',
            };
            $references = isset($coverage[$counter]) ? (int)$coverage[$counter] : null;
        } elseif ($engineMajor === 5 && $zenCoverage !== null
            && isset($zenCoverage['dependency_count'], $zenCoverage['unresolved_references'])) {
            $references = $depFilter === 'unresolved'
                ? (int)$zenCoverage['unresolved_references']
                : ($depFilter === 'resolved'
                    ? max(0, (int)$zenCoverage['dependency_count']
                        - (int)$zenCoverage['unresolved_references'])
                    : null);
        } elseif ($engineMajor !== 5) {
            $stat = catalog_one($db,
                'SELECT dependency_count,resolved_dependency_count,'
                . 'package_only_dependency_count,missing_dependency_count '
                . 'FROM ue_game_catalog_stats WHERE game_id=?', [$gameId]);
            $references = $stat !== null
                ? ($depFilter === 'unresolved'
                    ? max(0, (int)$stat['dependency_count']
                        - (int)$stat['resolved_dependency_count']
                        - (int)$stat['package_only_dependency_count']
                        - (int)$stat['missing_dependency_count'])
                    : ($depFilter === 'resolved'
                        ? (int)$stat['resolved_dependency_count']
                            + (int)$stat['package_only_dependency_count']
                        : null))
                : null;
        } else {
            $references = null;
        }
        if ($references !== null) {
            $countDescription = ' · ' . number_format($references)
                . ' ' . $filterDescriptions[$depFilter] . ' game-wide'
                . ' (multiple references may belong to one file).';
        }
    }
    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Files</h2><p>'
        . number_format($totalRows) . ' matching files' . catalog_h($countDescription) . '.'
        . ($separateUpkContainers ? ' UPK containers are available under the UPK packages tab.' : '')
        . '</p></div></div><div class="ui-section__body">';
    echo '<form class="table-controls game-files-controls" method="get" data-ui-loading-form aria-describedby="file-filter-help">';
    echo '<input type="hidden" name="id" value="' . (int)$gameId . '">';
    echo '<label for="file-filter">Search files <input id="file-filter" class="wide-search" type="search" name="file_filter" value="' . catalog_h($filter) . '" placeholder="Package, file, MD5, SHA1, GUID"></label>';
    echo '<label for="dependency-filter">Dependencies <select id="dependency-filter" name="dep_filter">';
    $dependencyOptions = $engineMajor === 5
        ? ['' => 'All', 'any' => 'Has dependencies',
            'unresolved' => 'Missing', 'resolved' => 'Found',
            'fully_resolved' => 'Fully indexed files',
            'missing' => 'Missing (confirmed)']
        : ['' => 'All', 'any' => 'Has dependencies',
            'indexed' => 'Packages found', 'unindexed' => 'Missing dependencies',
            'no_gaps' => 'No missing packages',
            'unresolved' => 'Runtime unresolved',
            'resolved' => 'Native confirmed', 'fully_resolved' => 'Native complete'];
    if ($engineMajor === 4 && $gameId === 7) {
        $dependencyOptions['script_source_matched'] = 'Native scripts in source';
        $dependencyOptions['script_source_unmatched'] = 'Native scripts not in source';
    }
    // Historic bookmarks to dep_filter=native_script / dep_filter=missing
    // continue to work even though the user-facing filter no longer conflates them.
    foreach ($dependencyOptions as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($depFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label for="type-filter">File type <select id="type-filter" name="type_filter">';
    foreach (['' => 'All', 'map' => 'Maps', 'music' => 'Music', 'sound' => 'Sounds', 'texture' => 'Textures', 'static_mesh' => 'Static meshes', 'animation' => 'Animations', 'particle_effect' => 'Particles/effects', 'gui' => 'GUI', 'content' => 'Content', 'package' => 'Packages'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($typeFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>';
    if ($showCompression) {
        echo '<label for="compression-filter">Internal compression <select id="compression-filter" name="compression_filter">';
        foreach (['' => 'All', 'compressed' => 'Compressed chunks', 'uncompressed' => 'No compressed chunks'] as $value => $label) {
            echo '<option value="' . catalog_h($value) . '"' . ($compressionFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<span class="game-files-filter-actions">';
    echo CatalogUi::button('Apply filters', ['type' => 'submit']);
    if ($filter !== '' || $depFilter !== '' || $typeFilter !== '' || ($showCompression && $compressionFilter !== '')) {
        echo CatalogUi::button('Clear filters', ['href' => 'game-files.php?id=' . (int)$gameId, 'variant' => 'quiet']);
    }
    echo '<span data-ui-loading-indicator>' . CatalogUi::loadingState('Applying filters…', true) . '</span></span>';
    echo '<span id="file-filter-help" class="ui-sr-only">Changing filters reloads this file list.</span></form>';
    echo '<p class="muted small">'
        . ($engineMajor === 5
            ? 'UE5 Missing means the required package/object identity was not found among current indexed targets; '
                . 'it may still exist in a container not imported into this catalogue.'
            : 'Missing means a required dependency package is absent from this game&#039;s verified catalogue, '
                . 'even if the referencing file itself was successfully indexed. '
                . 'Packages Found identifies matching package names, not verified imported objects or runtime mounts. '
                . 'UT4 native script-module names are checked against source declarations only; '
                . 'a source match does not prove runtime module registration.')
        . '</p>';

    echo $pagination;
    if ($files === []) {
        $action = ($filter !== '' || $depFilter !== '' || $typeFilter !== '' || ($showCompression && $compressionFilter !== ''))
            ? ['label' => 'Clear filters', 'href' => 'game-files.php?id=' . (int)$gameId]
            : ['label' => 'Back to games', 'href' => 'games.php'];
        echo CatalogUi::emptyState('No files found', 'No catalog files match the selected filters.', $action, '⌕');
    } else {
        echo '<div class="ui-table-region"><table id="game-files-table" class="' . ($showCompression ? 'game-files-table--with-compression' : 'game-files-table--no-compression') . '"><caption class="ui-sr-only">Files for ' . catalog_h((string)$game['name']) . '</caption><thead><tr>';
        echo '<th scope="col">File / Package'
            . '<div class="small muted">'
            . game_files_sort_link('sort file', 'file', $sort, $dir)
            . ' · '
            . game_files_sort_link('sort package', 'package', $sort, $dir)
            . '</div></th>';
        echo '<th scope="col">Identity</th>';
        echo '<th scope="col">' . game_files_sort_link('Version', 'version', $sort, $dir) . '</th>';
        echo '<th scope="col">' . game_files_sort_link('Size', 'size', $sort, $dir) . '</th>';
        if ($showCompression) {
            echo '<th scope="col">' . game_files_sort_link('Internal Compression', 'compression', $sort, $dir) . '</th>';
        }
        echo '<th scope="col">Dependencies'
            . ($engineMajor === 5
                ? '<div class="small muted" title="Zen catalogue matches are calculated per page; a misleading source-only sort is disabled">source references</div>'
                : '<div class="small muted">sort '.game_files_sort_link('unresolved', 'deps', $sort, $dir).'</div>')
            . '</th>';
        echo '<th scope="col">Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($files as $file) {
            $isZen = strtolower((string)($file['extension'] ?? '')) === 'zenpkg';
            $zenPackageId = strtoupper((string)($file['zen_package_id'] ?? ''));
            $staticResolved = $isZen ? (int)($file['static_resolved_count'] ?? 0) : 0;
            $staticScripts = $isZen ? (int)($file['static_script_count'] ?? 0) : 0;
            $dependencyBadges = [];
            // Package-level identities remain stored and resolved as before.
            // Do not present their internal outcome as a separate warning badge;
            // it does not indicate a missing dependency.
            foreach (['resolved', 'missing', 'unresolved'] as $key) {
                $count = (int)($file[$key . '_count'] ?? 0);
                if (!$count) continue;
                $badge = game_files_status_badge($key, $count);
                if ($key === 'resolved' && $staticResolved > 0) {
                    $badge = '<a href="file-examine.php?id=' . (int)$file['id']
                        . '&amp;tab=imports#package-tables" title="Exact catalogue matches: '
                        . $staticResolved . ' source references found in indexed packages'
                        . ($staticScripts > 0 ? ', including ' . $staticScripts
                            . ' cooked ScriptObjects entries in global.utoc' : '')
                        . '">' . $badge . '</a>';
                }
                $dependencyBadges[] = '<div>' . $badge . '</div>';
            }
            $deps = (int)($file['dependency_projection_current'] ?? 0) !== 1
                ? ($isZen && (string)($file['v5_stage_status'] ?? '') === 'staged'
                    ? '<span class="muted" title="Package metadata is published; its engine-specific dependency pass has not completed">Dependencies not evaluated</span>'
                    : '<span class="muted" title="Source-correct dependency publication is not current">unknown — pending recheck</span>')
                : ($dependencyBadges !== [] ? '<div class="game-files-dependency-list">' . implode('', $dependencyBadges) . '</div>' : '<span class="muted">none</span>');
            if (!$isZen && $gameDependencyCurrent) {
                $availability = $pagePackageCoverage[(int)$file['id']] ?? null;
                if (is_array($availability)) {
                    $indexed = (int)$availability['indexed'];
                    $notIndexed = (int)$availability['unindexed'];
                    $nativeSourceMatched = (int)$availability['source_matched_script'];
                    $nativeSourceUnmatched = (int)$availability['source_unmatched_script'];
                    $coverageBadges = [];
                    if ($indexed > 0) {
                        $coverageBadges[] = '<div><span class="dep resolved" '
                            . 'title="Indexed package candidate, not native VerifyImport or a proven runtime mount">'
                            . 'found: ' . number_format($indexed) . '</span></div>';
                    }
                    if ($nativeSourceMatched > 0) {
                        $coverageBadges[] = '<div><span class="dep package_only" '
                            . 'title="UT4 /Script/ module name has a corresponding declaration in retained game/engine source; imported class or runtime loading not verified">'
                            . 'script in source: ' . number_format($nativeSourceMatched) . '</span></div>';
                    }
                    if ($nativeSourceUnmatched > 0) {
                        $coverageBadges[] = '<div><span class="dep unresolved" '
                            . 'title="No matching UT4 module declaration in the retained source snapshot; an external compiled module may still exist">'
                            . 'script not in source: ' . number_format($nativeSourceUnmatched) . '</span></div>';
                    }
                    if ($notIndexed > 0) {
                        $coverageBadges[] = '<div><span class="dep unresolved" '
                            . 'title="Dependency package missing from this game&#039;s catalogue; it may be present under a different game or outside this catalogue">'
                            . 'missing: ' . number_format($notIndexed) . '</span></div>';
                    }
                    $deps = $coverageBadges !== []
                        ? '<div class="game-files-dependency-list">' . implode('', $coverageBadges) . '</div>'
                        : '<span class="muted">none</span>';
                }
            }
            $compressed = (int)($file['is_compressed'] ?? 0) === 1;
            $compression = CatalogUi::badge($compressed ? 'compressed chunks' : 'none', $compressed ? 'warning' : 'success');
            [$fileType, $fileTypeClass] = game_files_type_from_extension((string)($file['extension'] ?? ''));
            $id = (int)$file['id'];
            $packageVersion = (int)($file['package_version'] ?? 0);
            $licenseeVersion = (int)($file['licensee_version'] ?? 0);
            $versionText = $isZen ? 'Zen / UE5' : ($packageVersion . ($licenseeVersion ? ' / ' . $licenseeVersion : ''));
            $packageName = (string)$file['package_name'];
            $originalName = (string)$file['original_name'];

            echo '<tr>';
            if ($isZen) {
                $containerId = (int)($file['zen_container_id'] ?? 0);
                echo '<td class="game-files-primary">'
                    . '<div class="game-files-primary-file"><a class="game-files-file-link" href="file-examine.php?id=' . $id . '" title="Examine Zen package">'
                    . catalog_h($packageName) . '</a></div>'
                    . '<div class="game-files-primary-package mono small muted">IoStore package member'
                    . ($containerId > 0 ? ' · <a href="iostore-info.php?id=' . $containerId . '">Container #' . $containerId . '</a>' : '')
                    . ' · <span title="Internal extracted-member storage format, not a native UE package extension">.zenpkg is internal storage</span></div>'
                    . '</td>';
                echo '<td class="identity-cell"><span class="mono small guid-value" title="Unreal Engine 5 Zen package identity, not a package GUID">FPackageId '
                    . catalog_h($zenPackageId !== '' ? $zenPackageId : 'unavailable')
                    . '</span><br><small class="muted">No classic package GUID</small>'
                    . '<br><span class="mono small identity-md5">MD5 ' . catalog_h((string)$file['md5']) . '</span></td>';
            } else {
                echo '<td class="game-files-primary">'
                    . '<div class="game-files-primary-file"><a class="game-files-file-link" href="file-examine.php?id=' . $id . '" title="Examine file">' . catalog_h($originalName) . '</a></div>'
                    . '<div class="game-files-primary-package mono small"><a class="game-files-package-link" href="file-info.php?id=' . $id . '" title="View package details">' . catalog_h($packageName) . '</a></div>'
                    . '</td>';
                echo '<td class="identity-cell"><span class="mono small guid-value">' . catalog_h($file['package_guid']) . '</span><br><span class="mono small identity-md5">MD5 ' . catalog_h($file['md5']) . '</span></td>';
            }
            echo '<td class="game-files-version">'
                . '<div class="mono">' . catalog_h($versionText) . '</div>'
                . '<div class="game-files-version-type"><span class="dep file-type-pill ' . catalog_h($fileTypeClass) . '">' . catalog_h($fileType) . '</span></div>'
                . '</td>';
            echo '<td class="game-files-size">' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
            if ($showCompression) {
                echo '<td class="game-files-compression">' . $compression . '</td>';
            }
            echo '<td class="game-files-dependencies">' . $deps . '</td>';
            echo '<td class="game-files-actions">' . game_files_actions($id, $originalName, $maintenanceCsrf, $isAdmin) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo $pagination;
    }

    echo '</div></section><a class="game-files-to-top" href="#game-files-top" title="To Top" aria-label="To Top">↑</a>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'This page could not be loaded.');
    catalog_foot();
}
