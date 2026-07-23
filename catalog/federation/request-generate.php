<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationPeerSecret.php';
require_once __DIR__ . '/../lib/BaseGameProtection.php';

const REQGEN_PAGE_SIZE = 950;

function reqgen_page(mixed $value): int
{
    return max(1, (int)$value);
}

function reqgen_show_base_game(mixed $value): bool
{
    return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
}

function reqgen_item_key(array $item): string
{
    return hash('sha256', (int)($item['game_id'] ?? 0) . "\0" . strtolower(trim((string)($item['required_package'] ?? ''))));
}

/**
 * Match the dependency package against every useful name stored for a protected
 * base-game file. Some older base lists have a blank or stale package_name but a
 * correct original filename/source file, so package_name alone is insufficient.
 */
function reqgen_base_game_join(): string
{
    $bgStem = '(CASE WHEN LOCATE(".",COALESCE(bg.original_name,""))>0 '
        . 'THEN LEFT(bg.original_name,CHAR_LENGTH(bg.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(bg.original_name,".",-1))-1) '
        . 'ELSE COALESCE(bg.original_name,"") END)';
    $sourceStem = '(CASE WHEN LOCATE(".",COALESCE(bg_source.original_name,""))>0 '
        . 'THEN LEFT(bg_source.original_name,CHAR_LENGTH(bg_source.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(bg_source.original_name,".",-1))-1) '
        . 'ELSE COALESCE(bg_source.original_name,"") END)';

    return ' LEFT JOIN ue_base_game_files bg
             ON bg.game_id=f.game_id
            AND (
                LOWER(TRIM(COALESCE(bg.package_name,"")))=LOWER(TRIM(d.required_package))
                OR LOWER(TRIM(' . $bgStem . '))=LOWER(TRIM(d.required_package))
                OR EXISTS (
                    SELECT 1 FROM ue_files bg_source
                    WHERE bg_source.id=bg.source_file_id
                      AND (
                        LOWER(TRIM(COALESCE(bg_source.package_name,"")))=LOWER(TRIM(d.required_package))
                        OR LOWER(TRIM(' . $sourceStem . '))=LOWER(TRIM(d.required_package))
                      )
                )
            ) ';
}

function reqgen_total(PDO $db, bool $showBaseGame): int
{
    $filter = $showBaseGame ? '' : ' AND bg.id IS NULL';
    $row = catalog_one(
        $db,
        'SELECT COUNT(*) c FROM (
            SELECT f.game_id, d.required_package
            FROM ue_dependencies d
            JOIN ue_files f ON f.id=d.file_id
            ' . reqgen_base_game_join() . '
            WHERE d.status="missing" AND f.scan_status="verified" AND d.required_package<>""' . $filter . '
            GROUP BY f.game_id, d.required_package
        ) grouped_missing_packages'
    );
    return (int)($row['c'] ?? 0);
}

function reqgen_base_game_total(PDO $db): int
{
    $row = catalog_one(
        $db,
        'SELECT COUNT(*) c FROM (
            SELECT f.game_id, d.required_package
            FROM ue_dependencies d
            JOIN ue_files f ON f.id=d.file_id
            ' . reqgen_base_game_join() . '
            WHERE d.status="missing" AND f.scan_status="verified" AND d.required_package<>"" AND bg.id IS NOT NULL
            GROUP BY f.game_id, d.required_package
        ) grouped_base_game_packages'
    );
    return (int)($row['c'] ?? 0);
}

/** @return list<array<string,mixed>> */
function reqgen_items_page(PDO $db, int $page, bool $showBaseGame): array
{
    $offset = ($page - 1) * REQGEN_PAGE_SIZE;
    $filter = $showBaseGame ? '' : ' AND bg.id IS NULL';
    $rows = catalog_all(
        $db,
        'SELECT f.game_id, g.name game_name, COALESCE(p.engine_key,"") engine_key,
                d.required_package,
                MIN(d.required_object_path) required_object_path,
                COUNT(DISTINCT d.required_object_path) object_count,
                COUNT(DISTINCT d.file_id) use_count,
                MAX(CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END) is_base_game
         FROM ue_dependencies d
         JOIN ue_files f ON f.id=d.file_id
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1
         ' . reqgen_base_game_join() . '
         WHERE d.status="missing" AND f.scan_status="verified" AND d.required_package<>""' . $filter . '
         GROUP BY f.game_id, g.name, p.engine_key, d.required_package
         ORDER BY g.name, use_count DESC, d.required_package
         LIMIT ' . REQGEN_PAGE_SIZE . ' OFFSET ' . $offset
    );

    foreach ($rows as &$row) {
        $row['item_key'] = reqgen_item_key($row);
        $row['parent_available'] = null;
        $row['parent_is_base_game'] = false;
        $row['parent_file'] = '';
    }
    unset($row);
    return $rows;
}

