<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for PAK info.
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
        'SELECT e.*,f.package_name,f.original_name,f.package_guid,f.md5 file_md5,f.sha1 file_sha1,f.scan_status,'
        . 'f.name_count,f.import_count,f.export_count '
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
    echo <<<'CSS'
<style>
.pak-info-natural-table {
    width: 100%;
    min-width: 0 !important;
    table-layout: auto;
}

.pak-info-natural-table th,
.pak-info-natural-table td {
    min-width: 0;
    max-width: none;
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-break: normal;
}

.pak-info-natural-table .path,
.pak-info-natural-table .mono {
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-break: normal;
}

.pak-info-natural-table .pak-info-nowrap,
.pak-info-natural-table .pak-info-nowrap * {
    white-space: nowrap !important;
    overflow-wrap: normal;
    word-break: normal;
}

.pak-info-table-region > table {
    min-width: 0 !important;
}

.pak-info-notes {
    max-width: 100%;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}
</style>
CSS;
    echo CatalogUi::pageHeader(
        (string)$pak['original_name'],
        (string)$pak['game_name'] . ' original self-contained PAK archive.',
        [
            'PAK archives' => 'game-paks.php?id=' . (int)$pak['game_id'],
            'Extracted files' => 'game-files.php?id=' . (int)$pak['game_id'],
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

    echo '<div class="card"><h2>Original PAK details</h2><table class="pak-info-natural-table">';
    echo '<tr><th>Game</th><td><a href="game-paks.php?id=' . (int)$pak['game_id'] . '">' . catalog_h((string)$pak['game_name']) . '</a></td></tr>';
    echo '<tr><th>Original filename</th><td>' . catalog_h((string)$pak['original_name']) . '</td></tr>';
    echo '<tr><th>Mount point</th><td class="mono path">' . catalog_h((string)$pak['mount_point']) . '</td></tr>';
    echo '<tr><th>PAK version</th><td class="mono">' . (int)$pak['pak_version'] . '</td></tr>';
    echo '<tr><th>Footer layout</th><td class="mono">' . catalog_h((string)$pak['footer_layout']) . '</td></tr>';
    echo '<tr><th>Index offset / size</th><td class="mono">' . number_format((int)$pak['index_offset']) . ' / ' . number_format((int)$pak['index_size']) . '</td></tr>';
    echo '<tr><th>Index hash</th><td class="mono">' . catalog_h((string)$pak['index_hash']) . '</td></tr>';
    echo '<tr><th>Identity</th><td class="pak-info-nowrap">' . CatalogUi::identity('', (string)$pak['md5'], (string)$pak['sha256']) . '</td></tr>';
    echo '<tr><th>Imported</th><td>' . catalog_h((string)$pak['created_at']) . ($pak['uploaded_by_name'] ? ' by ' . catalog_h((string)$pak['uploaded_by_name']) : '') . '</td></tr>';
    echo '</table><div class="ui-inline-actions">';
    echo CatalogUi::iconButton([
        'label' => 'Download original PAK',
        'icon' => '⇩',
        'href' => 'pak-download.php?id=' . $pakId,
        'size' => 'sm',
        'variant' => 'primary',
    ]);
    if (catalog_support_is_admin()) {
        echo '<form method="post" action="pak-maintenance.php" onsubmit="return confirm(\'Delete this PAK archive record and the retained original .pak file? Extracted package files will remain in the catalog.\')">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('pak-maintenance')) . '"><input type="hidden" name="pak_id" value="' . $pakId . '"><input type="hidden" name="operation" value="delete">';
        echo CatalogUi::button('Delete retained PAK', ['type' => 'submit', 'variant' => 'danger', 'size' => 'sm']);
        echo '</form>';
    }
    echo '</div></div>';

    if (trim((string)$pak['scan_notes']) !== '') {
        echo '<div class="card"><h2>PAK extraction notes</h2><pre class="mono pak-info-notes">' . catalog_h((string)$pak['scan_notes']) . '</pre></div>';
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
        echo '<div class="ui-table-region pak-info-table-region"><table class="pak-info-natural-table"><thead><tr><th>#</th><th>Entry path</th><th>Package link</th><th class="pak-info-nowrap">Import status</th><th>Compression</th><th>Stored / unpacked</th><th class="pak-info-nowrap">Identity</th><th>Database</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            $fileId = (int)($entry['file_id'] ?? 0);
            $entryPath = catalog_h((string)$entry['entry_path']);
            if ($fileId > 0) {
                $entryPath = '<a href="file-examine.php?id=' . $fileId . '">' . $entryPath . '</a>';
            }

            $package = '<span class="muted">not a catalog package</span>';
            if ($fileId > 0) {
                $package = '<a href="file-info.php?id=' . $fileId . '"><strong>'
                    . catalog_h((string)($entry['package_name'] ?: $entry['original_name']))
                    . '</strong></a>';
            }

            $entryTone = match ((string)$entry['import_status']) {
                'verified', 'imported', 'duplicate', 'alias' => 'success',
                'unverified', 'rejected', 'failed', 'encrypted', 'not_extracted' => 'danger',
                default => 'neutral',
            };

            $database = $fileId > 0
                ? number_format((int)($entry['name_count'] ?? 0))
                    . ' / ' . number_format((int)($entry['import_count'] ?? 0))
                    . ' / ' . number_format((int)($entry['export_count'] ?? 0))
                : '—';
            $sha = $fileId > 0
                ? (string)($entry['file_sha1'] ?? '')
                : (string)($entry['entry_hash'] ?? '');

            echo '<tr data-file-id="' . $fileId . '">';
            echo '<td class="mono">' . (int)$entry['entry_index'] . '</td>';
            echo '<td class="mono path">' . $entryPath . '</td>';
            echo '<td>' . $package . '</td>';
            echo '<td class="pak-info-nowrap">' . CatalogUi::badge((string)$entry['import_status'], $entryTone) . '</td>';
            echo '<td>' . catalog_h(pak_info_compression_label((int)$entry['compression_method']))
                . (!empty($entry['is_encrypted']) ? '<br>' . CatalogUi::badge('encrypted', 'danger') : '') . '</td>';
            echo '<td>' . catalog_h(catalog_bytes((int)$entry['stored_size'])) . '<br><span class="small muted">' . catalog_h(catalog_bytes((int)$entry['uncompressed_size'])) . '</span></td>';
            echo '<td class="pak-info-nowrap catalog-identity-cell">' . CatalogUi::identity(
                (string)($entry['package_guid'] ?? ''),
                (string)($entry['file_md5'] ?? ''),
                $sha
            ) . '</td>';
            echo '<td class="mono pak-info-nowrap" title="Names / Imports / Exports">' . catalog_h($database) . '</td>';
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
