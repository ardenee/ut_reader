<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the GUID duplicate manager and accepts administrator selections.
 * Why: Duplicate retirement mutations and large duplicate-list SQL now have dedicated service/query owners.
 * Role: Presentation adapter for duplicate filtering, selection and rendering.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogDuplicateRetirementService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDuplicateGroupListQuery;

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

function duplicates_type_from_extension(string $ext): array
{
    $ext = strtolower(trim($ext, '. '));
    return match ($ext) {
        'unr', 'un2', 'ut2', 'ut3', 'umap' => ['map', 'type-map'],
        'umx' => ['music', 'type-music'],
        'uax' => ['sound', 'type-sound'],
        'utx' => ['texture', 'type-texture'],
        'usx' => ['static mesh', 'type-static-mesh'],
        'ukx' => ['animation', 'type-animation'],
        'upx' => ['particle/effect', 'type-particle-effect'],
        'ugx' => ['gui', 'type-gui'],
        'con' => ['content', 'type-content'],
        'u', 'upk', 'uasset' => ['package', 'type-package'],
        default => [$ext !== '' ? $ext : 'unknown', 'type-unknown'],
    };
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
        $result = (new CatalogDuplicateRetirementService($db, $config))
            ->retireSelectedGroups($postedGroups, DUPLICATES_MAX_PAGE_LIMIT);

        $_SESSION['flash_duplicates'] = 'Retired ' . $result['retired'] . ' duplicate file(s) into '
            . $result['groups'] . ' canonical group(s).';
        header('Location: ' . duplicates_return_url());
        exit;
    }

    if (!catalog_require_admin_page('GUID duplicates')) {
        exit;
    }

    $query = trim((string)($_GET['q'] ?? ''));
    $typeFilter = trim((string)($_GET['type_filter'] ?? ''));
    $compressionFilter = trim((string)($_GET['compression_filter'] ?? ''));
    $limit = duplicates_int_get('limit', 100, 10, DUPLICATES_MAX_PAGE_LIMIT);
    $requestedPage = duplicates_int_get('page', 1, 1, PHP_INT_MAX);
    $requestedGameId = duplicates_int_get('game_id', 0, 0, PHP_INT_MAX);

    $duplicateQuery = new PdoDuplicateGroupListQuery($db);
    $games = $duplicateQuery->games();
    $list = $duplicateQuery->fetch(
        $requestedGameId,
        $query,
        $typeFilter,
        $compressionFilter,
        $limit,
        $requestedPage
    );
    $gameId = $list['game_id'];
    $totalRows = $list['total_rows'];
    $totalGroups = $list['total_groups'];
    $totalPages = $list['total_pages'];
    $page = $list['page'];
    $offset = $list['offset'];
    $groups = $list['groups'];

    catalog_head('GUID duplicates');
    echo duplicates_page_styles();
    catalog_flash($_SESSION['flash_duplicates'] ?? null);
    unset($_SESSION['flash_duplicates']);

    catalog_page_header('GUID duplicate manager', 'Find active verified packages with the same valid Unreal package GUID in the same game. All-zero placeholder GUIDs are excluded.', ['Games' => 'games.php', 'Zero GUID repair' => 'guid-normalize.php', 'Source Scanner' => 'source-scan.php', 'Sources' => 'sources.php']);

    echo '<div class="card duplicates-help"><h2>What this page does</h2>';
    echo '<p><strong>Keep</strong> means the file row you want to keep as the active catalog record for this GUID group. <strong>Retire</strong> marks the selected duplicate row as <span class="mono">scan_status=duplicate</span>, moves its source locations onto the kept file, and queues compact dependency reconciliation for the old and new package identities.</p>';
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
