<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the federation inventory administration interface.
 * Why: Pagination/rendering remain here while refresh, transfer queueing and remote request orchestration are delegated.
 * Role: Federation UI entry point backed by bounded query and action services.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationInventoryActions;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoFederationInventoryListQuery;

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/FederationInventory.php';
require_once __DIR__ . '/../lib/FederationInventoryRefresh.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';
require_once __DIR__ . '/../lib/FederationState.php';

const FI_PARENT_PAGE_SIZE = 100;
const FI_CHILD_PAGE_SIZE = 950;

function fi_page(mixed $value): int
{
    return max(1, (int)$value);
}

function fi_cursor_move(mixed $value): string
{
    $move = strtolower(trim((string)$value));
    return in_array($move, ['first', 'next', 'prev', 'last'], true) ? $move : 'first';
}

function fi_identity(array $row): string
{
    return '<div class="mono small nowrap"><strong>GUID:</strong> ' . catalog_h((string)($row['package_guid'] ?: '—')) . '</div>'
        . '<div class="mono small nowrap"><strong>MD5:</strong> ' . catalog_h((string)($row['md5'] ?: '—')) . '</div>'
        . '<div class="mono small nowrap"><strong>SHA1:</strong> ' . catalog_h((string)($row['sha1'] ?: '—')) . '</div>';
}

/** @param array<string,mixed> $params */
function fi_parent_url(int $peerId, string $tab, array $params = []): string
{
    return 'inventories.php?' . http_build_query(array_merge(['peer_id' => $peerId, 'tab' => $tab], $params));
}

/** @param array<string,mixed> $params */
function fi_child_url(int $peerId, array $params = []): string
{
    return 'inventories.php?' . http_build_query(array_merge(['peer_id' => $peerId], $params));
}

/** @param array<string,mixed> $config */
function fi_decode_cursor(array $config, string $context, string $token, string &$move, int &$page): ?array
{
    if ($token === '') {
        return null;
    }
    $cursor = CatalogKeysetPaginator::decode($config, $context, $token);
    if ($cursor === null) {
        $move = 'first';
        $page = 1;
    }
    return $cursor;
}

