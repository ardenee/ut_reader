<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for game upks.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogUpkPackage.php';

catalog_start_session();

function game_upks_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function game_upks_url(array $params): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'game-upks.php?' . http_build_query($query);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $gameId = game_upks_int('id', 0, 1, PHP_INT_MAX);
    $game = catalog_one(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key profile_engine FROM ue_games g '
        . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
        [$gameId]
    );
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    catalog_head((string)$game['name'] . ' UPK packages');
    if (!catalog_upk_supported_engine((string)($game['profile_engine'] ?? ''))) {
        echo CatalogUi::pageHeader(
            (string)$game['name'],
            'UPK package containers are managed for UE3 game profiles.',
            ['Files' => 'game-files.php?id=' . $gameId, 'Back to games' => 'games.php']
        );
        echo CatalogUi::emptyState(
            'No UPK package view',
            'This game is not assigned to a UE3 profile.',
            ['label' => 'View files', 'href' => 'game-files.php?id=' . $gameId],
            '▤'
        );
        catalog_foot();
        exit;
    }

    $filter = trim((string)($_GET['upk_filter'] ?? ''));
    $page = game_upks_int('upk_page', 1, 1, PHP_INT_MAX);
    $limit = 100;
    $where = 'WHERE f.game_id=? AND f.scan_status="verified" AND LOWER(f.extension)="upk"';
    $args = [$gameId];
    if ($filter !== '') {
        $where .= ' AND (f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?)';
        $like = '%' . $filter . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }

    $total = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_files f ' . $where, $args)['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;
    $rows = catalog_all(
        $db,
        'SELECT f.*,'
        . '(SELECT COALESCE(SUM(e.serial_size),0) FROM ue_exports e WHERE e.file_id=f.id) serialized_export_bytes '
        . 'FROM ue_files f ' . $where
        . ' ORDER BY f.package_name,f.original_name,f.id LIMIT ' . $limit . ' OFFSET ' . $offset,
        $args
    );
    $isAdmin = catalog_support_is_admin();
    $csrf = $isAdmin ? catalog_csrf('catalog-maintenance') : '';

    echo CatalogUi::pageHeader(
        (string)$game['name'],
        'Switch between ordinary game files and original UE3 UPK package containers. UPKs are not mixed into the normal file list.',
        ['Files' => 'game-files.php?id=' . $gameId, 'UPK packages' => 'game-upks.php?id=' . $gameId, 'Back to games' => 'games.php']
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>UPK packages</h2><p>'
        . number_format($total) . ' original UE3 package container(s).</p></div></div><div class="ui-section__body">';
    echo '<p class="muted">A UE3 UPK contains serialized UObject exports rather than independent child package files. UnrealDB keeps the original UPK downloadable and exposes every parsed export as its internal contents.</p>';
    echo '<form class="table-controls" method="get"><input type="hidden" name="id" value="' . $gameId . '">';
    echo '<label>Search <input type="search" name="upk_filter" value="' . catalog_h($filter) . '" placeholder="Package, file, GUID or hash"></label> ';
    echo '<button type="submit">Apply</button>';
    if ($filter !== '') {
        echo ' <a class="button" href="game-upks.php?id=' . $gameId . '">Clear</a>';
    }
    echo '</form>';

    if ($rows === []) {
        echo CatalogUi::emptyState(
            'No UPK packages found',
            $filter !== '' ? 'No UPK packages match the selected filter.' : 'Upload or scan UE3 .upk files for this game.',
            $filter !== ''
                ? ['label' => 'Clear filter', 'href' => 'game-upks.php?id=' . $gameId]
                : (catalog_support_is_admin() ? ['label' => 'Upload files', 'href' => 'profiled-upload.php?game_id=' . $gameId] : null),
            '▤'
        );
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>UPK package</th><th>Package identity</th><th>Version</th><th title="Names / Imports / Exports">N/I/E</th><th>Size</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $upk) {
            $id = (int)$upk['id'];
            $version = (int)$upk['package_version'] . ((int)$upk['licensee_version'] > 0 ? ' / ' . (int)$upk['licensee_version'] : '');
            $database = number_format((int)($upk['name_count'] ?? 0))
                . ' / ' . number_format((int)($upk['import_count'] ?? 0))
                . ' / ' . number_format((int)($upk['export_count'] ?? 0));

            echo '<tr data-file-id="' . $id . '">';
            echo '<td><a href="upk-info.php?id=' . $id . '"><strong>' . catalog_h((string)$upk['original_name']) . '</strong></a>'
                . '<br><a class="mono small" href="file-examine.php?id=' . $id . '">' . catalog_h((string)$upk['package_name']) . '</a></td>';
            echo '<td class="catalog-identity-cell">' . CatalogUi::identity(
                (string)($upk['package_guid'] ?? ''),
                (string)($upk['md5'] ?? ''),
                (string)($upk['sha1'] ?? '')
            ) . '</td>';
            echo '<td class="mono">' . catalog_h($version) . ((int)$upk['is_compressed'] === 1 ? '<br>' . CatalogUi::badge('compressed chunks', 'warning') : '') . '</td>';
            echo '<td class="nowrap" title="Names / Imports / Exports"><span class="mono">' . catalog_h($database) . '</span>'
                . '<br><span class="small muted">' . catalog_h(catalog_bytes((int)$upk['serialized_export_bytes'])) . ' serialized payload</span></td>';
            echo '<td class="nowrap">' . catalog_h(catalog_bytes((int)$upk['file_size'])) . '</td>';
            echo '<td class="nowrap"><a class="button" href="download.php?id=' . $id . '">Download UPK</a>';
            if ($isAdmin) {
                $confirm = 'Delete ' . (string)$upk['original_name'] . ' from storage and the catalog? Its indexed names, imports, exports and dependencies will also be deleted.';
                echo '<form method="post" action="file-maintenance.php" style="display:inline" onsubmit="return confirm(\'' . catalog_h($confirm) . '\')">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h($csrf) . '">'
                    . '<input type="hidden" name="file_id" value="' . $id . '">'
                    . '<input type="hidden" name="operation" value="remove">'
                    . '<button class="danger" type="submit">Delete</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';

        if ($pages > 1) {
            echo '<nav class="pagination">';
            if ($page > 1) {
                echo '<a class="button" href="' . catalog_h(game_upks_url(['upk_page' => $page - 1])) . '">Previous</a> ';
            }
            echo '<span>Page ' . $page . ' of ' . $pages . '</span>';
            if ($page < $pages) {
                echo ' <a class="button" href="' . catalog_h(game_upks_url(['upk_page' => $page + 1])) . '">Next</a>';
            }
            echo '</nav>';
        }
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('UPK package error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'UPK package list could not be loaded.');
    catalog_foot();
}
