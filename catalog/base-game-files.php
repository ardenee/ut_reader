<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes Base Game File Protection administration.
 * Why: Protected-GUID CRUD, seeding and list SQL now belong to a shared policy administration service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogBaseGameProtectionAdminService;

catalog_start_session();

const BASE_GAME_PAGE_LIMIT_MAX = 200;

function bg_int_get(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function bg_url(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'base-game-files.php' . ($query ? '?' . http_build_query($query) : '');
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Base game files')) {
        exit;
    }

    $service = new CatalogBaseGameProtectionAdminService($db);
    $games = $service->games();
    $gameId = $service->normalizeGameId(bg_int_get('game_id', 0, 0, PHP_INT_MAX));
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = bg_int_get('limit', 100, 25, BASE_GAME_PAGE_LIMIT_MAX);
    $page = bg_int_get('page', 1, 1, PHP_INT_MAX);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('base-game-files');
        $action = (string)($_POST['action'] ?? '');
        $result = $service->handle(
            $action,
            $_POST,
            $gameId,
            isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null
        );
        $_SESSION['base_game_flash'] = $result['message'];

        if (in_array($action, ['seed_current_game', 'add'], true)) {
            header('Location: base-game-files.php?game_id=' . (int)$result['game_id']);
        } else {
            header('Location: ' . bg_url(['page' => $page]));
        }
        exit;
    }

    $list = $service->page($gameId, $query, $limit, $page);
    $rows = $list['rows'];
    $totalRows = $list['total_rows'];
    $totalPages = $list['total_pages'];
    $page = $list['page'];
    $offset = $list['offset'];

    catalog_head('Base game files');
    catalog_flash($_SESSION['base_game_flash'] ?? null);
    unset($_SESSION['base_game_flash']);

    echo <<<'CSS'
