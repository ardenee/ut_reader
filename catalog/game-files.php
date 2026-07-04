<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
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
    $marker = '';
    if ($activeSort === $key) {
        $marker = $activeDir === 'asc' ? ' ▲' : ' ▼';
    }
    return '<a class="sort-link" href="' . catalog_h(game_files_url(['sort' => $key, 'dir' => $nextDir, 'file_page' => 1])) . '">' . catalog_h($label . $marker) . '</a>';
}

function game_files_type_from_extension(string $ext): array
{
    $ext = strtolower(trim($ext, '. '));
    return match ($ext) {
        'unr', 'ut2', 'ut3', 'umap' => ['map', 'type-map'],
        'umx' => ['music', 'type-music'],
        'uax' => ['sound', 'type-sound'],
        'utx' => ['texture', 'type-texture'],
        'usx' => ['static mesh', 'type-static-mesh'],
        'ukx' => ['animation', 'type-animation'],
        'upx' => ['particle/effect', 'type-particle-effect'],
        'ugx' => ['gui', 'type-gui'],
        'con' => ['content', 'type-content'],
        'u', 'un2', 'upk', 'uasset' => ['package', 'type-package'],
        default => [$ext !== '' ? $ext : 'unknown', 'type-unknown'],
    };
}

function game_files_type_filter_sql(string $type): array
{
    $map = [
        'map' => ['unr', 'ut2', 'ut3', 'umap'],
        'music' => ['umx'],
        'sound' => ['uax'],
        'texture' => ['utx'],
        'static_mesh' => ['usx'],
        'animation' => ['ukx'],
        'particle_effect' => ['upx'],
        'gui' => ['ugx'],
        'content' => ['con'],
        'package' => ['u', 'un2', 'upk', 'uasset'],
    ];
    return $map[$type] ?? [];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $gameId = game_files_int('id', 0, 1, PHP_INT_MAX);
    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }

    $configuredLimit = (int)(fed_setting($db, 'game_file_display_limit', '100') ?: 100);
    $limit = max(1, min(500, $configuredLimit > 0 ? $configuredLimit : 100));

    $pageNo = game_files_int('file_page', 1, 1, PHP_INT_MAX);
    $filter = trim((string)($_GET['file_filter'] ?? ''));
    $depFilter = trim((string)($_GET['dep_filter'] ?? ''));
    $typeFilter = trim((string)($_GET['type_filter'] ?? ''));
    $compressionFilter = trim((string)($_GET['compression_filter'] ?? ''));
    $sort = trim((string)($_GET['sort'] ?? 'package'));
    $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
    $dir = $dir === 'desc' ? 'desc' : 'asc';

    $sortMap = [
        'package' => true,
        'file' => true,
        'version' => true,
        'size' => true,
        'compression' => true,
        'deps' => true,
        'uploaded' => true,
    ];
    if (!isset($sortMap[$sort])) {
        $sort = 'package';
    }

    $where = 'WHERE f.game_id=?';
    $args = [$gameId];

    if ($filter !== '') {
        $where .= ' AND (f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?)';
        $like = '%' . $filter . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }

    if (in_array($depFilter, ['resolved', 'missing', 'package_only', 'common', 'any'], true)) {
        if ($depFilter === 'any') {
            $where .= ' AND EXISTS (SELECT 1 FROM ue_dependencies dx WHERE dx.file_id=f.id)';
        } else {
            $where .= ' AND EXISTS (SELECT 1 FROM ue_dependencies dx WHERE dx.file_id=f.id AND dx.status=?)';
            $args[] = $depFilter;
        }
    }

    $typeExts = game_files_type_filter_sql($typeFilter);
    if ($typeExts) {
        $where .= ' AND f.extension IN (' . implode(',', array_fill(0, count($typeExts), '?')) . ')';
        foreach ($typeExts as $ext) {
            $args[] = $ext;
        }
    }

    if ($compressionFilter === 'compressed') {
        $where .= ' AND f.is_compressed=1';
    } elseif ($compressionFilter === 'uncompressed') {
        $where .= ' AND f.is_compressed=0';
    }

    $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files f ' . $where, $args)['c'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    $pageNo = min($pageNo, $totalPages);
    $offset = ($pageNo - 1) * $limit;

    $files = CatalogGameFileListService::fetchPage($db, $where, $args, $sort, $dir, $limit, $offset);

    catalog_head((string)$game['name']);
    echo '<div class="card hero"><h1>' . catalog_h($game['name']) . '</h1><p class="muted">Files, versions, dependency status and downloads.</p><p><a class="button" href="games.php">Back to games</a></p></div>';

    echo '<div class="card">';
    echo '<div class="section-title"><h2>Files</h2></div>';
    echo '<form class="table-controls" method="get">';
    echo '<input type="hidden" name="id" value="' . (int)$gameId . '">';
    echo '<label>Search files <input class="wide-search" name="file_filter" value="' . catalog_h($filter) . '" placeholder="Package, file, MD5, SHA1, GUID"></label> ';
    echo '<label>Dependencies <select name="dep_filter">';
    foreach (['' => 'All', 'any' => 'Has dependencies', 'missing' => 'Missing', 'resolved' => 'Resolved', 'package_only' => 'Package only', 'common' => 'Common'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($depFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>File type <select name="type_filter">';
    foreach (['' => 'All', 'map' => 'Maps', 'music' => 'Music', 'sound' => 'Sounds', 'texture' => 'Textures', 'static_mesh' => 'Static meshes', 'animation' => 'Animations', 'particle_effect' => 'Particles/effects', 'gui' => 'GUI', 'content' => 'Content', 'package' => 'Packages'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($typeFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>Compression <select name="compression_filter">';
    foreach (['' => 'All', 'compressed' => 'Compressed', 'uncompressed' => 'Uncompressed'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($compressionFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label> ';
    echo '<button>Apply</button> ';
    if ($filter !== '' || $depFilter !== '' || $typeFilter !== '' || $compressionFilter !== '') {
        echo '<a class="button" href="game-files.php?id=' . (int)$gameId . '">Clear</a>';
    }
    echo '</form>';

    echo '<div class="page-links">';
    if ($pageNo > 1) {
        echo '<a class="button" href="' . catalog_h(game_files_url(['file_page' => 1])) . '">First</a> ';
        echo '<a class="button" href="' . catalog_h(game_files_url(['file_page' => $pageNo - 1])) . '">Previous</a> ';
    }
    echo '<span class="subtle">Page ' . $pageNo . ' of ' . $totalPages . '</span> ';
    if ($pageNo < $totalPages) {
        echo '<a class="button" href="' . catalog_h(game_files_url(['file_page' => $pageNo + 1])) . '">Next</a> ';
        echo '<a class="button" href="' . catalog_h(game_files_url(['file_page' => $totalPages])) . '">Last</a> ';
    }
    echo '</div>';

    echo '<div class="scroll"><table id="game-files-table"><thead><tr>';
    echo '<th>' . game_files_sort_link('Package', 'package', $sort, $dir) . '</th>';
    echo '<th>' . game_files_sort_link('File', 'file', $sort, $dir) . '</th>';
    echo '<th>Identity</th>';
    echo '<th>' . game_files_sort_link('Version', 'version', $sort, $dir) . '</th>';
    echo '<th>' . game_files_sort_link('Size', 'size', $sort, $dir) . '</th>';
    echo '<th>' . game_files_sort_link('Compression', 'compression', $sort, $dir) . '</th>';
    echo '<th>' . game_files_sort_link('Dependencies', 'deps', $sort, $dir) . '</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($files as $file) {
        $deps = '';
        foreach (['resolved', 'missing', 'package_only', 'common'] as $key) {
            $count = (int)($file[$key . '_count'] ?? 0);
            if ($count) {
                $deps .= '<span class="dep ' . $key . '">' . $key . ': ' . $count . '</span>';
            }
        }
        $deps = $deps ?: '<span class="muted">none</span>';
        $compressed = (int)($file['is_compressed'] ?? 0) === 1;
        $compression = '<span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span>';
        [$fileType, $fileTypeClass] = game_files_type_from_extension((string)($file['extension'] ?? ''));
        $id = (int)$file['id'];
        $packageVersion = (int)($file['package_version'] ?? 0);
        $licenseeVersion = (int)($file['licensee_version'] ?? 0);
        $versionText = $packageVersion . ($licenseeVersion ? ' / ' . $licenseeVersion : '');

        echo '<tr>';
        echo '<td class="mono">' . catalog_h($file['package_name']) . '</td>';
        echo '<td>' . catalog_h($file['original_name']) . '<br><span class="dep file-type-pill ' . catalog_h($fileTypeClass) . '">' . catalog_h($fileType) . '</span></td>';
        echo '<td class="identity-cell"><span class="mono small guid-value">' . catalog_h($file['package_guid']) . '</span><br><span class="mono small">MD5 ' . catalog_h($file['md5']) . '</span></td>';
        echo '<td class="mono">' . catalog_h($versionText) . '</td>';
        echo '<td>' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
        echo '<td>' . $compression . '</td>';
        echo '<td>' . $deps . '</td>';
        echo '<td><a href="file-info.php?id=' . $id . '">details</a> | <a href="download-info.php?id=' . $id . '">download</a> | <a href="file-examine.php?id=' . $id . '">examine</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
