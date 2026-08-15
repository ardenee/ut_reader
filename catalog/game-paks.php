<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for game paks.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

catalog_start_session();

function game_paks_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function game_paks_engine_major(string $engineKey): int
{
    return preg_match('/UE\s*([0-9]+)/i', $engineKey, $match) === 1 ? (int)$match[1] : 0;
}

function game_paks_url(array $params): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'game-paks.php?' . http_build_query($query);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $gameId = game_paks_int('id', 0, 0, PHP_INT_MAX);
    if ($gameId < 1) {
        header('Location: paks.php');
        exit;
    }
    $game = catalog_one(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key profile_engine FROM ue_games g '
        . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
        [$gameId]
    );
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }

    catalog_head((string)$game['name'] . ' PAK archives');
    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        echo CatalogUi::pageHeader(
            (string)$game['name'],
            'PAK archives are stored separately from extracted package files.',
            ['Files' => 'game-files.php?id=' . $gameId, 'Back to games' => 'games.php']
        );
        echo CatalogUi::alert('warning', 'PAK archive management is not installed. Run php catalog/bin/migrate.php migrate.');
        catalog_foot();
        exit;
    }

    $engineMajor = game_paks_engine_major((string)($game['profile_engine'] ?? ''));
    if (!in_array($engineMajor, [4, 5], true)) {
        echo CatalogUi::pageHeader(
            (string)$game['name'],
            'PAK archives are managed for UE4 and UE5 game profiles.',
            ['Files' => 'game-files.php?id=' . $gameId, 'Back to games' => 'games.php']
        );
        echo CatalogUi::emptyState('No PAK archive view', 'This game is not assigned to a UE4 or UE5 profile.', ['label' => 'View files', 'href' => 'game-files.php?id=' . $gameId], '▣');
        catalog_foot();
        exit;
    }

    $filter = trim((string)($_GET['pak_filter'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $page = game_paks_int('pak_page', 1, 1, PHP_INT_MAX);
    $limit = 100;
    $where = 'WHERE p.game_id=?';
    $args = [$gameId];
    if ($filter !== '') {
        $where .= ' AND (p.original_name LIKE ? OR p.md5 LIKE ? OR p.sha1 LIKE ? OR p.sha256 LIKE ? OR p.mount_point LIKE ?)';
        $like = '%' . $filter . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if (in_array($status, ['processing', 'ready', 'failed'], true)) {
        $where .= ' AND p.status=?';
        $args[] = $status;
    }

    $total = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_pak_archives p ' . $where, $args)['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;
    $rows = catalog_all(
        $db,
        'SELECT p.*, '
        . '(SELECT COUNT(*) FROM ue_pak_entries e WHERE e.pak_id=p.id AND e.file_id IS NOT NULL) linked_packages '
        . 'FROM ue_pak_archives p ' . $where
        . ' ORDER BY p.created_at DESC,p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        $args
    );

    echo CatalogUi::pageHeader(
        (string)$game['name'],
        'Switch between extracted package files and their original self-contained UE' . $engineMajor . ' PAK archives.',
        [
            'Files' => 'game-files.php?id=' . $gameId,
            'PAK archives' => 'game-paks.php?id=' . $gameId,
            'Import PAK' => 'pak-import.php?game_id=' . $gameId,
            'Back to games' => 'games.php',
        ]
    );

    if (isset($_SESSION['pak_maintenance_flash'])) {
        catalog_flash((string)$_SESSION['pak_maintenance_flash']);
        unset($_SESSION['pak_maintenance_flash']);
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>PAK archives</h2><p>'
        . number_format($total) . ' original archive(s). PAKs are not mixed into the extracted file list.</p></div></div><div class="ui-section__body">';
    echo '<form class="table-controls" method="get"><input type="hidden" name="id" value="' . $gameId . '">';
    echo '<label>Search <input type="search" name="pak_filter" value="' . catalog_h($filter) . '" placeholder="Name, hash or mount point"></label> ';
    echo '<label>Status <select name="status">';
    foreach (['' => 'All', 'ready' => 'Ready', 'processing' => 'Processing', 'failed' => 'Failed'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($status === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label> <button type="submit">Apply</button>';
    if ($filter !== '' || $status !== '') {
        echo ' <a class="button" href="game-paks.php?id=' . $gameId . '">Clear</a>';
    }
    echo '</form>';

    if ($rows === []) {
        echo CatalogUi::emptyState(
            'No PAK archives found',
            'Import a UE4 or UE5 .pak file to retain the original archive and catalog its readable contents.',
            catalog_support_is_admin() ? ['label' => 'Import PAK', 'href' => 'pak-import.php?game_id=' . $gameId] : null,
            '▣'
        );
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>PAK archive</th><th>Status</th><th>PAK details</th><th>Contents</th><th>Identity</th><th class="nowrap">Size</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $pak) {
            $tone = match ((string)$pak['status']) {
                'ready' => 'success',
                'failed' => 'danger',
                default => 'warning',
            };
            echo '<tr>';
            echo '<td><a href="pak-info.php?id=' . (int)$pak['id'] . '"><strong>' . catalog_h((string)$pak['original_name']) . '</strong></a><br><span class="mono small">' . catalog_h((string)$pak['mount_point']) . '</span></td>';
            echo '<td>' . CatalogUi::badge((string)$pak['status'], $tone) . '</td>';
            echo '<td class="mono">version ' . (int)$pak['pak_version'] . '<br><span class="small">' . catalog_h((string)$pak['footer_layout']) . '</span></td>';
            echo '<td><span class="mono" title="Entries">' . number_format((int)$pak['entry_count']) . '</span><br><span class="mono small muted" title="Linked packages">' . number_format((int)$pak['linked_packages']) . '</span></td>';
            echo '<td><span class="mono small">MD5 ' . catalog_h((string)$pak['md5']) . '</span><br><span class="mono small">SHA256 ' . catalog_h((string)$pak['sha256']) . '</span></td>';
            echo '<td class="nowrap">' . catalog_h(catalog_bytes((int)$pak['file_size'])) . '</td>';
            echo '<td>' . CatalogUi::iconButton([
                'label' => 'Download ' . (string)$pak['original_name'],
                'icon' => '⇩',
                'href' => 'pak-download.php?id=' . (int)$pak['id'],
                'size' => 'sm',
            ]) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($pages > 1) {
            echo '<nav class="pagination">';
            if ($page > 1) {
                echo '<a class="button" href="' . catalog_h(game_paks_url(['pak_page' => $page - 1])) . '">Previous</a> ';
            }
            echo '<span>Page ' . $page . ' of ' . $pages . '</span>';
            if ($page < $pages) {
                echo ' <a class="button" href="' . catalog_h(game_paks_url(['pak_page' => $page + 1])) . '">Next</a>';
            }
            echo '</nav>';
        }
    }
    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('PAK archive error');
    }
    echo CatalogUi::alert('danger', catalog_exception_display_message($error), 'PAK archive list could not be loaded.');
    catalog_foot();
}