function fi_cursor_context(string $view, int $peerId, string $tab, bool $ignoreBaseGame, int $limit): string
{
    return json_encode([
        'page' => 'federation-inventories',
        'view' => $view,
        'peer_id' => $peerId,
        'tab' => $tab,
        'ignore_base_game' => $ignoreBaseGame,
        'limit' => $limit,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** @param callable(array<string,mixed>):string $url */
function fi_pagination(
    int $page,
    int $pages,
    bool $hasPrevious,
    bool $hasNext,
    string $previousCursor,
    string $nextCursor,
    callable $url
): void {
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($hasPrevious) {
        echo '<a class="button" href="' . catalog_h($url(['cursor' => null, 'cursor_move' => null, 'cursor_page' => null])) . '">First</a> ';
        echo '<a class="button" href="' . catalog_h($url(['cursor' => $previousCursor, 'cursor_move' => 'prev', 'cursor_page' => max(1, $page - 1)])) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($hasNext) {
        echo '<a class="button" href="' . catalog_h($url(['cursor' => $nextCursor, 'cursor_move' => 'next', 'cursor_page' => min($pages, $page + 1)])) . '">Next</a> ';
        echo '<a class="button" href="' . catalog_h($url(['cursor' => null, 'cursor_move' => 'last', 'cursor_page' => $pages])) . '">Last</a>';
    }
    echo '</p>';
}

function fi_cursor_hidden(string $token, string $move, int $page): string
{
    return '<input type="hidden" name="cursor" value="' . catalog_h($token) . '">'
        . '<input type="hidden" name="cursor_move" value="' . catalog_h($move) . '">'
        . '<input type="hidden" name="cursor_page" value="' . $page . '">';
}

function fi_child_key(array $row): string
{
    return hash('sha256', (int)$row['game_id'] . "\0" . strtolower(trim((string)$row['required_package'])));
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $inventoryQuery = new PdoFederationInventoryListQuery($db);
    $inventoryActions = new CatalogFederationInventoryActions($db, $config);
    base_game_ensure($db);
    $role = federation_reconcile_site_role($db);
    $ignoreBaseGame = federation_ignore_base_game_files($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_inventories');
        $actionResult = $inventoryActions->handle($_POST, $role, $ignoreBaseGame);
        if ((string)$actionResult['flash'] !== '') {
            $_SESSION['fed_inventory_flash'] = (string)$actionResult['flash'];
        }
        header('Location: ' . (string)$actionResult['redirect']);
        exit;
    }

    if (!catalog_require_admin_page('Federation Inventories')) {
        exit;
    }

    catalog_head('Federation Inventories');
    catalog_flash($_SESSION['fed_inventory_flash'] ?? null);
    unset($_SESSION['fed_inventory_flash']);
    catalog_page_header(
        $role === 'parent' ? 'Children’s Inventories' : ($role === 'child' ? 'Parent Inventory' : 'Federation Inventories'),
        $role === 'parent'
            ? 'Review files held by each child that satisfy Parent dependencies or are missing from the Parent.'
            : ($role === 'child' ? 'Only local missing dependency packages are shown. Select packages and submit a request to the Parent.' : 'Connect to a Parent or approve a Child before inventories are available.'),
        federation_main_links()
    );

    if ($role === 'standalone') {
        echo '<div class="card"><h2>No federation inventory available</h2><p>This server has no established federation connection.</p><p><a class="button" href="connections.php">Open Connections</a></p></div>';
        catalog_foot();
        exit;
    }

    if ($role === 'parent') {
        $children = federation_child_peers($db, true);
        if (!$children) {
            echo '<div class="card"><h2>No connected children</h2><p>Approve a child connection before viewing inventories.</p></div>';
            catalog_foot();
            exit;
        }
        $peerId = (int)($_GET['peer_id'] ?? $children[0]['id']);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="child" AND is_active=1', [$peerId]) ?: $children[0];
        $peerId = (int)$peer['id'];
        $tab = strtolower(trim((string)($_GET['tab'] ?? 'required')));
        $tab = in_array($tab, ['required', 'missing'], true) ? $tab : 'required';
        $counts = $inventoryQuery->parentCounts($peerId, $ignoreBaseGame);
        $total = $tab === 'required' ? $counts['required'] : $counts['missing'];
        $pages = max(1, (int)ceil($total / FI_PARENT_PAGE_SIZE));
        $move = fi_cursor_move($_GET['cursor_move'] ?? 'first');
        $pageNumber = fi_page($_GET['cursor_page'] ?? ($move === 'last' ? $pages : 1));
        $pageNumber = min($pageNumber, $pages);
        if ($move === 'first') {
            $pageNumber = 1;
        } elseif ($move === 'last') {
            $pageNumber = $pages;
        }
        $context = fi_cursor_context('parent', $peerId, $tab, $ignoreBaseGame, FI_PARENT_PAGE_SIZE);
        $cursorToken = trim((string)($_GET['cursor'] ?? ''));
        $cursor = fi_decode_cursor($config, $context, $cursorToken, $move, $pageNumber);
        $page = $inventoryQuery->parentCursorPage($peerId, $tab, $ignoreBaseGame, FI_PARENT_PAGE_SIZE, $cursor, $move);
        if ($page['rows'] === [] && $total > 0 && $move !== 'first') {
            $move = 'first';
            $pageNumber = 1;
            $cursorToken = '';
            $page = $inventoryQuery->parentCursorPage($peerId, $tab, $ignoreBaseGame, FI_PARENT_PAGE_SIZE, null, 'first');
        }
        $previousCursor = is_array($page['first_cursor']) ? CatalogKeysetPaginator::encode($config, $context, $page['first_cursor']) : '';
        $nextCursor = is_array($page['last_cursor']) ? CatalogKeysetPaginator::encode($config, $context, $page['last_cursor']) : '';
        $rows = $page['rows'];

        echo '<div class="card"><h2>Selected child</h2><form method="get"><label>Child<br><select name="peer_id" onchange="this.form.submit()">';
        foreach ($children as $child) {
            echo '<option value="' . (int)$child['id'] . '"' . ((int)$child['id'] === $peerId ? ' selected' : '') . '>' . catalog_h($child['site_name']) . '</option>';
        }
        echo '</select></label><input type="hidden" name="tab" value="' . catalog_h($tab) . '"></form>';
        echo '<p><strong>Last contact:</strong> ' . catalog_h((string)($peer['last_seen_at'] ?? 'never')) . '</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_inventories')) . '"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="tab" value="' . catalog_h($tab) . '"><button name="action" value="refresh">Refresh both inventories now</button></form></div>';

        echo '<div class="card"><p class="page-links"><a class="button" href="' . catalog_h(fi_parent_url($peerId, 'required')) . '">Required by Parent (' . $counts['required'] . ')</a> <a class="button" href="' . catalog_h(fi_parent_url($peerId, 'missing')) . '">Missing from Parent (' . $counts['missing'] . ')</a></p></div>';
        echo '<div class="card"><h2>' . ($tab === 'required' ? 'Required by Parent' : 'Missing from Parent') . '</h2><p>Showing <strong>' . count($rows) . '</strong> of <strong>' . $total . '</strong> files.</p>';
        if (!$rows) {
            echo '<p class="muted">No matching files.</p>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_inventories')) . '"><input type="hidden" name="action" value="queue_parent_pull"><input type="hidden" name="peer_id" value="' . $peerId . '"><input type="hidden" name="tab" value="' . catalog_h($tab) . '">' . fi_cursor_hidden($cursorToken, $move, $pageNumber);
            echo '<p><label><input type="checkbox" data-check-all="inventory-files"> Check all files on this page</label> <button>Download selected files from child</button></p>';
            echo '<table><tr><th>Select</th><th>Game</th><th>Needed by Parent files</th><th>Package</th><th>Child file</th><th>N/I/E</th><th>GUID / MD5 / SHA1</th><th>Size</th></tr>';
            foreach ($rows as $row) {
                echo '<tr><td><input type="checkbox" data-check-group="inventory-files" name="peer_file_ids[]" value="' . (int)$row['id'] . '"></td><td>' . catalog_h((string)$row['display_game']) . '</td><td>' . (int)$row['needed_by_parent_files'] . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="nowrap">0 / ' . (int)$row['import_count'] . ' / ' . (int)$row['export_count'] . '</td><td>' . fi_identity($row) . '</td><td class="nowrap">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td></tr>';
            }
            echo '</table><p><button>Download selected files from child</button></p></form>';
            fi_pagination(
                $pageNumber,
                $pages,
                (bool)$page['has_previous'],
                (bool)$page['has_next'],
                $previousCursor,
                $nextCursor,
                static fn(array $params): string => fi_parent_url($peerId, $tab, $params)
            );
        }
        echo '</div>';
    } else {
        $parent = federation_parent_peer($db, true);
        if (!$parent) {
            echo '<div class="card"><h2>No active Parent</h2><p>Reconnect to a Parent before requesting files.</p></div>';
            catalog_foot();
            exit;
        }
        $peerId = (int)$parent['id'];
        $requestStatuses = [];
        $requestStatusError = '';
        try {
            $requestStatuses = $inventoryActions->childRequestStatuses($parent);
        } catch (Throwable $statusError) {
            $requestStatusError = $statusError->getMessage();
        }
        $ignoreBaseGame = federation_ignore_base_game_files($db, $parent);
        $total = $inventoryQuery->childMissingTotal($ignoreBaseGame);
        $pages = max(1, (int)ceil($total / FI_CHILD_PAGE_SIZE));
        $move = fi_cursor_move($_GET['cursor_move'] ?? 'first');
        $pageNumber = fi_page($_GET['cursor_page'] ?? ($move === 'last' ? $pages : 1));
        $pageNumber = min($pageNumber, $pages);
        if ($move === 'first') {
            $pageNumber = 1;
        } elseif ($move === 'last') {
            $pageNumber = $pages;
        }
        $context = fi_cursor_context('child', $peerId, 'required', $ignoreBaseGame, FI_CHILD_PAGE_SIZE);
        $cursorToken = trim((string)($_GET['cursor'] ?? ''));
        $cursor = fi_decode_cursor($config, $context, $cursorToken, $move, $pageNumber);
        $page = $inventoryQuery->childCursorPage($peerId, $ignoreBaseGame, FI_CHILD_PAGE_SIZE, $cursor, $move);
        if ($page['rows'] === [] && $total > 0 && $move !== 'first') {
            $move = 'first';
            $pageNumber = 1;
            $cursorToken = '';
            $page = $inventoryQuery->childCursorPage($peerId, $ignoreBaseGame, FI_CHILD_PAGE_SIZE, null, 'first');
        }
        $previousCursor = is_array($page['first_cursor']) ? CatalogKeysetPaginator::encode($config, $context, $page['first_cursor']) : '';
        $nextCursor = is_array($page['last_cursor']) ? CatalogKeysetPaginator::encode($config, $context, $page['last_cursor']) : '';
        $rows = $page['rows'];
        foreach ($rows as &$row) {
            $row['item_key'] = fi_child_key($row);
            $row['request_state'] = $requestStatuses[strtolower(trim((string)$row['required_package']))] ?? null;
        }
        unset($row);

        echo '<div class="card"><h2>Parent source</h2><table><tr><th>Parent</th><td><strong>' . catalog_h($parent['site_name']) . '</strong></td></tr><tr><th>URL</th><td class="mono path">' . catalog_h($parent['site_url']) . '</td></tr><tr><th>Base-game policy</th><td>' . catalog_h(federation_base_game_policy_label($db, $parent)) . '</td></tr></table>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_inventories')) . '"><input type="hidden" name="peer_id" value="' . $peerId . '"><button name="action" value="refresh">Refresh Parent inventory now</button></form></div>';

        echo '<div class="card"><h2>Child required files</h2><p>Only packages required by current local missing dependencies are listed. Parent availability is based on the latest cached Parent inventory.</p><p>Eligible packages: <strong>' . $total . '</strong>.</p>';
        if ($requestStatusError !== '') {
            echo CatalogUi::alert('warning', 'Existing request status could not be checked. ' . $requestStatusError, 'Parent request status unavailable');
        }
        if (!$rows) {
            echo '<p class="muted">No eligible missing dependency packages.</p>';
        } else {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_inventories')) . '"><input type="hidden" name="action" value="submit_child_request"><input type="hidden" name="peer_id" value="' . $peerId . '">' . fi_cursor_hidden($cursorToken, $move, $pageNumber);
            echo '<p><label><input type="checkbox" data-check-all="required-packages"> Check all packages on this page</label> <button>Request selected files from Parent</button></p>';
            echo '<table><tr><th>Select</th><th>Game</th><th>Required package</th><th>Example missing object</th><th>Missing objects</th><th>Required by files</th><th>Parent availability</th><th>Request status</th><th>Parent file</th></tr>';
            foreach ($rows as $row) {
                $available = !empty($row['parent_available']);
                $requestState = is_array($row['request_state'] ?? null) ? $row['request_state'] : null;
                $activeRequest = $requestState !== null && in_array((string)($requestState['item_status'] ?? ''), ['requested', 'approved', 'queued', 'downloading', 'downloaded'], true);
                $select = $activeRequest
                    ? '<span class="muted">already requested</span>'
                    : '<input type="checkbox" data-check-group="required-packages" name="item_keys[]" value="' . catalog_h($row['item_key']) . '">';
                $requestDisplay = $requestState
                    ? '<a href="requests.php?request_id=' . (int)$requestState['request_id'] . '">' . catalog_h((string)$requestState['item_status']) . '</a>'
                    : '<span class="muted">not requested</span>';
                $exampleObject = trim((string)$row['required_object_path']) !== '' ? (string)$row['required_object_path'] : '—';
                echo '<tr><td>' . $select . '</td><td>' . catalog_h($row['game_name']) . '</td><td class="mono">' . catalog_h($row['required_package']) . '</td><td class="mono path">' . catalog_h($exampleObject) . '</td><td>' . (int)$row['object_count'] . '</td><td>' . (int)$row['use_count'] . '</td><td>' . ($available ? '<span class="pill green">available</span>' : '<span class="pill amber">not currently held</span>') . '</td><td>' . $requestDisplay . '</td><td>' . catalog_h($available ? (string)$row['parent_file'] . ' (' . catalog_bytes((int)$row['parent_file_size']) . ')' : 'Request may remain waiting until Parent imports it') . '</td></tr>';
            }
            echo '</table><p><button>Request selected files from Parent</button></p></form>';
            fi_pagination(
                $pageNumber,
                $pages,
                (bool)$page['has_previous'],
                (bool)$page['has_next'],
                $previousCursor,
                $nextCursor,
                static fn(array $params): string => fi_child_url($peerId, $params)
            );
        }
        echo '</div>';
    }

    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(master){master.addEventListener("change",function(){var group=master.getAttribute("data-check-all");document.querySelectorAll("[data-check-group=\""+group+"\"]").forEach(function(box){box.checked=master.checked;});});});})();</script>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation inventory error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
