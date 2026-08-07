<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for game page.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);


require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();

function game_page_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function game_page_url(array $params): string
{
    $base = [
        'page' => 'game',
        'id' => (int)($_GET['id'] ?? 0),
    ];
    $query = array_merge($base, $_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'index.php?' . http_build_query($query);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $gameId = game_page_int('id', 0, 1, PHP_INT_MAX);
    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }

    $configuredLimit = (int)($config['ui']['game_file_display_limit'] ?? $config['game_file_display_limit'] ?? 100);
    $defaultLimit = max(1, min(500, $configuredLimit > 0 ? $configuredLimit : 100));
    $allowedLimits = [25, 50, 100, 200, 500];
    $limit = game_page_int('limit', $defaultLimit, 1, 500);
    if (!in_array($limit, $allowedLimits, true)) {
        $limit = $defaultLimit;
    }

    $pageNo = game_page_int('file_page', 1, 1, PHP_INT_MAX);
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
        "SELECT f.*, SUM(d.status='resolved') resolved_count, SUM(d.status='missing') missing_count, SUM(d.status='package_only') package_only_count, SUM(d.status='common') common_count
         FROM ue_files f
         LEFT JOIN ue_dependencies d ON d.file_id=f.id
         $where
         GROUP BY f.id
         ORDER BY f.package_name, f.original_name
         LIMIT $limit OFFSET $offset",
        $args
    );

    catalog_head((string)$game['name']);
    echo '<div class="card hero"><h1>' . catalog_h($game['name']) . '</h1><p class="muted">' . catalog_h($game['description']) . '</p></div>';

    echo '<div class="card">';
    echo '<div class="section-title"><div><h2>Files</h2><p class="muted small">Showing ' . count($files) . ' of ' . $totalRows . ' files. Default page size is controlled by <code>ui.game_file_display_limit</code> in <code>catalog/config.php</code>.</p></div></div>';
    echo '<form class="table-controls" method="get">';
    echo '<input type="hidden" name="page" value="game"><input type="hidden" name="id" value="' . (int)$gameId . '">';
    echo '<label>Server filter <input name="file_filter" value="' . catalog_h($filter) . '" placeholder="Package, file, MD5, SHA1, GUID"></label> ';
    echo '<label>Rows <select name="limit">';
    foreach ($allowedLimits as $option) {
        echo '<option value="' . $option . '"' . ($option === $limit ? ' selected' : '') . '>' . $option . '</option>';
    }
    echo '</select></label> <button>Apply</button> ';
    echo '<input type="search" id="displayed-file-filter" placeholder="Filter displayed page" autocomplete="off">';
    echo '</form>';

    echo '<div class="page-links">';
    if ($pageNo > 1) {
        echo '<a class="button" href="' . catalog_h(game_page_url(['file_page' => 1])) . '">First</a> ';
        echo '<a class="button" href="' . catalog_h(game_page_url(['file_page' => $pageNo - 1])) . '">Previous</a> ';
    }
    echo '<span class="subtle">Page ' . $pageNo . ' of ' . $totalPages . '</span> ';
    if ($pageNo < $totalPages) {
        echo '<a class="button" href="' . catalog_h(game_page_url(['file_page' => $pageNo + 1])) . '">Next</a> ';
        echo '<a class="button" href="' . catalog_h(game_page_url(['file_page' => $totalPages])) . '">Last</a> ';
    }
    echo '</div>';

    echo '<div class="scroll"><table id="game-files-table" class="reorderable-table"><thead><tr>';
    echo '<th draggable="true" data-col="package">Package</th>';
    echo '<th draggable="true" data-col="file">File</th>';
    echo '<th draggable="true" data-col="size">Size</th>';
    echo '<th draggable="true" data-col="deps">Dependencies</th>';
    echo '<th draggable="true" data-col="md5">MD5</th>';
    echo '<th draggable="true" data-col="actions">Actions</th>';
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
        echo '<tr>';
        echo '<td class="mono" data-col="package">' . catalog_h($file['package_name']) . '</td>';
        echo '<td data-col="file">' . catalog_h($file['original_name']) . '<br><span class="muted small">GUID ' . catalog_h($file['package_guid']) . '</span></td>';
        echo '<td data-col="size">' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
        echo '<td data-col="deps">' . $deps . '</td>';
        echo '<td class="mono small" data-col="md5">' . catalog_h($file['md5']) . '</td>';
        echo '<td data-col="actions"><a href="' . catalog_h('index.php?page=file&id=' . (int)$file['id']) . '">details</a> | <a href="' . catalog_h('index.php?page=examine&id=' . (int)$file['id']) . '">examine</a> | <a href="' . catalog_h('index.php?page=download&id=' . (int)$file['id']) . '">download</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '</div>';

    if (catalog_support_is_admin()) {
        echo '<div class="card"><h2>Upload files</h2><form method="post" enctype="multipart/form-data" action="index.php?page=upload"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload')) . '"><input type="hidden" name="game_id" value="' . (int)$gameId . '"><p class="muted">Max per file: ' . catalog_h(catalog_bytes((int)$config['max_upload_bytes'])) . '. Failed scans move to the admin-only unverified folder.</p><input type="file" name="files[]" multiple required> <button>Upload and scan</button></form></div>';
    }

    echo <<<'HTML'
<script>
(function () {
    const filter = document.getElementById('displayed-file-filter');
    const table = document.getElementById('game-files-table');
    if (!table) return;

    if (filter) {
        filter.addEventListener('input', function () {
            const q = filter.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

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
            cols.splice(to, 0, cols.splice(from, 1)[0]);
            applyOrder(cols);
        });
    });
})();
</script>
HTML;

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Catalog Error');
    }
    echo '<div class="msg err"><strong>Error:</strong> ' . catalog_h($e->getMessage()) . '</div>';
    catalog_foot();
}