/**
 * Ask the selected parent to classify the current package rows before they are
 * displayed or submitted. This is required because a child can be missing an
 * official file and therefore may not have that GUID in its own local base list.
 *
 * @param list<array<string,mixed>> $items
 * @return array<string,array<string,mixed>> keyed by item_key
 */
function reqgen_parent_availability(PDO $db, array $parent, array $items): array
{
    if (!$items) {
        return [];
    }

    $payloadItems = [];
    foreach ($items as $item) {
        $payloadItems[] = [
            'key' => (string)$item['item_key'],
            'required_package' => (string)$item['required_package'],
            'game_name' => (string)$item['game_name'],
            'engine_key' => (string)$item['engine_key'],
            'wanted_guid' => '',
            'wanted_md5' => '',
        ];
    }

    $result = fed_http_post_signed(
        rtrim((string)$parent['site_url'], '/') . '/api/federation/package-availability.php',
        (string)fed_setting($db, 'site_id', ''),
        federation_peer_stored_signing_secret($db, $parent),
        ['items' => $payloadItems]
    );
    if (empty($result['ok']) || !is_array($result['items'] ?? null)) {
        throw new RuntimeException('Parent package protection check failed: ' . (string)($result['error'] ?? 'invalid response'));
    }

    $byKey = [];
    foreach ($result['items'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string)($row['key'] ?? ''));
        if ($key !== '') {
            $byKey[$key] = $row;
        }
    }
    return $byKey;
}

/**
 * @param list<array<string,mixed>> $items
 * @param array<string,array<string,mixed>> $availability
 * @return list<array<string,mixed>>
 */
function reqgen_apply_parent_availability(array $items, array $availability): array
{
    foreach ($items as &$item) {
        $parent = $availability[(string)$item['item_key']] ?? [];
        $item['parent_available'] = array_key_exists('available', $parent) ? !empty($parent['available']) : null;
        $item['parent_is_base_game'] = !empty($parent['is_base_game']);
        $item['parent_file'] = trim((string)($parent['package_name'] ?? '') . ' / ' . (string)($parent['original_name'] ?? ''), ' /');
    }
    unset($item);
    return $items;
}

function reqgen_url(int $page, int $peerId, bool $showBaseGame): string
{
    $query = ['page' => $page, 'peer_id' => $peerId];
    if ($showBaseGame) {
        $query['show_base_game'] = 1;
    }
    return 'request-generate.php?' . http_build_query($query);
}

