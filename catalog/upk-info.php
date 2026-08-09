<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for UPK info.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogUpkPackage.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;

catalog_start_session();

function upk_info_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function upk_info_url(array $params): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'upk-info.php?' . http_build_query($query);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = upk_info_int('id', 0, 1, PHP_INT_MAX);
    $upk = catalog_one(
        $db,
        'SELECT f.*,g.name game_name,g.slug game_slug,p.engine_key profile_engine,u.username uploaded_by_name '
        . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
        . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'LEFT JOIN ue_users u ON u.id=f.uploaded_by '
        . 'WHERE f.id=? AND f.scan_status="verified" AND LOWER(f.extension)="upk"',
        [$fileId]
    );
    if (!$upk) {
        throw new RuntimeException('UPK package not found.');
    }
    if (!catalog_upk_supported_engine((string)($upk['profile_engine'] ?? ''))) {
        throw new RuntimeException('The selected UPK is not assigned to a UE3 game profile.');
    }

    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('Catalog storage_path is required for compact UPK metadata reading.');
    }
    $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($fileId);
    $allExports = array_values((array)($snapshot['exports'] ?? []));
    usort(
        $allExports,
        static fn(array $left, array $right): int => (int)($left['export_index'] ?? 0) <=> (int)($right['export_index'] ?? 0)
    );

    $classCounts = [];
    $serialBytes = 0;
    $firstOffset = null;
    $lastEnd = 0;
    foreach ($allExports as $export) {
        $className = trim((string)($export['class_name'] ?? ''));
        $className = $className !== '' ? $className : 'unknown';
        $classCounts[$className] = ($classCounts[$className] ?? 0) + 1;

        $serialOffset = max(0, (int)($export['serial_offset'] ?? 0));
        $serialSize = max(0, (int)($export['serial_size'] ?? 0));
        $serialBytes += $serialSize;
        $firstOffset = $firstOffset === null ? $serialOffset : min($firstOffset, $serialOffset);
        $lastEnd = max($lastEnd, $serialOffset + $serialSize);
    }
    $classes = [];
    foreach ($classCounts as $className => $count) {
        $classes[] = ['class_name' => $className, 'c' => $count];
    }
    usort(
        $classes,
        static fn(array $left, array $right): int => ((int)$right['c'] <=> (int)$left['c'])
            ?: strcasecmp((string)$left['class_name'], (string)$right['class_name'])
    );
    $classes = array_slice($classes, 0, 500);

    $payload = [
        'export_count' => count($allExports),
        'serial_bytes' => $serialBytes,
        'first_offset' => $firstOffset ?? 0,
        'last_end' => $lastEnd,
    ];

    $filter = trim((string)($_GET['export_filter'] ?? ''));
    $classFilter = trim((string)($_GET['class_name'] ?? ''));
    $filteredExports = array_values(array_filter(
        $allExports,
        static function (array $export) use ($filter, $classFilter): bool {
            $className = trim((string)($export['class_name'] ?? ''));
            if ($classFilter === 'unknown' && $className !== '') {
                return false;
            }
            if ($classFilter !== '' && $classFilter !== 'unknown' && $className !== $classFilter) {
                return false;
            }
            if ($filter === '') {
                return true;
            }
            foreach (['object_name', 'class_name', 'local_path', 'full_path'] as $field) {
                if (stripos((string)($export[$field] ?? ''), $filter) !== false) {
                    return true;
                }
            }
            return false;
        }
    ));

    $page = upk_info_int('export_page', 1, 1, PHP_INT_MAX);
    $limit = 200;
    $total = count($filteredExports);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;
    $exports = array_slice($filteredExports, $offset, $limit);
    $isAdmin = catalog_support_is_admin();

    catalog_head((string)$upk['original_name']);
    echo CatalogUi::pageHeader(
        (string)$upk['original_name'],
        (string)$upk['game_name'] . ' original UE3 UPK package container and its parsed internal exports.',
        [
            'UPK packages' => 'game-upks.php?id=' . (int)$upk['game_id'],
            'Other files' => 'game-files.php?id=' . (int)$upk['game_id'],
            'Examine full package' => 'file-examine.php?id=' . $fileId,
            'Download original UPK' => 'download.php?id=' . $fileId,
        ]
    );

    echo '<div class="grid">';
    catalog_stat_card('Internal exports', number_format((int)($payload['export_count'] ?? 0)));
    catalog_stat_card('Names', number_format((int)$upk['name_count']));
    catalog_stat_card('Imports', number_format((int)$upk['import_count']));
    catalog_stat_card('Serialized export data', catalog_bytes((int)($payload['serial_bytes'] ?? 0)));
    catalog_stat_card('UPK size', catalog_bytes((int)$upk['file_size']));
    echo '</div>';

    echo '<div class="card"><h2>Original UPK details</h2><table>';
    echo '<tr><th>Game</th><td><a href="game-upks.php?id=' . (int)$upk['game_id'] . '">' . catalog_h((string)$upk['game_name']) . '</a></td></tr>';
    echo '<tr><th>Package name</th><td class="mono">' . catalog_h((string)$upk['package_name']) . '</td></tr>';
    echo '<tr><th>Original filename</th><td>' . catalog_h((string)$upk['original_name']) . '</td></tr>';
    echo '<tr><th>Recorded source path</th><td class="mono path">' . catalog_h((string)$upk['source_relative_path']) . '</td></tr>';
    echo '<tr><th>Version / licencee</th><td class="mono">' . (int)$upk['package_version'] . ' / ' . (int)$upk['licensee_version'] . '</td></tr>';
    echo '<tr><th>Identity</th><td class="catalog-identity-cell">' . CatalogUi::identity(
        (string)$upk['package_guid'],
        (string)$upk['md5'],
        (string)$upk['sha1']
    ) . '</td></tr>';
    echo '<tr><th>Compression</th><td>' . ((int)$upk['is_compressed'] === 1 ? CatalogUi::badge('compressed chunks', 'warning') : CatalogUi::badge('none', 'success'))
        . ' <span class="mono small">flags 0x' . strtoupper(str_pad(dechex((int)$upk['compression_flags']), 8, '0', STR_PAD_LEFT)) . '</span></td></tr>';
    echo '<tr><th>Serialized export range</th><td class="mono">' . number_format((int)($payload['first_offset'] ?? 0)) . ' - ' . number_format((int)($payload['last_end'] ?? 0)) . '</td></tr>';
    echo '<tr><th>Imported</th><td>' . catalog_h((string)$upk['uploaded_at']) . ($upk['uploaded_by_name'] ? ' by ' . catalog_h((string)$upk['uploaded_by_name']) : '') . '</td></tr>';
    echo '</table><p><a class="button primary" href="download.php?id=' . $fileId . '">Download original UPK</a> '
        . '<a class="button" href="file-examine.php?id=' . $fileId . '">Names / Imports / Exports</a></p>';
    if ($isAdmin) {
        $confirm = 'Delete this UPK from storage and the catalog? Its names, imports, exports and dependencies will also be deleted.';
        echo '<form method="post" action="file-maintenance.php" onsubmit="return confirm(\'' . catalog_h($confirm) . '\')">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('catalog-maintenance')) . '">';
        echo '<input type="hidden" name="file_id" value="' . $fileId . '"><input type="hidden" name="operation" value="remove">';
        echo '<button class="danger" type="submit">Delete UPK package</button></form>';
    }
    echo '</div>';

    if (trim((string)$upk['scan_notes']) !== '') {
        echo '<div class="card"><h2>Scan notes</h2><pre class="mono">' . catalog_h((string)$upk['scan_notes']) . '</pre></div>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>UPK contents</h2><p>'
        . number_format($total) . ' matching serialized export object(s).</p></div></div><div class="ui-section__body">';
    echo '<p class="muted">These entries are serialized UE3 UObject exports inside the original UPK. They are indexed and linked to the package examiner, but they are not presented as independent .upk files because export payloads are not standalone Unreal packages.</p>';
    echo '<form class="table-controls" method="get"><input type="hidden" name="id" value="' . $fileId . '">';
    echo '<label>Search contents <input type="search" name="export_filter" value="' . catalog_h($filter) . '" placeholder="Object, class or path"></label> ';
    echo '<label>Class <select name="class_name"><option value="">All classes</option>';
    foreach ($classes as $class) {
        $value = (string)$class['class_name'];
        echo '<option value="' . catalog_h($value) . '"' . ($classFilter === $value ? ' selected' : '') . '>'
            . catalog_h($value) . ' (' . number_format((int)$class['c']) . ')</option>';
    }
    echo '</select></label> <button type="submit">Apply</button>';
    if ($filter !== '' || $classFilter !== '') {
        echo ' <a class="button" href="upk-info.php?id=' . $fileId . '">Clear</a>';
    }
    echo '</form>';

    if ($exports === []) {
        echo CatalogUi::emptyState(
            'No exports found',
            'No UPK exports match the selected filters.',
            ['label' => 'Clear filters', 'href' => 'upk-info.php?id=' . $fileId],
            '⌕'
        );
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>#</th><th>Class</th><th>Object</th><th>Local path</th><th>Full path</th><th>Outer</th><th>Serialized offset / size</th><th>Flags</th><th>Open</th></tr></thead><tbody>';
        foreach ($exports as $export) {
            $exportIndex = (int)$export['export_index'];
            $href = catalog_upk_export_href($fileId, $exportIndex);
            echo '<tr>';
            echo '<td class="mono">' . $exportIndex . '</td>';
            echo '<td class="mono">' . catalog_h((string)$export['class_name']) . '</td>';
            echo '<td><a href="' . catalog_h($href) . '"><strong>' . catalog_h((string)$export['object_name']) . '</strong></a></td>';
            echo '<td class="mono path">' . catalog_h((string)$export['local_path']) . '</td>';
            echo '<td class="mono path">' . catalog_h((string)$export['full_path']) . '</td>';
            echo '<td class="mono">' . (int)$export['outer_index'] . '</td>';
            echo '<td class="mono nowrap">' . number_format((int)$export['serial_offset']) . ' / ' . catalog_h(catalog_bytes((int)$export['serial_size'])) . '</td>';
            echo '<td class="mono small">0x' . strtoupper(str_pad(dechex((int)$export['object_flags']), 8, '0', STR_PAD_LEFT)) . '</td>';
            echo '<td><a class="button" href="' . catalog_h($href) . '">View export</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($pages > 1) {
            echo '<nav class="pagination">';
            if ($page > 1) {
                echo '<a class="button" href="' . catalog_h(upk_info_url(['export_page' => $page - 1])) . '">Previous</a> ';
            }
            echo '<span>Page ' . $page . ' of ' . $pages . '</span>';
            if ($page < $pages) {
                echo ' <a class="button" href="' . catalog_h(upk_info_url(['export_page' => $page + 1])) . '">Next</a>';
            }
            echo '</nav>';
        }
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('UPK information error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'UPK information could not be loaded.');
    catalog_foot();
}
