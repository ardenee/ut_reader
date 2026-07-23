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

function reqgen_base_game_join(): string
{
    return ' LEFT JOIN ue_base_game_files bg
             ON bg.game_id=f.game_id
            AND bg.package_name IS NOT NULL
            AND bg.package_name<>""
            AND bg.package_name=d.required_package ';
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
    }
    unset($row);
    return $rows;
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

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    $selectedParentId = (int)($_REQUEST['peer_id'] ?? ($parents[0]['id'] ?? 0));
    $showBaseGame = reqgen_show_base_game($_REQUEST['show_base_game'] ?? '0');
    $page = reqgen_page($_REQUEST['page'] ?? 1);
    $totalItems = reqgen_total($db, $showBaseGame);
    $baseGameTotal = reqgen_base_game_total($db);
    $pages = max(1, (int)ceil($totalItems / REQGEN_PAGE_SIZE));
    $page = min($page, $pages);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_reqgen');
        $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$selectedParentId]);
        if (!$parent) {
            throw new RuntimeException('Active parent peer not found.');
        }
        $storedSecret = federation_peer_stored_signing_secret($db, $parent);

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
        $available = [];
        foreach ($pageItems as $item) {
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
            throw new RuntimeException('The selected packages are no longer missing on this page. Refresh and try again.');
        }

        $siteLabel = fed_setting($db, 'site_name', '') ?: fed_setting($db, 'site_url', '') ?: fed_setting($db, 'site_id', 'child');
        $payload = [
            'title' => 'Missing dependency request from ' . $siteLabel,
            'notes' => 'Selected ' . count($items) . ' missing package(s) from local verified dependency data.',
            'generated_at' => date('c'),
            'items' => $items,
        ];

        $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-submit.php';
        $result = fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), $storedSecret, $payload);
        fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'REQUEST_SUBMIT_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
        $_SESSION['fed_reqgen_result'] = $result + ['selected_packages' => count($items)];
        header('Location: ' . reqgen_url($page, $selectedParentId, $showBaseGame));
        exit;
    }

    if (!catalog_require_admin_page('Generate Missing Dependency Request')) {
        exit;
    }

    catalog_head('Generate Missing Dependency Request');
    catalog_page_header(
        'Generate Missing Dependency Request',
        'Child-side tool. Select missing packages to request from the parent. Official base-game packages are hidden by default because federation cannot transfer them. Each page is capped at 950 selections for standard PHP max_input_vars limits.',
        catalog_federation_links() + ['Request Status' => 'request-status.php', 'Approved Downloads' => 'approved-downloads.php']
    );

    if (isset($_SESSION['fed_reqgen_result'])) {
        echo '<div class="card"><h2>Last submit result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_reqgen_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_reqgen_result']);
    }

    $items = reqgen_items_page($db, $page, $showBaseGame);
    echo '<div class="card"><h2>Missing package request</h2>';
    echo '<form method="get" action="request-generate.php" class="filter-bar">';
    echo '<input type="hidden" name="page" value="1">';
    echo '<input type="hidden" name="peer_id" value="' . $selectedParentId . '">';
    echo '<label><input type="checkbox" name="show_base_game" value="1"' . ($showBaseGame ? ' checked' : '') . '> Show official base-game packages</label> ';
    echo '<button>Apply</button></form>';
    echo '<p>Visible missing packages: <strong>' . $totalItems . '</strong>. Official base-game packages: <strong>' . $baseGameTotal . '</strong>' . ($showBaseGame ? ' shown.' : ' hidden.') . ' Showing ' . count($items) . ' on this page.</p>';

    if (!$parents) {
        echo '<p class="muted">No active parent peer is configured.</p></div>';
        catalog_foot();
        exit;
    }
    if (!$items) {
        echo '<p class="muted">No matching missing dependency packages were found with the current filter.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_reqgen')) . '">';
    echo '<input type="hidden" name="page" value="' . $page . '">';
    echo '<input type="hidden" name="show_base_game" value="' . ($showBaseGame ? '1' : '0') . '">';
    echo '<p><label>Parent<br><select name="peer_id">';
    foreach ($parents as $parent) {
        $selected = (int)$parent['id'] === $selectedParentId ? ' selected' : '';
        echo '<option value="' . (int)$parent['id'] . '"' . $selected . '>' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label><input type="checkbox" data-check-all="request-packages" checked> Check all on this page</label> <button>Submit selected packages to parent</button></p>';
    echo '<table><tr><th>Select</th><th>Game</th><th>Required package</th><th>Example required object</th><th>Objects missing</th><th>Needed by files</th></tr>';
    foreach ($items as $item) {
        $baseBadge = !empty($item['is_base_game']) ? ' <span class="pill amber">official base-game</span>' : '';
        echo '<tr><td><input type="checkbox" data-check-group="request-packages" name="item_keys[]" value="' . catalog_h($item['item_key']) . '" checked></td><td>' . catalog_h($item['game_name']) . '<div class="muted small">' . catalog_h($item['engine_key']) . '</div></td><td class="mono">' . catalog_h($item['required_package']) . $baseBadge . '</td><td class="mono path">' . catalog_h($item['required_object_path']) . '</td><td>' . (int)$item['object_count'] . '</td><td>' . (int)$item['use_count'] . '</td></tr>';
    }
    echo '</table><p><button>Submit selected packages to parent</button></p></form>';
    reqgen_pagination($page, $pages, $selectedParentId, $showBaseGame);
    echo '</div>';

    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(master){master.addEventListener("change",function(){var group=master.getAttribute("data-check-all");document.querySelectorAll("[data-check-group=\""+group+"\"]").forEach(function(box){box.checked=master.checked;});});});})();</script>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Request generate error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