<style>
.base-game-controls { display:flex; align-items:end; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
.base-game-controls label { display:grid; gap:5px; }
.base-game-table { min-width:1320px; }
.base-game-table th, .base-game-table td { vertical-align:top; }
.base-game-select { width:42px; text-align:center; white-space:nowrap; }
.base-game-guid { white-space:nowrap; }
.base-game-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:12px 0; }
.base-game-table input[type=text], .base-game-table textarea { width:100%; min-width:180px; }
.base-game-table textarea { min-height:54px; }
.base-game-pagination { display:flex; justify-content:space-between; align-items:center; gap:10px; margin:12px 0; flex-wrap:wrap; }
</style>
CSS;

    catalog_page_header('Base game file protection', 'Mark official/base game package GUIDs as dependency-index-only. Protected files cannot be downloaded, transferred by federation, or bundled into ZIP packages.', ['Downloads' => 'download-admin.php', 'Package Normalizer' => 'package-normalize.php', 'Games' => 'games.php']);

    echo '<div class="card"><h2>How this is used</h2><p>Seed a game after importing its official/base files. UnrealDB keeps those exports available for dependency resolution, but blocks the matching GUIDs from public download, mirror requests, federation pulls, approved-request downloads, and bundle ZIP packaging.</p></div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Seed from current catalog</h2><p>This snapshots all currently verified non-zero GUIDs for the selected game into the base-game protection list. Run it after loading the official game files.</p></div></div><div class="ui-section__body">';
    echo '<form class="base-game-controls" method="post" onsubmit="return confirm(\'Seed all current verified GUIDs for this game as official/base files?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('base-game-files')) . '"><input type="hidden" name="action" value="seed_current_game"><label>Game<select name="game_id" required><option value="">Choose game</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label><button type="submit">Seed selected game</button></form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Add one GUID</h2><p>Use this for manual additions or corrections.</p></div></div><div class="ui-section__body">';
    echo '<form class="base-game-controls" method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('base-game-files')) . '"><input type="hidden" name="action" value="add"><label>Game<select name="game_id" required><option value="">Choose game</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label><label>Package GUID<input type="text" name="package_guid" class="mono" required placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"></label><label>Package<input type="text" name="package_name" placeholder="PackageName"></label><label>File<input type="text" name="original_name" placeholder="PackageName.utx"></label><label>Notes<input type="text" name="notes" placeholder="Optional"></label><button type="submit">Add/update GUID</button></form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Current protected GUIDs</h2><p>Visible rows can be edited and saved together. Page limit is capped at ' . BASE_GAME_PAGE_LIMIT_MAX . ' rows to avoid PHP post variable limits.</p></div></div><div class="ui-section__body">';
    echo '<form class="base-game-controls" method="get"><label>Search<input type="search" name="q" value="' . catalog_h($query) . '" placeholder="GUID, package, file, notes"></label><label>Game<select name="game_id"><option value="0">All games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label><label>Rows/page<select name="limit">';
    foreach ([50, 100, 200] as $option) {
        echo '<option value="' . $option . '"' . ($limit === $option ? ' selected' : '') . '>' . $option . '</option>';
    }
    echo '</select></label><button type="submit">Apply filter</button></form>';

    $pagination = '<nav class="base-game-pagination"><span class="muted">Showing ' . ($totalRows ? ($offset + 1) : 0) . '–' . min($offset + $limit, $totalRows) . ' of ' . $totalRows . ' protected GUID rows.</span><span>'
        . ($page > 1 ? CatalogUi::button('First', ['href' => bg_url(['page' => 1]), 'variant' => 'secondary', 'size' => 'sm']) . CatalogUi::button('Previous', ['href' => bg_url(['page' => $page - 1]), 'variant' => 'secondary', 'size' => 'sm']) : '')
        . ($page < $totalPages ? CatalogUi::button('Next', ['href' => bg_url(['page' => $page + 1]), 'variant' => 'secondary', 'size' => 'sm']) . CatalogUi::button('Last', ['href' => bg_url(['page' => $totalPages]), 'variant' => 'secondary', 'size' => 'sm']) : '')
        . '</span></nav>';
    echo $pagination;

    if (!$rows) {
        echo CatalogUi::emptyState('No protected base-game GUIDs found', 'Seed a game or add a GUID manually.');
        echo '</div></section>';
        catalog_foot();
        exit;
    }

    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('base-game-files')) . '">';
    echo '<div class="base-game-actions"><button type="submit" name="action" value="save_visible">Save visible edits</button><button type="submit" name="action" value="delete_selected" onclick="return confirm(\'Remove selected GUIDs from base-game protection?\')">Remove selected</button></div>';
    echo '<div class="ui-table-region"><table class="base-game-table"><thead><tr><th class="base-game-select">Remove</th><th>Game</th><th class="base-game-guid">GUID</th><th>Package</th><th>File</th><th>Current file</th><th>Notes</th><th>Updated</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        echo '<tr>';
        echo '<td class="base-game-select"><input type="checkbox" name="delete_ids[]" value="' . $id . '"><input type="hidden" name="ids[]" value="' . $id . '"></td>';
        echo '<td>' . catalog_h($row['game_name']) . '</td>';
        echo '<td class="mono small base-game-guid">' . catalog_h($row['package_guid']) . '</td>';
        echo '<td><input type="text" name="package_name[' . $id . ']" value="' . catalog_h((string)$row['package_name']) . '"></td>';
        echo '<td><input type="text" name="original_name[' . $id . ']" value="' . catalog_h((string)$row['original_name']) . '"></td>';
        echo '<td>' . (!empty($row['current_file_id']) ? '<a href="file-info.php?id=' . (int)$row['current_file_id'] . '">file #' . (int)$row['current_file_id'] . '</a>' : '<span class="muted">not currently in catalog</span>') . '</td>';
        echo '<td><textarea name="notes[' . $id . ']">' . catalog_h((string)$row['notes']) . '</textarea></td>';
        echo '<td class="mono small">' . catalog_h($row['updated_at']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div><div class="base-game-actions"><button type="submit" name="action" value="save_visible">Save visible edits</button><button type="submit" name="action" value="delete_selected" onclick="return confirm(\'Remove selected GUIDs from base-game protection?\')">Remove selected</button></div></form>';
    echo $pagination;
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Base game files error');
    }
    echo CatalogUi::alert('danger', $e->getMessage(), 'Base game file protection failed.');
    catalog_foot();
}
