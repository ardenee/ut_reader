<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders or processes the federation interface for Federation Inventories.
 * Why: It keeps parent/child federation administration, inventory, requests, and transfer workflows separate from
 *      general catalog pages.
 * Role: Federation UI/administration entry point backed by shared federation services.
 * Audit: Federation-specific route; consolidate shared behavior into services rather than merging distinct
 *        parent/child screens blindly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoFederationInventoryListQuery;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;

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

function fi_local_presence_sql(string $alias = 'pf'): string
{
    return 'EXISTS (
        SELECT 1 FROM ue_files local
        WHERE local.scan_status="verified"
          AND ((COALESCE(' . $alias . '.package_guid,"")<>"" AND local.package_guid=' . $alias . '.package_guid)
            OR (COALESCE(' . $alias . '.md5,"")<>"" AND local.md5=' . $alias . '.md5))
    )';
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

/** @return array{cursor:string,cursor_move:string,cursor_page:int} */
function fi_post_cursor_state(): array
{
    return [
        'cursor' => trim((string)($_POST['cursor'] ?? '')),
        'cursor_move' => fi_cursor_move($_POST['cursor_move'] ?? 'first'),
        'cursor_page' => fi_page($_POST['cursor_page'] ?? 1),
    ];
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

/**
 * @param callable(array<string,mixed>):string $url
 */
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

/** @return array<string,array<string,mixed>> */
function fi_child_request_statuses(PDO $db, array $parent): array
{
    $result = fed_http_post_signed(
        rtrim((string)$parent['site_url'], '/') . '/api/federation/request-status.php',
        (string)fed_setting($db, 'site_id', ''),
        federation_peer_stored_signing_secret($db, $parent),
        ['package_statuses' => true]
    );
    if (is_array($result['policy'] ?? null)) {
        federation_cache_parent_base_game_policy($db, (int)$parent['id'], $result['policy']);
    }
    if (empty($result['ok']) || !is_array($result['packages'] ?? null)) {
        throw new RuntimeException('Parent request status check failed: ' . (string)($result['error'] ?? 'invalid response'));
    }
    return $result['packages'];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $inventoryQuery = new PdoFederationInventoryListQuery($db);
    base_game_ensure($db);
    $role = federation_reconcile_site_role($db);
    $ignoreBaseGame = federation_ignore_base_game_files($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_inventories');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $peerId = (int)($_POST['peer_id'] ?? 0);
        $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND is_active=1', [$peerId]);
        if (!$peer) {
            throw new RuntimeException('Active federation peer not found.');
        }
        $cursorState = fi_post_cursor_state();

        if ($action === 'refresh') {
            $result = federation_pull_inventory_from_peer($db, $peerId);
            if ($role === 'parent' && (string)$peer['peer_role'] === 'child') {
                $remote = federation_request_child_refresh_parent_inventory($db, $peerId);
                $_SESSION['fed_inventory_flash'] = 'Inventories refreshed: received ' . (int)($result['received'] ?? 0) . ' child rows; child received ' . (int)($remote['received'] ?? 0) . ' parent rows.';
            } elseif ($role === 'child' && (string)$peer['peer_role'] === 'parent') {
                $push = federation_push_inventory_to_parent($db, $peerId);
                $_SESSION['fed_inventory_flash'] = 'Parent inventory refreshed and local inventory pushed: ' . (!empty($push['ok']) ? 'success' : 'failed') . '.';
            }
            $cursorState = ['cursor' => '', 'cursor_move' => 'first', 'cursor_page' => 1];
        } elseif ($action === 'queue_parent_pull') {
            if ($role !== 'parent' || (string)$peer['peer_role'] !== 'child') {
                throw new RuntimeException('Only a Parent may download selected files from a child inventory.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['peer_file_ids'] ?? []), static fn(int $id): bool => $id > 0)));
            if (!$ids || count($ids) > FI_PARENT_PAGE_SIZE) {
                throw new RuntimeException('Select between 1 and ' . FI_PARENT_PAGE_SIZE . ' files.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = catalog_all(
                $db,
                'SELECT pf.* FROM ue_federation_peer_files pf '
                . 'WHERE pf.peer_id=? AND pf.id IN (' . $placeholders . ') '
                . 'AND NOT (' . fi_local_presence_sql('pf') . ')'
                . ($ignoreBaseGame ? ' AND COALESCE(pf.is_base_game,0)=0' : ''),
                array_merge([$peerId], $ids)
            );
            $insert = $db->prepare(
                'INSERT INTO ue_federation_transfer_jobs(peer_id,direction,remote_file_id,status,speed_limit_kbps,wait_after_seconds,bytes_total) '
                . 'VALUES(?,"parent_pull_from_child",?,"queued",?,?,?)'
            );
            $queued = 0;
            foreach ($rows as $row) {
                $remoteFileId = (int)($row['remote_file_id'] ?? 0);
                if ($remoteFileId <= 0 || catalog_one($db, 'SELECT id FROM ue_federation_transfer_jobs WHERE peer_id=? AND direction="parent_pull_from_child" AND remote_file_id=? AND status IN ("queued","running","downloaded") LIMIT 1', [$peerId, $remoteFileId])) {
                    continue;
                }
                $insert->execute([
                    $peerId,
                    $remoteFileId,
                    (int)fed_setting($db, 'max_download_kbps', '0'),
                    (int)fed_setting($db, 'delay_between_downloads_seconds', '5'),
                    (int)$row['file_size'],
                ]);
                $queued++;
            }
            fed_log($db, $peerId, null, 'INFO', 'PARENT_PULL_QUEUE', 'Queued ' . $queued . ' selected child inventory file(s).');
            $_SESSION['fed_inventory_flash'] = 'Queued ' . $queued . ' file(s) from this child.';
        } elseif ($action === 'submit_child_request') {
            if ($role !== 'child' || (string)$peer['peer_role'] !== 'parent') {
                throw new RuntimeException('Only a Child may request missing dependency files from its Parent.');
            }
            $ignoreBaseGame = federation_ignore_base_game_files($db, $peer);
            $context = fi_cursor_context('child', $peerId, 'required', $ignoreBaseGame, FI_CHILD_PAGE_SIZE);
            $move = $cursorState['cursor_move'];
            $pageNumber = $cursorState['cursor_page'];
            $cursor = fi_decode_cursor($config, $context, $cursorState['cursor'], $move, $pageNumber);
            $page = $inventoryQuery->childCursorPage($peerId,
                $ignoreBaseGame,
                FI_CHILD_PAGE_SIZE,
                $cursor,
                $move
            );
            $activeStatuses = fi_child_request_statuses($db, $peer);
            $byKey = [];
            foreach ($page['rows'] as $row) {
                $existing = $activeStatuses[strtolower(trim((string)$row['required_package']))] ?? null;
                if (is_array($existing) && in_array((string)($existing['item_status'] ?? ''), ['requested', 'approved', 'queued', 'downloading', 'downloaded'], true)) {
                    continue;
                }
                $byKey[fi_child_key($row)] = $row;
            }
            $keys = array_values(array_unique(array_filter(array_map('strval', $_POST['item_keys'] ?? []), static fn(string $key): bool => preg_match('/^[a-f0-9]{64}$/', $key) === 1)));
            $items = [];
            foreach ($keys as $key) {
                $row = $byKey[$key] ?? null;
                if (!$row) {
                    continue;
                }
                $items[] = [
                    'required_package' => (string)$row['required_package'],
                    'required_object_path' => (string)$row['required_object_path'],
                    'wanted_guid' => '',
                    'wanted_md5' => '',
                    'game_name' => (string)$row['game_name'],
                    'engine_key' => (string)$row['engine_key'],
                    'use_count' => (int)$row['use_count'],
                    'object_count' => (int)$row['object_count'],
                    'is_base_game_dependency' => !empty($row['is_base_game']),
                ];
            }
            if (!$items) {
                throw new RuntimeException('The selected packages are no longer eligible. The Parent policy, request status, or current cursor page changed; reload the list and select again.');
            }
            $siteLabel = fed_setting($db, 'site_name', '') ?: fed_setting($db, 'site_url', '') ?: 'child';
            try {
                $result = fed_http_post_signed(
                    rtrim((string)$peer['site_url'], '/') . '/api/federation/request-submit.php',
                    (string)fed_setting($db, 'site_id', ''),
                    federation_peer_stored_signing_secret($db, $peer),
                    [
                        'title' => 'Missing file request from ' . $siteLabel,
                        'notes' => 'Requested from the consolidated Parent Inventory page.',
                        'generated_at' => date('c'),
                        'items' => $items,
                    ]
                );
            } catch (RuntimeException $requestError) {
                if (str_contains($requestError->getMessage(), 'Every selected package is excluded by the parent Ignore base-game files policy.')) {
                    $_SESSION['fed_inventory_flash'] = 'The Parent base-game policy changed or was refreshed. Excluded base-game packages were removed from the request list.';
                    header('Location: ' . fi_child_url($peerId));
                    exit;
                }
                throw $requestError;
            }
            if (is_array($result['policy'] ?? null)) {
                federation_cache_parent_base_game_policy($db, $peerId, $result['policy']);
            }
            if (empty($result['ok'])) {
                throw new RuntimeException('Request submission failed: ' . (string)($result['error'] ?? 'unknown error'));
            }
            fed_log($db, $peerId, null, 'INFO', 'REQUEST_SUBMIT_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
            header('Location: requests.php?request_id=' . (int)($result['request_id'] ?? 0));
            exit;
        } else {
            throw new RuntimeException('Unknown inventory action.');
        }

        $tab = strtolower(trim((string)($_POST['tab'] ?? 'required')));
        $tab = in_array($tab, ['required', 'missing'], true) ? $tab : 'required';
        $params = array_filter($cursorState, static fn(mixed $value): bool => $value !== '' && $value !== null && $value !== 1);
        header('Location: ' . ($role === 'parent' ? fi_parent_url($peerId, $tab, $params) : fi_child_url($peerId, $params)));
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
            $requestStatuses = fi_child_request_statuses($db, $parent);
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
