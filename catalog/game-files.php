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

catalog_start_session();
require_once __DIR__ . '/lib/FederationAuth.php';
require_once __DIR__ . '/lib/CatalogGameFileListService.php';

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
        'package' => ['u', 'upk', 'uasset'],
    ];
    return $map[$type] ?? [];
}

function game_files_status_badge(string $status, int $count): string
{
    $tone = match ($status) {
        'resolved' => 'success',
        'missing' => 'danger',
        'package_only' => 'warning',
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
    string $nextCursor
): string {
    $start = '';
    if ($hasPrevious) {
        $start .= CatalogUi::button('First', ['href' => game_files_url([
            'cursor' => null,
            'cursor_move' => null,
            'cursor_page' => null,
            'file_page' => null,
        ]), 'variant' => 'secondary', 'size' => 'sm']);
        $start .= CatalogUi::button('Previous', ['href' => game_files_url([
            'cursor' => $previousCursor,
            'cursor_move' => 'prev',
            'cursor_page' => max(1, $pageNo - 1),
            'file_page' => null,
        ]), 'variant' => 'secondary', 'size' => 'sm']);
    }

    $end = '';
    if ($hasNext) {
        $end .= CatalogUi::button('Next', ['href' => game_files_url([
            'cursor' => $nextCursor,
            'cursor_move' => 'next',
            'cursor_page' => min($totalPages, $pageNo + 1),
            'file_page' => null,
        ]), 'variant' => 'secondary', 'size' => 'sm']);
        $end .= CatalogUi::button('Last', ['href' => game_files_url([
            'cursor' => null,
            'cursor_move' => 'last',
            'cursor_page' => $totalPages,
            'file_page' => null,
        ]), 'variant' => 'secondary', 'size' => 'sm']);
    }

    return '<nav class="game-files-pagination" aria-label="File pagination">'
        . '<div class="game-files-pagination__start">' . $start . '</div>'
        . '<span class="subtle game-files-pagination__current" aria-current="page">Page ' . $pageNo . ' of ' . $totalPages . '</span>'
        . '<div class="game-files-pagination__end">' . $end . '</div>'
        . '</nav>';
}

function game_files_actions(int $fileId, string $originalName, string $csrf, bool $isAdmin): string
{
    $confirm = 'Remove ' . $originalName . ' from storage and the catalog? This cannot be undone. Dependency links for the game will then be rebuilt.';
    $html = '<div class="game-files-actions-list">';
    $html .= '<a class="game-files-download-link" href="download-info.php?id=' . $fileId . '" title="Download" aria-label="Download ' . catalog_h($originalName) . '">⇩</a>';

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

.game-files-pagination { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 12px; margin: 12px 0; }
.game-files-pagination__start, .game-files-pagination__end { display: flex; align-items: center; gap: 6px; }
.game-files-pagination__start { justify-self: start; }
.game-files-pagination__end { justify-self: end; }
.game-files-pagination__current { justify-self: center; white-space: nowrap; }

#game-files-table { width: 100% !important; min-width: 1180px !important; table-layout: auto !important; }
#game-files-table.game-files-table--no-compression { min-width: 1060px !important; }
#game-files-table th:nth-child(1), #game-files-table td:nth-child(1),
#game-files-table th:nth-child(2), #game-files-table td:nth-child(2),
#game-files-table th:nth-child(3), #game-files-table td:nth-child(3),
#game-files-table th:nth-child(4), #game-files-table td:nth-child(4),
#game-files-table th:nth-child(5), #game-files-table td:nth-child(5) { white-space: nowrap; }
#game-files-table .game-files-package, #game-files-table .game-files-version, #game-files-table .game-files-size, #game-files-table .game-files-actions { width: 1%; }
#game-files-table th:nth-child(3), #game-files-table td:nth-child(3), #game-files-table .identity-cell { width: 38ch; min-width: 38ch; max-width: 38ch; }
.game-files-file-link, .game-files-package-link { font-weight: 650; }
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
    .game-files-pagination { gap: 8px; }
    .game-files-pagination__start, .game-files-pagination__end { gap: 4px; }
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
    $showCompression = $engineMajor >= 3;
    $separateUpkContainers = $engineMajor === 3;

    $configuredLimit = (int)(fed_setting($db, 'game_file_display_limit', '100') ?: 100);
    $limit = max(1, min(500, $configuredLimit > 0 ? $configuredLimit : 100));
    $filter = trim((string)($_GET['file_filter'] ?? ''));
    $depFilter = trim((string)($_GET['dep_filter'] ?? ''));
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
    if (in_array($depFilter, ['resolved', 'missing', 'package_only', 'common', 'any'], true)) {
        if ($depFilter === 'any') {
            $where .= ' AND EXISTS (SELECT 1 FROM ue_dependency_package_summaries dx WHERE dx.file_id=f.id)';
        } else {
            $countColumn = match ($depFilter) {
                'resolved' => 'resolved_count',
                'missing' => 'missing_count',
                'package_only' => 'package_only_count',
                default => 'common_count',
            };
            $where .= ' AND EXISTS (SELECT 1 FROM ue_dependency_package_summaries dx WHERE dx.file_id=f.id AND dx.' . $countColumn . '>0)';
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
    $pageNo = game_files_int('cursor_page', $move === 'last' ? $totalPages : 1, 1, $totalPages);
    if ($move === 'first') {
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
    if ($cursorToken !== '' && $cursor === null) {
        $move = 'first';
        $pageNo = 1;
    }

    $page = CatalogGameFileListService::fetchCursorPage($db, $where, $args, $sort, $dir, $limit, $cursor, $move);
    $files = $page['rows'];
    if ($files === [] && $totalRows > 0 && $move !== 'first') {
        $move = 'first';
        $pageNo = 1;
        $page = CatalogGameFileListService::fetchCursorPage($db, $where, $args, $sort, $dir, $limit, null, 'first');
        $files = $page['rows'];
    }

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
        $nextCursor
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

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Files</h2><p>' . catalog_h((string)$totalRows) . ' matching files.'
        . ($separateUpkContainers ? ' UPK containers are available under the UPK packages tab.' : '')
        . '</p></div></div><div class="ui-section__body">';
    echo '<form class="table-controls game-files-controls" method="get" data-ui-loading-form aria-describedby="file-filter-help">';
    echo '<input type="hidden" name="id" value="' . (int)$gameId . '">';
    echo '<label for="file-filter">Search files <input id="file-filter" class="wide-search" type="search" name="file_filter" value="' . catalog_h($filter) . '" placeholder="Package, file, MD5, SHA1, GUID"></label>';
    echo '<label for="dependency-filter">Dependencies <select id="dependency-filter" name="dep_filter">';
    foreach (['' => 'All', 'any' => 'Has dependencies', 'missing' => 'Missing', 'resolved' => 'Resolved', 'package_only' => 'Package only', 'common' => 'Common'] as $value => $label) {
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

    echo $pagination;
    if ($files === []) {
        $action = ($filter !== '' || $depFilter !== '' || $typeFilter !== '' || ($showCompression && $compressionFilter !== ''))
            ? ['label' => 'Clear filters', 'href' => 'game-files.php?id=' . (int)$gameId]
            : ['label' => 'Back to games', 'href' => 'games.php'];
        echo CatalogUi::emptyState('No files found', 'No catalog files match the selected filters.', $action, '⌕');
    } else {
        echo '<div class="ui-table-region"><table id="game-files-table" class="' . ($showCompression ? 'game-files-table--with-compression' : 'game-files-table--no-compression') . '"><caption class="ui-sr-only">Files for ' . catalog_h((string)$game['name']) . '</caption><thead><tr>';
        echo '<th scope="col">' . game_files_sort_link('Package', 'package', $sort, $dir) . '</th>';
        echo '<th scope="col">' . game_files_sort_link('File', 'file', $sort, $dir) . '</th>';
        echo '<th scope="col">Identity</th>';
        echo '<th scope="col">' . game_files_sort_link('Version', 'version', $sort, $dir) . '</th>';
        echo '<th scope="col">' . game_files_sort_link('Size', 'size', $sort, $dir) . '</th>';
        if ($showCompression) {
            echo '<th scope="col">' . game_files_sort_link('Internal Compression', 'compression', $sort, $dir) . '</th>';
        }
        echo '<th scope="col">' . game_files_sort_link('Dependencies', 'deps', $sort, $dir) . '</th>';
        echo '<th scope="col">Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($files as $file) {
            $dependencyBadges = [];
            foreach (['resolved', 'missing', 'package_only', 'common'] as $key) {
                $count = (int)($file[$key . '_count'] ?? 0);
                if ($count) {
                    $dependencyBadges[] = '<div>' . game_files_status_badge($key, $count) . '</div>';
                }
            }
            $deps = $dependencyBadges !== [] ? '<div class="game-files-dependency-list">' . implode('', $dependencyBadges) . '</div>' : '<span class="muted">none</span>';
            $compressed = (int)($file['is_compressed'] ?? 0) === 1;
            $compression = CatalogUi::badge($compressed ? 'compressed chunks' : 'none', $compressed ? 'warning' : 'success');
            [$fileType, $fileTypeClass] = game_files_type_from_extension((string)($file['extension'] ?? ''));
            $id = (int)$file['id'];
            $packageVersion = (int)($file['package_version'] ?? 0);
            $licenseeVersion = (int)($file['licensee_version'] ?? 0);
            $versionText = $packageVersion . ($licenseeVersion ? ' / ' . $licenseeVersion : '');
            $packageName = (string)$file['package_name'];
            $originalName = (string)$file['original_name'];

            echo '<tr>';
            echo '<td class="mono game-files-package"><a class="game-files-package-link" href="file-info.php?id=' . $id . '" title="View package details">' . catalog_h($packageName) . '</a></td>';
            echo '<td><a class="game-files-file-link" href="file-examine.php?id=' . $id . '" title="Examine file">' . catalog_h($originalName) . '</a><br><span class="dep file-type-pill ' . catalog_h($fileTypeClass) . '">' . catalog_h($fileType) . '</span></td>';
            echo '<td class="identity-cell"><span class="mono small guid-value">' . catalog_h($file['package_guid']) . '</span><br><span class="mono small identity-md5">MD5 ' . catalog_h($file['md5']) . '</span></td>';
            echo '<td class="mono game-files-version">' . catalog_h($versionText) . '</td>';
            echo '<td class="game-files-size">' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
            if ($showCompression) {
                echo '<td>' . $compression . '</td>';
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