function reqgen_pagination(int $page, int $pages, int $peerId, bool $showBaseGame): void
{
    if ($pages <= 1) {
        return;
    }
    echo '<p class="page-links">';
    if ($page > 1) {
        echo '<a class="button" href="' . catalog_h(reqgen_url($page - 1, $peerId, $showBaseGame)) . '">Previous</a> ';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span> ';
    if ($page < $pages) {
        echo '<a class="button" href="' . catalog_h(reqgen_url($page + 1, $peerId, $showBaseGame)) . '">Next</a>';
    }
    echo '</p>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);

    $role = strtolower(trim((string)fed_setting($db, 'site_role', 'standalone')));
    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $selectedParentId = (int)($_REQUEST['peer_id'] ?? ($parents[0]['id'] ?? 0));
    $showBaseGame = reqgen_show_base_game($_REQUEST['show_base_game'] ?? '0');
    $page = reqgen_page($_REQUEST['page'] ?? 1);
    $totalItems = reqgen_total($db, $showBaseGame);
    $baseGameTotal = reqgen_base_game_total($db);
    $pages = max(1, (int)ceil($totalItems / REQGEN_PAGE_SIZE));
    $page = min($page, $pages);
    $parent = $selectedParentId > 0
        ? catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$selectedParentId])
        : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        if ($role !== 'child') {
            throw new RuntimeException('Outgoing dependency requests are available only while this site is in Child mode.');
        }
        catalog_check_csrf('fed_reqgen');
        if (!$parent) {
            throw new RuntimeException('Active parent connection not found.');
        }

        $selectedKeys = array_values(array_unique(array_filter(
            array_map('strval', $_POST['item_keys'] ?? []),
            static fn(string $key): bool => preg_match('/^[a-f0-9]{64}$/', $key) === 1
        )));
        if (!$selectedKeys) {
            throw new RuntimeException('Select at least one missing package to request.');
        }
        if (count($selectedKeys) > REQGEN_PAGE_SIZE) {
            throw new RuntimeException('Select no more than ' . REQGEN_PAGE_SIZE . ' packages per request.');
        }

        $pageItems = reqgen_items_page($db, $page, $showBaseGame);
        $pageItems = reqgen_apply_parent_availability($pageItems, reqgen_parent_availability($db, $parent, $pageItems));
        $available = [];
        foreach ($pageItems as $item) {
            if (!empty($item['is_base_game']) || !empty($item['parent_is_base_game'])) {
                continue;
            }
            $available[(string)$item['item_key']] = $item;
        }

        $items = [];
        foreach ($selectedKeys as $key) {
            $item = $available[$key] ?? null;
            if (!$item) {
                continue;
            }
            $items[] = [
                'required_package' => (string)$item['required_package'],
                'required_object_path' => (string)$item['required_object_path'],
                'wanted_guid' => '',
                'wanted_md5' => '',
                'game_name' => (string)$item['game_name'],
                'engine_key' => (string)$item['engine_key'],
                'use_count' => (int)$item['use_count'],
                'object_count' => (int)$item['object_count'],
            ];
        }
        if (!$items) {
            throw new RuntimeException('The selected packages are protected base-game files or are no longer missing. Refresh and try again.');
        }

        $siteLabel = fed_setting($db, 'site_name', '') ?: fed_setting($db, 'site_url', '') ?: fed_setting($db, 'site_id', 'child');
        $payload = [
            'title' => 'Missing file request from ' . $siteLabel,
            'notes' => 'This child is requesting ' . count($items) . ' distinct missing package(s) after local and parent base-game protection checks.',
            'generated_at' => date('c'),
            'items' => $items,
        ];

        $result = fed_http_post_signed(
            rtrim((string)$parent['site_url'], '/') . '/api/federation/request-submit.php',
            (string)fed_setting($db, 'site_id', ''),
            federation_peer_stored_signing_secret($db, $parent),
            $payload
        );
        fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'REQUEST_SUBMIT_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));

        if (!empty($result['ok']) && (int)($result['request_id'] ?? 0) > 0) {
            header('Location: request-status.php?peer_id=' . (int)$parent['id'] . '&request_id=' . (int)$result['request_id']);
            exit;
        }

        $_SESSION['fed_reqgen_result'] = $result + ['selected_packages' => count($items)];
        header('Location: ' . reqgen_url($page, $selectedParentId, $showBaseGame));
        exit;
    }

    if (!catalog_require_admin_page('Missing Files')) {
        exit;
    }

    catalog_head('Missing Files');
    catalog_page_header(
        'Missing Files',
        'Child-side workflow. These packages are missing on this server. The selected parent is checked before display so official base-game files cannot be requested.',
        catalog_federation_links() + ['Request Centre' => 'request-center.php', 'Outgoing Requests' => 'request-status.php', 'Approved Downloads' => 'approved-downloads.php']
    );

    if ($role !== 'child') {
        echo '<div class="card"><h2>Outgoing requests disabled</h2><p>This page is available only in Child mode. In Parent mode, use Child Inventories to find files this parent needs and pull them directly from a child.</p><p><a class="button" href="missing-files.php">Open role-appropriate Missing Files workflow</a></p></div>';
        catalog_foot();
        exit;
    }

    if (isset($_SESSION['fed_reqgen_result'])) {
        $result = (array)$_SESSION['fed_reqgen_result'];
        $message = !empty($result['ok'])
            ? 'Request #' . (int)($result['request_id'] ?? 0) . ' was submitted.'
            : 'The request was not submitted: ' . (string)($result['error'] ?? 'Unknown parent response.');
        echo CatalogUi::alert(!empty($result['ok']) ? 'success' : 'warning', $message, 'Last request attempt');
        unset($_SESSION['fed_reqgen_result']);
    }

    $items = reqgen_items_page($db, $page, $showBaseGame);
    $preflightError = '';
    $parentBaseCount = 0;
    if ($parent && $items) {
        try {
            $items = reqgen_apply_parent_availability($items, reqgen_parent_availability($db, $parent, $items));
            $parentBaseCount = count(array_filter($items, static fn(array $item): bool => !empty($item['parent_is_base_game']) && empty($item['is_base_game'])));
            if (!$showBaseGame) {
                $items = array_values(array_filter($items, static fn(array $item): bool => empty($item['parent_is_base_game'])));
            }
        } catch (Throwable $error) {
            $preflightError = $error->getMessage();
            $items = [];
        }
    }

    echo '<div class="card"><h2>Files this server needs</h2>';
    echo '<p><strong>Direction:</strong> this child &rarr; selected parent. Both local and parent base-game protection lists are checked before a package is offered.</p>';
    echo '<form method="get" action="request-generate.php" class="filter-bar">';
    echo '<input type="hidden" name="page" value="1">';
    echo '<input type="hidden" name="peer_id" value="' . $selectedParentId . '">';
    echo '<label><input type="checkbox" name="show_base_game" value="1"' . ($showBaseGame ? ' checked' : '') . '> Show official base-game packages for reference</label> ';
    echo '<button>Apply filter</button></form>';
    echo '<p>Local missing-package rows: <strong>' . $totalItems . '</strong>. Locally identified base-game packages: <strong>' . $baseGameTotal . '</strong>' . ($showBaseGame ? ' shown as non-requestable references.' : ' hidden.') . ' Parent-identified base-game packages on this page: <strong>' . $parentBaseCount . '</strong>' . ($showBaseGame ? ' shown as non-requestable references.' : ' hidden.') . '</p>';

    if (!$parents) {
        echo '<p class="muted">No active parent connection is configured.</p><p><a class="button" href="join-main-parent.php">Join a parent</a> <a class="button" href="peers.php?role=parent">Manage parents</a></p></div>';
        catalog_foot();
        exit;
    }
    if ($preflightError !== '') {
        echo CatalogUi::alert('warning', 'The parent could not be checked, so request selection is disabled. Update both servers and retry. ' . $preflightError, 'Parent protection check unavailable');
        echo '</div>';
        catalog_foot();
        exit;
    }
    if (!$items) {
        echo '<p class="muted">No requestable missing dependency packages match the current filter.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_reqgen')) . '">';
    echo '<input type="hidden" name="page" value="' . $page . '">';
    echo '<input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '">';
    echo '<p><label>Request files from parent<br><select name="peer_id">';
    foreach ($parents as $parentRow) {
        $selected = (int)$parentRow['id'] === $selectedParentId ? ' selected' : '';
        echo '<option value="' . (int)$parentRow['id'] . '"' . $selected . '>' . catalog_h($parentRow['site_name'] . ' - ' . $parentRow['site_url']) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label><input type="checkbox" data-check-all="request-packages" checked> Check all requestable packages on this page</label> <button>Request selected files from parent</button></p>';
    echo '<table><tr><th>Select</th><th>Game</th><th>Missing package</th><th>Example missing object</th><th>Missing objects</th><th>Files that need it</th><th>Parent check</th></tr>';
    foreach ($items as $item) {
        $isBase = !empty($item['is_base_game']) || !empty($item['parent_is_base_game']);
        $baseBadge = $isBase ? ' <span class="pill amber">official base-game</span>' : '';
        $select = $isBase
            ? '<span class="muted">blocked</span>'
            : '<input type="checkbox" data-check-group="request-packages" name="item_keys[]" value="' . catalog_h($item['item_key']) . '" checked>';
        $parentCheck = $isBase
            ? 'Protected by parent; cannot transfer'
            : (!empty($item['parent_available']) ? 'Available on parent' : 'Not currently on parent');
        echo '<tr><td>' . $select . '</td><td>' . catalog_h($item['game_name']) . '<div class="muted small">' . catalog_h($item['engine_key']) . '</div></td><td class="mono">' . catalog_h($item['required_package']) . $baseBadge . '</td><td class="mono path">' . catalog_h($item['required_object_path']) . '</td><td>' . (int)$item['object_count'] . '</td><td>' . (int)$item['use_count'] . '</td><td>' . catalog_h($parentCheck) . '</td></tr>';
    }
    echo '</table><p><button>Request selected files from parent</button></p></form>';
    reqgen_pagination($page, $pages, $selectedParentId, $showBaseGame);
    echo '</div>';

    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(master){master.addEventListener("change",function(){var group=master.getAttribute("data-check-all");document.querySelectorAll("[data-check-group=\""+group+"\"]").forEach(function(box){if(!box.disabled){box.checked=master.checked;}});});});})();</script>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Missing files request error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
