<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
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

    $where = 'WHERE f.game_id=?';
    $args = [$gameId];
    if ($filter !== '') {
        $where .= ' AND (f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?)';
        $like = '%' . $filter . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }

    $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files f ' . $where, $args)['c'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    $pageNo = min($pageNo, $totalPages);
    $offset = ($pageNo - 1) * $limit;

    $files = catalog_all(
        $db,
        "SELECT f.*, SUM(d.status='resolved') resolved_count, SUM(d.status='missing') missing_count, SUM(d.status='package_only') package_only_count, SUM(d.status='common') common_count, COUNT(DISTINCT l.id) source_location_count
         FROM ue_files f
         LEFT JOIN ue_dependencies d ON d.file_id=f.id
         LEFT JOIN ue_file_locations l ON l.file_id=f.id AND l.exists_in_source=1
         $where
         GROUP BY f.id
         ORDER BY f.package_name, f.original_name
         LIMIT $limit OFFSET $offset",
        $args
    );

    catalog_head((string)$game['name']);
    echo '<script src="assets/catalog-popups.js"></script>';
    echo '<div class="card hero"><h1>' . catalog_h($game['name']) . '</h1><p class="muted">Files, dependency status, hidden-path downloads and popup details.</p><p><a class="button" href="games.php">Back to games</a></p></div>';

    echo '<div class="card">';
    echo '<div class="section-title"><h2>Files</h2></div>';
    echo '<form class="table-controls" method="get">';
    echo '<input type="hidden" name="id" value="' . (int)$gameId . '">';
    echo '<label>Search files <input name="file_filter" value="' . catalog_h($filter) . '" placeholder="Package, file, MD5, SHA1, GUID"></label> ';
    echo '<button>Search</button> ';
    if ($filter !== '') {
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

    echo '<div class="scroll"><table id="game-files-table" class="reorderable-table"><thead><tr>';
    echo '<th draggable="true" data-col="package" title="Drag to rearrange columns">Package</th>';
    echo '<th draggable="true" data-col="file" title="Drag to rearrange columns">File</th>';
    echo '<th draggable="true" data-col="identity" title="Drag to rearrange columns">Identity</th>';
    echo '<th draggable="true" data-col="size" title="Drag to rearrange columns">Size</th>';
    echo '<th draggable="true" data-col="type" title="Drag to rearrange columns">Type</th>';
    echo '<th draggable="true" data-col="deps" title="Drag to rearrange columns">Dependencies</th>';
    echo '<th draggable="true" data-col="sources" title="Drag to rearrange columns">Sources</th>';
    echo '<th draggable="true" data-col="actions" title="Drag to rearrange columns">Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($files as $file) {
        $deps = '';
        foreach (['resolved','missing','package_only','common'] as $key) {
            $count = (int)($file[$key . '_count'] ?? 0);
            if ($count) {
                $deps .= '<span class="dep ' . $key . '">' . $key . ': ' . $count . '</span>';
            }
        }
        $deps = $deps ?: '<span class="muted">none</span>';
        $compressed = (int)($file['is_compressed'] ?? 0) === 1;
        $type = '<span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span>';
        $sources = (int)($file['source_location_count'] ?? 0);
        $sourceText = $sources ? '<span class="dep resolved">locations: ' . $sources . '</span>' : '<span class="muted">none</span>';
        $id = (int)$file['id'];
        echo '<tr>';
        echo '<td class="mono" data-col="package">' . catalog_h($file['package_name']) . '</td>';
        echo '<td data-col="file">' . catalog_h($file['original_name']) . '</td>';
        echo '<td data-col="identity"><span class="mono small">GUID ' . catalog_h($file['package_guid']) . '</span><br><span class="mono small">MD5 ' . catalog_h($file['md5']) . '</span></td>';
        echo '<td data-col="size">' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
        echo '<td data-col="type">' . $type . '</td>';
        echo '<td data-col="deps">' . $deps . '</td>';
        echo '<td data-col="sources">' . $sourceText . '</td>';
        echo '<td data-col="actions"><a href="file-info.php?id=' . $id . '" onclick="return catalogPopup(this.href,\'fileInfo' . $id . '\',1100,780)">details</a> | <a href="download-info.php?id=' . $id . '" onclick="return catalogPopup(this.href,\'downloadInfo' . $id . '\',1000,760)">download</a> | <a href="index.php?page=examine&id=' . $id . '">examine</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';

    echo <<<'HTML'
<script>
(function () {
    const table = document.getElementById('game-files-table');
    if (!table) return;

    const storageKey = 'unrealdb.gameFiles.columnOrder';
    function orderedColumns() {
        return Array.from(table.querySelectorAll('thead th')).map(function (th) { return th.dataset.col; });
    }
    function applyOrder(order) {
        if (!order || !order.length) return;
        table.querySelectorAll('tr').forEach(function (row) {
            order.forEach(function (col) {
                const cell = row.querySelector('[data-col="' + col + '"]');
                if (cell) row.appendChild(cell);
            });
        });
    }
    try {
        applyOrder(JSON.parse(localStorage.getItem(storageKey) || '[]'));
    } catch (e) {}

    let dragged = null;
    table.querySelectorAll('thead th').forEach(function (th) {
        th.addEventListener('dragstart', function () {
            dragged = th;
            th.classList.add('dragging');
        });
        th.addEventListener('dragend', function () {
            th.classList.remove('dragging');
            dragged = null;
            localStorage.setItem(storageKey, JSON.stringify(orderedColumns()));
        });
        th.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragged || dragged === th) return;
            const cols = orderedColumns();
            const from = cols.indexOf(dragged.dataset.col);
            const to = cols.indexOf(th.dataset.col);
            if (from < 0 || to < 0) return;
            cols.splice(to, 0, cols.splice(from, 1)[0]);
            applyOrder(cols);
        });
    });
})();
</script>
HTML;

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
