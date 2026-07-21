<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

catalog_start_session();

function pak_info_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function pak_info_url(array $params): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'pak-info.php?' . http_build_query($query);
}

function pak_info_compression_label(int $method): string
{
    return match ($method) {
        0 => 'none',
        1 => 'zlib',
        default => 'method ' . $method,
    };
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        throw new RuntimeException('PAK archive tables are missing. Run php catalog/bin/migrate.php migrate.');
    }

    $pakId = pak_info_int('id', 0, 1, PHP_INT_MAX);
    $pak = catalog_one(
        $db,
        'SELECT p.*,g.name game_name,g.slug game_slug,pr.engine_key profile_engine,u.username uploaded_by_name '
        . 'FROM ue_pak_archives p JOIN ue_games g ON g.id=p.game_id '
        . 'LEFT JOIN ue_game_profiles pr ON pr.id=g.profile_id '
        . 'LEFT JOIN ue_users u ON u.id=p.uploaded_by WHERE p.id=?',
        [$pakId]
    );
    if (!$pak) {
        throw new RuntimeException('PAK archive not found.');
    }

    $filter = trim((string)($_GET['entry_filter'] ?? ''));
    $status = trim((string)($_GET['entry_status'] ?? ''));
    $page = pak_info_int('entry_page', 1, 1, PHP_INT_MAX);
    $limit = 200;
    $where = 'WHERE e.pak_id=?';
    $args = [$pakId];
    if ($filter !== '') {
        $where .= ' AND (e.entry_path LIKE ? OR e.entry_name LIKE ? OR f.package_name LIKE ? OR f.original_name LIKE ?)';
        $like = '%' . $filter . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if ($status !== '') {
        $where .= ' AND e.import_status=?';
        $args[] = $status;
    }

    $total = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ue_pak_entries e LEFT JOIN ue_files f ON f.id=e.file_id ' . $where,
        $args
    )['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;
    $entries = catalog_all(
        $db,
        'SELECT e.*,f.package_name,f.original_name,f.package_guid,f.md5 file_md5,f.scan_status '
        . 'FROM ue_pak_entries e LEFT JOIN ue_files f ON f.id=e.file_id ' . $where
        . ' ORDER BY e.entry_index LIMIT ' . $limit . ' OFFSET ' . $offset,
        $args
    );
    $statusRows = catalog_all(
        $db,
        'SELECT import_status,COUNT(*) c FROM ue_pak_entries WHERE pak_id=? GROUP BY import_status ORDER BY import_status',
        [$pakId]
    );

    catalog_head((string)$pak['original_name']);
    echo CatalogUi::pageHeader(
        (string)$pak['original_name'],
        (string)$pak['game_name'] . ' original self-contained PAK archive.',
        [
            'PAK archives' => 'game-paks.php?id=' . (int)$pak['game_id'],
            'Extracted files' => 'game-files.php?id=' . (int)$pak['game_id'],
            'Download original PAK' => 'pak-download.php?id=' . $pakId,
        ]
    );

    $tone = match ((string)$pak['status']) {
        'ready' => 'success',
        'failed' => 'danger',
        default => 'warning',
    };
    echo '<div class="grid">';
    catalog_stat_card('Archive status', (string)$pak['status'], '', $tone);
    catalog_stat_card('Index entries', number_format((int)$pak['entry_count']));
    catalog_stat_card('Extracted entries', number_format((int)$pak['extracted_count']));
    catalog_stat_card('Skipped/not extracted', number_format((int)$pak['skipped_count']));
    catalog_stat_card('Archive size', catalog_bytes((int)$pak['file_size']));
    echo '</div>';

    echo '<div class="card"><h2>Original PAK details</h2><table>';
    echo '<tr><th>Game</th><td><a href="game-paks.php?id=' . (int)$pak['game_id'] . '">' . catalog_h((string)$pak['game_name']) . '</a></td></tr>';
    echo '<tr><th>Original filename</th><td>' . catalog_h((string)$pak['original_name']) . '</td></tr>';
    echo '<tr><th>Mount point</th><td class="mono path">' . catalog_h((string)$pak['mount_point']) . '</td></tr>';
    echo '<tr><th>PAK version</th><td class="mono">' . (int)$pak['pak_version'] . '</td></tr>';
    echo '<tr><th>Footer layout</th><td class="mono">' . catalog_h((string)$pak['footer_layout']) . '</td></tr>';
    echo '<tr><th>Index offset / size</th><td class="mono">' . number_format((int)$pak['index_offset']) . ' / ' . number_format((int)$pak['index_size']) . '</td></tr>';
    echo '<tr><th>Index hash</th><td class="mono">' . catalog_h((string)$pak['index_hash']) . '</td></tr>';
    echo '<tr><th>MD5</th><td class="mono">' . catalog_h((string)$pak['md5']) . '</td></tr>';
    echo '<tr><th>SHA1</th><td class="mono">' . catalog_h((string)$pak['sha1']) . '</td></tr>';
    echo '<tr><th>SHA256</th><td class="mono">' . catalog_h((string)$pak['sha256']) . '</td></tr>';
    echo '<tr><th>Imported</th><td>' . catalog_h((string)$pak['created_at']) . ($pak['uploaded_by_name'] ? ' by ' . catalog_h((string)$pak['uploaded_by_name']) : '') . '</td></tr>';
    echo '</table><p><a class="button primary" href="pak-download.php?id=' . $pakId . '">Download original PAK</a></p>';
    if (catalog_support_is_admin()) {
        echo '<form method="post" action="pak-maintenance.php" onsubmit="return confirm(\'Delete this PAK archive record and the retained original .pak file? Extracted package files will remain in the catalog.\')">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('pak-maintenance')) . '"><input type="hidden" name="pak_id" value="' . $pakId . '"><input type="hidden" name="operation" value="delete">';
        echo '<button class="danger" type="submit">Delete retained PAK</button></form>';
    }
    echo '</div>';

    if (trim((string)$pak['scan_notes']) !== '') {
        echo '<div class="card"><h2>PAK extraction notes</h2><pre class="mono">' . catalog_h((string)$pak['scan_notes']) . '</pre></div>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Archive contents</h2><p>'
        . number_format($total) . ' matching entry or package reference(s).</p></div></div><div class="ui-section__body">';
    echo '<p class="muted">Every PAK index entry is listed. Standalone package entries link to the extracted catalog file; companion payloads and unsupported entries remain visible without being mixed into the normal game-file list.</p>';
    if ($statusRows !== []) {
        echo '<p>';
        foreach ($statusRows as $row) {
            echo CatalogUi::badge((string)$row['import_status'] . ': ' . number_format((int)$row['c']), 'neutral') . ' ';
        }
        echo '</p>';
    }

    echo '<form class="table-controls" method="get"><input type="hidden" name="id" value="' . $pakId . '">';
    echo '<label>Search contents <input type="search" name="entry_filter" value="' . catalog_h($filter) . '" placeholder="Entry path or package"></label> ';
    echo '<label>Status <select name="entry_status"><option value="">All</option>';
    foreach ($statusRows as $row) {
        $value = (string)$row['import_status'];
        echo '<option value="' . catalog_h($value) . '"' . ($status === $value ? ' selected' : '') . '>' . catalog_h($value) . '</option>';
    }
    echo '</select></label> <button type="submit">Apply</button>';
    if ($filter !== '' || $status !== '') {
        echo ' <a class="button" href="pak-info.php?id=' . $pakId . '">Clear</a>';
    }
    echo '</form>';

    if ($entries === []) {
        echo CatalogUi::emptyState('No entries found', 'No PAK entries match the selected filters.', ['label' => 'Clear filters', 'href' => 'pak-info.php?id=' . $pakId], '⌕');
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>#</th><th>Entry path</th><th>Package link</th><th>Import status</th><th>Compression</th><th>Stored / unpacked</th><th>Entry identity</th><th>Message</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            $package = '<span class="muted">not a catalog package</span>';
            if ((int)($entry['file_id'] ?? 0) > 0) {
                $package = '<a href="file-info.php?id=' . (int)$entry['file_id'] . '"><strong>' . catalog_h((string)($entry['package_name'] ?: $entry['original_name'])) . '</strong></a>'
                    . '<br><a class="small" href="file-examine.php?id=' . (int)$entry['file_id'] . '">examine extracted package</a>';
            }
            $entryTone = match ((string)$entry['import_status']) {
                'verified', 'imported', 'duplicate', 'alias' => 'success',
                'unverified', 'rejected', 'failed', 'encrypted', 'not_extracted' => 'danger',
                default => 'neutral',
            };
            echo '<tr>';
            echo '<td class="mono">' . (int)$entry['entry_index'] . '</td>';
            echo '<td class="mono path">' . catalog_h((string)$entry['entry_path']) . '</td>';
            echo '<td>' . $package . '</td>';
            echo '<td>' . CatalogUi::badge((string)$entry['import_status'], $entryTone) . '</td>';
            echo '<td>' . catalog_h(pak_info_compression_label((int)$entry['compression_method']))
                . (!empty($entry['is_encrypted']) ? '<br>' . CatalogUi::badge('encrypted', 'danger') : '') . '</td>';
            echo '<td class="nowrap">' . catalog_h(catalog_bytes((int)$entry['stored_size'])) . '<br><span class="small muted">' . catalog_h(catalog_bytes((int)$entry['uncompressed_size'])) . '</span></td>';
            echo '<td><span class="mono small">SHA1 ' . catalog_h((string)$entry['entry_hash']) . '</span>'
                . ((int)($entry['file_id'] ?? 0) > 0 ? '<br><span class="mono small">MD5 ' . catalog_h((string)$entry['file_md5']) . '</span>' : '') . '</td>';
            echo '<td class="small">' . catalog_h((string)$entry['import_message']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($pages > 1) {
            echo '<nav class="pagination">';
            if ($page > 1) {
                echo '<a class="button" href="' . catalog_h(pak_info_url(['entry_page' => $page - 1])) . '">Previous</a> ';
            }
            echo '<span>Page ' . $page . ' of ' . $pages . '</span>';
            if ($page < $pages) {
                echo ' <a class="button" href="' . catalog_h(pak_info_url(['entry_page' => $page + 1])) . '">Next</a>';
            }
            echo '</nav>';
        }
    }
    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('PAK information error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'PAK information could not be loaded.');
    catalog_foot();
}
