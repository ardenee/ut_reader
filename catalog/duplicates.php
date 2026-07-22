<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();

const DUPLICATES_MAX_PAGE_LIMIT = 950;

function duplicates_int_get(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function duplicates_url(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'duplicates.php' . ($query ? '?' . http_build_query($query) : '');
}

function duplicates_return_url(): string
{
    $url = (string)($_POST['return_url'] ?? 'duplicates.php');
    $path = basename((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    return $path === 'duplicates.php' ? $url : 'duplicates.php';
}

function duplicates_valid_guid(string $guid): bool
{
    $compact = preg_replace('/[^A-Fa-f0-9]/', '', trim($guid)) ?? '';
    return strlen($compact) === 32 && preg_match('/^0+$/', $compact) !== 1;
}

function duplicates_type_from_extension(string $ext): array
{
    $ext = strtolower(trim($ext, '. '));
    return match ($ext) {
        'unr', 'ut2', 'ut3', 'umap' => ['map', 'type-map'],
        'umx' => ['music', 'type-music'],
        'uax' => ['sound', 'type-sound'],
        'utx' => ['texture', 'type-texture'],
        'usx' => ['static mesh', 'type-static-mesh'],
        'ukx' => ['animation', 'type-animation'],
        'upx' => ['particle/effect', 'type-particle-effect'],
        'ugx' => ['gui', 'type-gui'],
        'con' => ['content', 'type-content'],
        'u', 'un2', 'upk', 'uasset' => ['package', 'type-package'],
        default => [$ext !== '' ? $ext : 'unknown', 'type-unknown'],
    };
}

function duplicates_type_filter_sql(string $type): array
{
    $map = [
        'map' => ['unr', 'ut2', 'ut3', 'umap'],
        'music' => ['umx'],
        'sound' => ['uax'],
        'texture' => ['utx'],
        'static_mesh' => ['usx'],
        'animation' => ['ukx'],
        'particle_effect' => ['upx'],
        'gui' => ['ugx'],
        'content' => ['con'],
        'package' => ['u', 'un2', 'upk', 'uasset'],
    ];
    return $map[$type] ?? [];
}

function duplicates_same_group(PDO $db, int $canonicalId, int $duplicateId): bool
{
    $rows = catalog_all($db, 'SELECT id, game_id, package_guid FROM ue_files WHERE id IN (?,?)', [$canonicalId, $duplicateId]);
    if (count($rows) !== 2) {
        return false;
    }

    $a = $rows[0];
    $b = $rows[1];
    $guidA = (string)($a['package_guid'] ?? '');
    $guidB = (string)($b['package_guid'] ?? '');

    return (int)$a['game_id'] === (int)$b['game_id']
        && duplicates_valid_guid($guidA)
        && $guidA === $guidB;
}

function duplicates_retire_file(PDO $db, int $canonicalId, int $duplicateId): void
{
    if ($canonicalId === $duplicateId) {
        return;
    }
    if (!duplicates_same_group($db, $canonicalId, $duplicateId)) {
        throw new RuntimeException('File ' . $duplicateId . ' is not in the same valid GUID group as canonical file ' . $canonicalId);
    }

    $locations = catalog_all($db, 'SELECT * FROM ue_file_locations WHERE file_id=?', [$duplicateId]);
    $insertLocation = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source), last_seen_at=VALUES(last_seen_at)');
    foreach ($locations as $loc) {
        $insertLocation->execute([$canonicalId, (int)$loc['source_id'], (string)$loc['source_relative_path'], (int)$loc['exists_in_source'], $loc['last_seen_at']]);
    }

    $deps = catalog_all($db, 'SELECT id, required_object_path FROM ue_dependencies WHERE resolved_file_id=?', [$duplicateId]);
    $updateDep = $db->prepare('UPDATE ue_dependencies SET resolved_file_id=?, resolved_export_id=?, status=? WHERE id=?');
    foreach ($deps as $dep) {
        $export = catalog_one($db, 'SELECT id FROM ue_exports WHERE file_id=? AND full_path=? LIMIT 1', [$canonicalId, (string)$dep['required_object_path']]);
        $updateDep->execute([$canonicalId, $export ? (int)$export['id'] : null, $export ? 'resolved' : 'package_only', (int)$dep['id']]);
    }

    $db->prepare('UPDATE ue_files SET scan_status="duplicate", scan_notes=CONCAT(COALESCE(scan_notes,""), ? ) WHERE id=?')
        ->execute(["\nRetired as duplicate of file ID " . $canonicalId . " on " . date('Y-m-d H:i:s'), $duplicateId]);
}

/** @return list<array{canonical_id:int,duplicate_ids:list<int>}> */
function duplicates_post_groups(): array
{
    $groups = [];
    $canonicalGroups = $_POST['canonical_ids'] ?? null;
    $duplicateGroups = $_POST['duplicate_ids'] ?? null;

    if (is_array($canonicalGroups) && is_array($duplicateGroups)) {
        foreach ($duplicateGroups as $groupKey => $ids) {
            if (!is_array($ids)) {
                continue;
            }
            $canonicalId = (int)($canonicalGroups[$groupKey] ?? 0);
            if ($canonicalId <= 0) {
                continue;
            }
            $duplicateIds = array_values(array_filter(
                array_unique(array_map('intval', $ids)),
                static fn(int $value): bool => $value > 0 && $value !== $canonicalId
            ));
            if ($duplicateIds !== []) {
                $groups[] = ['canonical_id' => $canonicalId, 'duplicate_ids' => $duplicateIds];
            }
        }
        return $groups;
    }

    $canonicalId = (int)($_POST['canonical_id'] ?? 0);
    $duplicateIds = array_values(array_filter(
        array_unique(array_map('intval', is_array($_POST['duplicate_ids'] ?? null) ? $_POST['duplicate_ids'] : [])),
        static fn(int $value): bool => $value > 0 && $value !== $canonicalId
    ));
    if ($canonicalId > 0 && $duplicateIds !== []) {
        $groups[] = ['canonical_id' => $canonicalId, 'duplicate_ids' => $duplicateIds];
    }
    return $groups;
}

function duplicates_page_styles(): string
{
    return <<<'CSS'
<style>
.duplicates-controls{display:flex;align-items:end;flex-wrap:wrap;gap:10px;margin:0 0 12px}.duplicates-controls label{display:grid;gap:5px}.duplicates-controls .wide-search{width:min(520px,90vw)}.duplicates-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:12px 0;flex-wrap:wrap}.duplicates-submit-bar{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin:12px 0}.duplicates-group-card{overflow:hidden}.duplicates-table{min-width:1380px}.duplicates-table th,.duplicates-table td{vertical-align:top}.duplicates-select-col{width:62px;text-align:center;white-space:nowrap}.duplicates-id,.duplicates-size,.duplicates-download{width:1%;white-space:nowrap}.duplicates-download{text-align:center}.duplicates-file-link,.duplicates-package-link{font-weight:650}.duplicates-md5,.duplicates-database,.duplicates-uploaded{white-space:nowrap;overflow-wrap:normal}.duplicates-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 16px}.duplicates-help{border-left:4px solid var(--amber);padding-left:12px}.duplicates-download-link{display:inline-grid;place-items:center;width:34px;height:34px;border:1px solid var(--line2);border-radius:9px;color:var(--blue);background:rgba(118,169,255,.08);font-size:20px;font-weight:800;line-height:1}.duplicates-download-link:hover{background:rgba(118,169,255,.18);text-decoration:none}@media(max-width:760px){.duplicates-summary{grid-template-columns:1fr}}
</style>
CSS;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('duplicates');
        $postedGroups = duplicates_post_groups();
        if ($postedGroups === []) {
            throw new RuntimeException('Choose at least one canonical file and at least one duplicate file to retire.');
        }

        $totalSelected = array_sum(array_map(static fn(array $group): int => count($group['duplicate_ids']), $postedGroups));
        if ($totalSelected > DUPLICATES_MAX_PAGE_LIMIT) {
            throw new RuntimeException('Too many duplicate files selected. Process at most ' . DUPLICATES_MAX_PAGE_LIMIT . ' rows at once.');
        }

        $db->beginTransaction();
        try {
            foreach ($postedGroups as $postedGroup) {
                foreach ($postedGroup['duplicate_ids'] as $duplicateId) {
                    duplicates_retire_file($db, $postedGroup['canonical_id'], $duplicateId);
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        $_SESSION['flash_duplicates'] = 'Retired ' . $totalSelected . ' duplicate file(s) into ' . count($postedGroups) . ' canonical group(s).';
        header('Location: ' . duplicates_return_url());
        exit;
    }

    if (!catalog_require_admin_page('GUID duplicates')) {
        exit;
    }

    $games = catalog_all($db, 'SELECT id, name FROM ue_games ORDER BY name');
    $gameId = duplicates_int_get('game_id', 0, 0, PHP_INT_MAX);
    $knownGameIds = array_map(static fn(array $game): int => (int)$game['id'], $games);
    if ($gameId > 0 && !in_array($gameId, $knownGameIds, true)) {
        $gameId = 0;
    }

    $query = trim((string)($_GET['q'] ?? ''));
    $typeFilter = trim((string)($_GET['type_filter'] ?? ''));
    $compressionFilter = trim((string)($_GET['compression_filter'] ?? ''));
    $limit = duplicates_int_get('limit', 100, 10, DUPLICATES_MAX_PAGE_LIMIT);
    $page = duplicates_int_get('page', 1, 1, PHP_INT_MAX);

    $duplicateGroupSql = 'SELECT game_id, package_guid, COUNT(*) duplicate_count FROM ue_files '
        . 'WHERE package_guid IS NOT NULL AND package_guid<>"" '
        . 'AND REPLACE(package_guid,"-","")<>REPEAT("0",32) '
        . 'AND scan_status="verified" GROUP BY game_id, package_guid HAVING COUNT(*) > 1';
    $where = 'WHERE f.scan_status="verified"';
    $args = [];

    if ($gameId > 0) {
        $where .= ' AND f.game_id=?';
        $args[] = $gameId;
    }
    if ($query !== '') {
        $where .= ' AND (g.name LIKE ? OR f.package_name LIKE ? OR f.original_name LIKE ? OR f.md5 LIKE ? OR f.sha1 LIKE ? OR f.package_guid LIKE ?' . (ctype_digit($query) ? ' OR f.id=?' : '') . ')';
        $like = '%' . $query . '%';
        array_push($args, $like, $like, $like, $like, $like, $like);
        if (ctype_digit($query)) {
            $args[] = (int)$query;
        }
    }
    $typeExts = duplicates_type_filter_sql($typeFilter);
    if ($typeExts !== []) {
        $where .= ' AND f.extension IN (' . implode(',', array_fill(0, count($typeExts), '?')) . ')';
        array_push($args, ...$typeExts);
    }
    if ($compressionFilter === 'compressed') {
        $where .= ' AND f.is_compressed=1';
    } elseif ($compressionFilter === 'uncompressed') {
        $where .= ' AND f.is_compressed=0';
    }

    $countSql = 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
        . 'JOIN (' . $duplicateGroupSql . ') grp ON grp.game_id=f.game_id AND grp.package_guid=f.package_guid ' . $where;
    $totalRows = (int)(catalog_one($db, 'SELECT COUNT(*) c ' . $countSql, $args)['c'] ?? 0);
    $totalGroups = (int)(catalog_one($db, 'SELECT COUNT(DISTINCT f.game_id, f.package_guid) c ' . $countSql, $args)['c'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $rows = catalog_all($db, '
        SELECT f.id,f.game_id,g.name AS game_name,f.package_guid,grp.duplicate_count,
               f.package_name,f.original_name,f.md5,f.sha1,f.extension,f.file_size,f.is_compressed,
               f.uploaded_at,f.package_version,f.licensee_version,
               COALESCE(f.name_count,0) AS name_count,
               COALESCE(f.import_count,0) AS import_count,
               COALESCE(f.export_count,0) AS export_count,
               COALESCE(l.source_location_count,0) AS source_location_count
        FROM ue_files f
        JOIN ue_games g ON g.id=f.game_id
        JOIN (' . $duplicateGroupSql . ') grp ON grp.game_id=f.game_id AND grp.package_guid=f.package_guid
        LEFT JOIN (
            SELECT file_id,COUNT(*) AS source_location_count
            FROM ue_file_locations
            WHERE exists_in_source=1
            GROUP BY file_id
        ) l ON l.file_id=f.id
        ' . $where . '
        ORDER BY g.name,f.package_guid,f.is_compressed ASC,f.file_size DESC,f.uploaded_at ASC,f.id ASC
        LIMIT ' . $limit . ' OFFSET ' . $offset,
        $args
    );

    $groups = [];
    foreach ($rows as $row) {
        $key = (int)$row['game_id'] . ':' . (string)$row['package_guid'];
        $groups[$key]['game_name'] = (string)$row['game_name'];
        $groups[$key]['package_guid'] = (string)$row['package_guid'];
        $groups[$key]['duplicate_count'] = (int)$row['duplicate_count'];
        $groups[$key]['rows'][] = $row;
    }

    catalog_head('GUID duplicates');
    echo duplicates_page_styles();
    catalog_flash($_SESSION['flash_duplicates'] ?? null);
    unset($_SESSION['flash_duplicates']);

    catalog_page_header('GUID duplicate manager', 'Find active verified packages with the same valid Unreal package GUID in the same game. All-zero placeholder GUIDs are excluded.', ['Games' => 'games.php', 'Zero GUID repair' => 'guid-normalize.php', 'Source Scanner' => 'source-scan.php', 'Sources' => 'sources.php']);

    echo '<div class="card duplicates-help"><h2>What this page does</h2>';
    echo '<p><strong>Keep</strong> means the file row you want to keep as the active catalog record for this GUID group. <strong>Retire</strong> marks the selected duplicate row as <span class="mono">scan_status=duplicate</span>, moves its source locations onto the kept file, and redirects dependency rows that previously resolved to the duplicate so they point at the kept file where possible.</p>';
    echo '<p class="muted">Blank and all-zero GUIDs are invalid placeholders, not package identities. They are excluded here and can be reviewed through Zero GUID repair.</p></div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Filters</h2><p>Hard page limit is ' . DUPLICATES_MAX_PAGE_LIMIT . ' visible file rows to stay below common PHP post variable limits.</p></div></div><div class="ui-section__body">';
    echo '<form class="duplicates-controls" method="get">';
    echo '<label for="dup-search">Search<input id="dup-search" class="wide-search" type="search" name="q" value="' . catalog_h($query) . '" placeholder="Game, package, filename, MD5, SHA1, GUID, file ID"></label>';
    echo '<label for="dup-game">Game<select id="dup-game" name="game_id"><option value="0">All games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label for="dup-type">File type<select id="dup-type" name="type_filter">';
    foreach (['' => 'All', 'map' => 'Maps', 'music' => 'Music', 'sound' => 'Sounds', 'texture' => 'Textures', 'static_mesh' => 'Static meshes', 'animation' => 'Animations', 'particle_effect' => 'Particles/effects', 'gui' => 'GUI', 'content' => 'Content', 'package' => 'Packages'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($typeFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label for="dup-compression">Chunks<select id="dup-compression" name="compression_filter">';
    foreach (['' => 'All', 'compressed' => 'Compressed chunks', 'uncompressed' => 'No compressed chunks'] as $value => $label) {
        echo '<option value="' . catalog_h($value) . '"' . ($compressionFilter === $value ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label for="dup-limit">Rows/page<select id="dup-limit" name="limit">';
    foreach ([50, 100, 250, 500, 950] as $option) {
        echo '<option value="' . $option . '"' . ($limit === $option ? ' selected' : '') . '>' . $option . '</option>';
    }
    echo '</select></label>';
    echo CatalogUi::button('Apply filters', ['type' => 'submit']);
    if ($query !== '' || $gameId > 0 || $typeFilter !== '' || $compressionFilter !== '' || $limit !== 100) {
        echo CatalogUi::button('Clear filters', ['href' => 'duplicates.php', 'variant' => 'quiet']);
    }
    echo '</form></div></section>';

    echo '<div class="duplicates-summary">';
    echo '<div class="stat"><h2>' . $totalRows . '</h2><p>matching duplicate file rows</p></div>';
    echo '<div class="stat"><h2>' . $totalGroups . '</h2><p>matching GUID groups</p></div>';
    echo '<div class="stat"><h2>' . $page . ' / ' . $totalPages . '</h2><p>page</p></div>';
    echo '</div>';

    $pagination = '<nav class="duplicates-pagination"><span class="muted">Showing ' . ($totalRows ? ($offset + 1) : 0) . '–' . min($offset + $limit, $totalRows) . ' of ' . $totalRows . ' duplicate file rows.</span><span>'
        . ($page > 1 ? CatalogUi::button('First', ['href' => duplicates_url(['page' => 1]), 'variant' => 'secondary', 'size' => 'sm']) . CatalogUi::button('Previous', ['href' => duplicates_url(['page' => $page - 1]), 'variant' => 'secondary', 'size' => 'sm']) : '')
        . ($page < $totalPages ? CatalogUi::button('Next', ['href' => duplicates_url(['page' => $page + 1]), 'variant' => 'secondary', 'size' => 'sm']) . CatalogUi::button('Last', ['href' => duplicates_url(['page' => $totalPages]), 'variant' => 'secondary', 'size' => 'sm']) : '')
        . '</span></nav>';

    if ($totalRows === 0) {
        echo CatalogUi::emptyState('No active GUID duplicates found', 'No active duplicate package GUID rows match the current filters.');
        catalog_foot();
        exit;
    }

    echo $pagination;
    echo '<form method="post" id="duplicates-page-form" onsubmit="return confirm(\'Submit retire changes for all selected duplicate rows on this page?\')">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('duplicates')) . '">';
    echo '<input type="hidden" name="return_url" value="' . catalog_h(duplicates_url(['page' => $page])) . '">';
    echo '<div class="duplicates-submit-bar"><span class="muted small">Selections across all visible groups are submitted together.</span><button type="submit">Submit</button></div>';

    foreach ($groups as $groupKey => $group) {
        $files = $group['rows'];
        $suggestedCanonical = (int)($files[0]['id'] ?? 0);
        echo '<div class="card duplicates-group-card" data-duplicate-group>';
        echo '<h2>' . catalog_h((string)$group['game_name']) . ' / <span class="mono">' . catalog_h((string)$group['package_guid']) . '</span></h2>';
        echo '<p class="muted">GUID group size: ' . (int)$group['duplicate_count'] . ' active file(s). Visible on this page/filter: ' . count($files) . '.</p>';
        echo '<div class="ui-table-region"><table class="duplicates-table"><thead><tr>';
        echo '<th class="duplicates-select-col" title="The file row to keep active">Keep</th>';
        echo '<th class="duplicates-select-col" title="Rows selected here are retired into the kept row">Retire</th>';
        echo '<th>ID</th><th>Package</th><th>File</th><th>MD5</th><th>Size</th><th>File type</th><th>Chunks</th><th title="Names / Imports / Exports">Database</th><th>Sources</th><th>Uploaded</th><th>Download</th>';
        echo '</tr></thead><tbody>';

        foreach ($files as $file) {
            $compressed = (int)($file['is_compressed'] ?? 0) === 1;
            $chunkBadge = CatalogUi::badge($compressed ? 'compressed' : 'none', $compressed ? 'warning' : 'success');
            [$fileType, $fileTypeClass] = duplicates_type_from_extension((string)$file['extension']);
            $database = (int)$file['name_count'] . ' / ' . (int)$file['import_count'] . ' / ' . (int)$file['export_count'];
            $uploadedAt = trim((string)($file['uploaded_at'] ?? ''));
            $uploadedTimestamp = $uploadedAt !== '' ? strtotime($uploadedAt) : false;
            $uploadedDate = $uploadedTimestamp === false ? ($uploadedAt !== '' ? $uploadedAt : '—') : date('Y-m-d', $uploadedTimestamp);
            $uploadedTime = $uploadedTimestamp === false ? $uploadedAt : date('H:i:s', $uploadedTimestamp);
            $id = (int)$file['id'];
            $originalName = catalog_clean_unreal_filename((string)$file['original_name']);

            echo '<tr>';
            echo '<td class="duplicates-select-col"><input type="radio" name="canonical_ids[' . catalog_h((string)$groupKey) . ']" value="' . $id . '" data-canonical-radio ' . ($id === $suggestedCanonical ? 'checked' : '') . '></td>';
            echo '<td class="duplicates-select-col"><input type="checkbox" name="duplicate_ids[' . catalog_h((string)$groupKey) . '][]" value="' . $id . '" data-retire-checkbox></td>';
            echo '<td class="mono duplicates-id">' . $id . '</td>';
            echo '<td class="mono"><a class="duplicates-package-link" href="file-info.php?id=' . $id . '">' . catalog_h($file['package_name']) . '</a></td>';
            echo '<td><a class="duplicates-file-link" href="file-examine.php?id=' . $id . '">' . catalog_h($originalName) . '</a></td>';
            echo '<td class="mono small duplicates-md5">' . catalog_h($file['md5']) . '</td>';
            echo '<td class="duplicates-size">' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
            echo '<td><span class="dep file-type-pill ' . catalog_h($fileTypeClass) . '">' . catalog_h($fileType) . '</span></td>';
            echo '<td>' . $chunkBadge . '</td>';
            echo '<td class="small mono duplicates-database" title="Names / Imports / Exports">' . catalog_h($database) . '</td>';
            echo '<td>' . (int)$file['source_location_count'] . '</td>';
            echo '<td class="small mono duplicates-uploaded" title="Time: ' . catalog_h($uploadedTime) . '">' . catalog_h($uploadedDate) . '</td>';
            echo '<td class="duplicates-download"><a class="duplicates-download-link" href="download-info.php?id=' . $id . '" title="Download ' . catalog_h($originalName) . '" aria-label="Download ' . catalog_h($originalName) . '">⇩</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    echo '<div class="duplicates-submit-bar"><span class="muted small">Selections across all visible groups are submitted together.</span><button type="submit">Submit</button></div>';
    echo '</form>' . $pagination;

    echo <<<'JS'
<script>
(function () {
    var pageForm = document.getElementById('duplicates-page-form');
    if (!pageForm) return;

    function syncGroup(group) {
        var selected = group.querySelector('[data-canonical-radio]:checked');
        var selectedId = selected ? selected.value : '';
        group.querySelectorAll('[data-retire-checkbox]').forEach(function (box) {
            if (box.value === selectedId) {
                box.checked = false;
                box.disabled = true;
            } else {
                box.disabled = false;
            }
        });
    }

    pageForm.querySelectorAll('[data-duplicate-group]').forEach(function (group) {
        group.querySelectorAll('[data-canonical-radio]').forEach(function (radio) {
            radio.addEventListener('change', function () { syncGroup(group); });
        });
        syncGroup(group);
    });

    pageForm.addEventListener('submit', function (event) {
        if (!pageForm.querySelector('[data-retire-checkbox]:checked')) {
            event.preventDefault();
            window.alert('Tick at least one duplicate row to retire.');
        }
    });
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Duplicate manager error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
