<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';

function uv_page_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function uv_page_text(string $key): string
{
    $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    return is_string($value) ? trim($value) : '';
}

function uv_page_header(array $item): string
{
    $header = is_array($item['header'] ?? null) ? $item['header'] : [];
    $engine = strtoupper(trim((string)($header['engine'] ?? 'UNKNOWN'))) ?: 'UNKNOWN';
    if (!empty($header['ok'])) {
        return $engine . ' v' . (int)($header['version'] ?? 0) . ' / lic ' . (int)($header['licensee'] ?? 0);
    }
    return $engine . ' / unreadable';
}

function uv_page_identity_matches(PDO $db, array $item): array
{
    $where = [];
    $args = [];
    $md5 = strtolower(trim((string)($item['md5'] ?? ''));
    $guid = trim((string)($item['package_guid'] ?? ''));
    if (preg_match('/^[a-f0-9]{32}$/', $md5) === 1) {
        $where[] = 'f.md5=?';
        $args[] = $md5;
    }
    if ($guid !== '' && preg_match('/^0+(?:-0+)*$/', str_replace('-', '', $guid)) !== 1) {
        $where[] = 'f.package_guid=?';
        $args[] = $guid;
    }
    if ($where === []) return [];
    return catalog_all(
        $db,
        'SELECT f.id,f.original_name,f.package_name,f.md5,f.package_guid,g.name game_name,p.engine_key'
        . ' FROM ue_files f JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' WHERE f.scan_status="verified" AND (' . implode(' OR ', $where) . ') ORDER BY g.name,f.original_name LIMIT 8',
        $args
    );
}

function uv_page_identity_html(array $matches): string
{
    if ($matches === []) return '<span class="muted">No verified MD5/GUID match.</span>';
    $html = '<div class="uv-id-list">';
    foreach ($matches as $match) {
        $html .= '<a href="file-info.php?id=' . (int)$match['id'] . '"><strong>' . catalog_h((string)$match['game_name']) . '</strong><span class="mono">' . catalog_h((string)$match['original_name']) . '</span><small>' . catalog_h((string)($match['engine_key'] ?? '')) . '</small></a>';
    }
    return $html . '</div>';
}

function uv_page_assessment(array $row): array
{
    return match ((string)$row['assessment']) {
        'likely' => ['Likely usable', 'good'],
        'possible' => ['Possible match', 'warn'],
        'package_only' => ['Package-name only', 'warn'],
        'compatible' => ['Profile compatible', 'info'],
        'conflict' => ['Evidence conflicts', 'bad'],
        default => ['Not compatible', 'bad'],
    };
}

function uv_page_matches_html(array $rows): string
{
    $useful = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['rank'] <= 4));
    if ($useful === []) {
        return '<span class="muted">No compatible or catalogue-backed game target.</span>';
    }
    $html = '<div class="uv-match-list">';
    foreach (array_slice($useful, 0, 5) as $row) {
        [$label, $tone] = uv_page_assessment($row);
        $rate = $row['match_percent'] === null ? '' : rtrim(rtrim(number_format((float)$row['match_percent'], 1), '0'), '.') . '%';
        $match = (int)$row['import_count'] > 0
            ? (int)$row['exact_object_matches'] . '/' . (int)$row['import_count'] . ' exact' . ($rate !== '' ? ' (' . $rate . ')' : '')
            : 'no package references';
        $html .= '<div class="uv-match ' . catalog_h($tone) . '"><div><strong>' . catalog_h((string)$row['game_name']) . '</strong><small>' . catalog_h((string)$row['engine_key']) . ' · ' . catalog_h($label) . '</small></div><div class="uv-match-count"><strong>' . catalog_h($match) . '</strong><small>' . (int)$row['owner_count'] . ' requiring file(s)</small></div></div>';
    }
    if (count($useful) > 5) $html .= '<small class="muted">+' . (count($useful) - 5) . ' more possible games in Object Check.</small>';
    return $html . '</div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Unverified Files')) exit;
    catalog_unverified_schema_ensure($db);

    $sourceGameId = uv_page_int('source_game_id', 0);
    $extension = strtolower(uv_page_text('extension'));
    $engine = strtoupper(uv_page_text('engine'));
    $version = uv_page_text('version');
    $licensee = uv_page_text('licensee');
    $indexedFilter = uv_page_text('indexed');
    $page = max(1, uv_page_int('queue_page', 1));
    $limit = max(25, min(250, uv_page_int('limit', 100)));

    $listSource = $sourceGameId === 0 ? null : ($sourceGameId === -1 ? 0 : $sourceGameId);
    $allItems = uvf_list($db, $config, $listSource);
    $games = catalog_all($db, 'SELECT g.id,g.name,p.engine_key FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name');

    $indexedCount = 0;
    foreach ($allItems as &$item) {
        $row = catalog_unverified_find($db, (int)$item['game']['id'], (string)$item['queue_name']);
        $item['db_row'] = $row;
        if ($row) {
            $indexedCount++;
            $item['db_file_id'] = (int)$row['id'];
            $item['package_name'] = (string)$row['package_name'];
            $item['original_name'] = (string)$row['original_name'];
            $item['extension'] = (string)$row['extension'];
            $item['md5'] = (string)$row['md5'];
            $item['package_guid'] = (string)($row['package_guid'] ?? '');
            $item['header'] = [
                'ok' => (string)$row['detected_engine_key'] !== '',
                'engine' => (string)($row['detected_engine_key'] ?? 'UNKNOWN'),
                'version' => $row['detected_package_version'],
                'licensee' => $row['detected_licensee_version'],
                'note' => '',
            ];
        }
    }
    unset($item);

    $filtered = array_values(array_filter($allItems, static function (array $item) use ($extension, $engine, $version, $licensee, $indexedFilter): bool {
        if ($extension !== '' && strtolower((string)$item['extension']) !== $extension) return false;
        $header = (array)($item['header'] ?? []);
        if ($engine !== '' && strtoupper((string)($header['engine'] ?? 'UNKNOWN')) !== $engine) return false;
        if ($version !== '' && (string)($header['version'] ?? 'unknown') !== $version) return false;
        if ($licensee !== '' && (string)($header['licensee'] ?? 'unknown') !== $licensee) return false;
        $isIndexed = !empty($item['db_row']);
        if ($indexedFilter === 'yes' && !$isIndexed) return false;
        if ($indexedFilter === 'no' && $isIndexed) return false;
        return true;
    }));

    $total = count($filtered);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $items = array_slice($filtered, ($page - 1) * $limit, $limit);

    $extensionOptions = array_values(array_unique(array_filter(array_map(static fn(array $item): string => strtolower((string)$item['extension']), $allItems))));
    sort($extensionOptions);
    $engineOptions = array_values(array_unique(array_map(static fn(array $item): string => strtoupper((string)($item['header']['engine'] ?? 'UNKNOWN')), $allItems)));
    sort($engineOptions);

    catalog_head('Unverified Files');
    echo <<<'CSS'
<style>
.uv-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.uv-controls{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:8px;align-items:end}.uv-controls label{display:flex;flex-direction:column;gap:4px}.uv-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}.uv-table{min-width:1500px}.uv-table td{vertical-align:top}.uv-file strong,.uv-id-list a span,.uv-id-list a small{display:block}.uv-id-list,.uv-match-list{display:flex;flex-direction:column;gap:5px}.uv-id-list a{display:block;padding:7px;border:1px solid var(--line2);border-radius:7px}.uv-db-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.uv-db-badge.good{color:#b8f3cb;background:rgba(67,190,110,.15)}.uv-db-badge.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}.uv-match{display:grid;grid-template-columns:minmax(190px,1fr) minmax(150px,.8fr);gap:8px;padding:7px 9px;border:1px solid var(--line2);border-radius:7px}.uv-match.good{border-left:4px solid #43be6e}.uv-match.warn{border-left:4px solid #f6c453}.uv-match.info{border-left:4px solid #4884ff}.uv-match.bad{border-left:4px solid #d85a5a}.uv-match small,.uv-match-count small{display:block;color:var(--muted);font-size:11px}.uv-match-count{text-align:right}.uv-note-row td{padding-top:0;border-top:0}.uv-note{padding:7px 10px;border-left:3px solid #f6c453;color:var(--muted)}.uv-pagination{display:flex;justify-content:space-between;align-items:center;margin:10px 0}@media(max-width:1100px){.uv-controls{grid-template-columns:repeat(3,1fr)}.uv-summary{grid-template-columns:repeat(2,1fr)}}
</style>
CSS;

    echo CatalogUi::pageHeader('Unverified Files', 'Queued package files are indexed in the database immediately but remain hidden from game file lists until promoted to verified.', [
        'Index existing queue files' => 'unverified-database-import.php',
        'Upload bucket' => 'upload-bucket.php',
        'Upload to game' => 'profiled-upload.php',
    ]);

    echo '<div class="uv-summary">';
    echo '<div class="stat"><h2>' . count($allItems) . '</h2><p>Physical queued files</p></div>';
    echo '<div class="stat"><h2>' . $indexedCount . '</h2><p>Database indexed</p></div>';
    echo '<div class="stat"><h2>' . (count($allItems) - $indexedCount) . '</h2><p>Need backfill</p></div>';
    echo '<div class="stat"><h2>' . catalog_h(catalog_bytes(array_sum(array_map(static fn(array $item): int => (int)$item['size'], $allItems)))) . '</h2><p>Queue storage</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Filters</h2><p>' . $total . ' matching file(s).</p></div></div><div class="ui-section__body">';
    echo '<form class="uv-controls" method="get">';
    echo '<label>Source queue<select name="source_game_id"><option value="0">All queues</option><option value="-1"' . ($sourceGameId === -1 ? ' selected' : '') . '>Upload Bucket</option>';
    foreach ($games as $game) echo '<option value="' . (int)$game['id'] . '"' . ($sourceGameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h((string)$game['name']) . '</option>';
    echo '</select></label>';
    echo '<label>File type<select name="extension"><option value="">All</option>';
    foreach ($extensionOptions as $value) echo '<option value="' . catalog_h($value) . '"' . ($extension === $value ? ' selected' : '') . '>' . catalog_h('.' . $value) . '</option>';
    echo '</select></label>';
    echo '<label>UE engine<select name="engine"><option value="">All</option>';
    foreach ($engineOptions as $value) echo '<option value="' . catalog_h($value) . '"' . ($engine === $value ? ' selected' : '') . '>' . catalog_h($value) . '</option>';
    echo '</select></label>';
    echo '<label>Package version<input name="version" value="' . catalog_h($version) . '" placeholder="Any"></label>';
    echo '<label>Licensee version<input name="licensee" value="' . catalog_h($licensee) . '" placeholder="Any"></label>';
    echo '<label>Database status<select name="indexed"><option value="">All</option><option value="yes"' . ($indexedFilter === 'yes' ? ' selected' : '') . '>Indexed</option><option value="no"' . ($indexedFilter === 'no' ? ' selected' : '') . '>Not indexed</option></select></label>';
    echo '<label>Rows<select name="limit">';
    foreach ([25,50,100,250] as $value) echo '<option value="' . $value . '"' . ($limit === $value ? ' selected' : '') . '>' . $value . '</option>';
    echo '</select></label><div><button type="submit">Apply</button> <a class="button secondary" href="unverified-files.php">Clear</a></div></form></div></section>';

    $query = $_GET;
    $pageUrl = static function (int $number) use ($query): string { $query['queue_page'] = $number; return 'unverified-files.php?' . http_build_query($query); };
    echo '<div class="uv-pagination"><span>' . ($page > 1 ? '<a class="button secondary" href="' . catalog_h($pageUrl($page - 1)) . '">Previous</a>' : '') . '</span><span>Page ' . $page . ' of ' . $pages . '</span><span>' . ($page < $pages ? '<a class="button secondary" href="' . catalog_h($pageUrl($page + 1)) . '">Next</a>' : '') . '</span></div>';

    echo '<form id="unverified-bulk-form" method="post" action="unverified-files-action.php">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('unverified-files')) . '">';
    echo '<div class="uv-actions"><label>Target game <select name="target_game_id"><option value="">Choose game</option>';
    foreach ($games as $game) echo '<option value="' . (int)$game['id'] . '">' . catalog_h((string)$game['name'] . ' / ' . (string)($game['engine_key'] ?? '')) . '</option>';
    echo '</select></label><button name="action" value="import">Import selected</button><button name="action" value="move" class="secondary">Move queue</button><button id="unverified-object-check" type="button" class="secondary">Object Check</button><button name="action" value="delete" class="danger">Delete selected</button><label><input type="checkbox" name="allow_profile_override" value="1"> Allow profile override</label></div>';

    if ($items === []) {
        echo CatalogUi::emptyState('No queued files', 'No unverified files match the selected filters.');
    } else {
        echo '<div class="table-wrap"><table class="uv-table"><thead><tr><th></th><th>Queue</th><th>File</th><th>Identity</th><th>Database</th><th>Detected</th><th>Size</th><th>Possible game targets</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $row = $item['db_row'];
            $matches = $row ? catalog_unverified_game_matches($db, (int)$row['id']) : [];
            $identity = uv_page_identity_matches($db, $item);
            echo '<tr>';
            echo '<td><input class="unverified-select" type="checkbox" name="tokens[]" value="' . catalog_h((string)$item['token']) . '" aria-label="Select ' . catalog_h((string)$item['original_name']) . '"></td>';
            echo '<td><strong>' . catalog_h((string)$item['game']['name']) . '</strong><small class="muted">' . catalog_h((string)$item['game']['slug']) . '/unverified</small></td>';
            echo '<td class="uv-file"><strong>' . catalog_h((string)$item['original_name']) . '</strong><span>Package: <span class="mono">' . catalog_h((string)$item['package_name']) . '</span></span><small>Queued: ' . catalog_h(date('Y-m-d H:i', (int)$item['modified_at'])) . '</small></td>';
            echo '<td><span class="mono small">MD5: ' . catalog_h((string)$item['md5']) . '</span><br><span class="mono small">GUID: ' . catalog_h((string)$item['package_guid']) . '</span>' . uv_page_identity_html($identity) . '</td>';
            if ($row) {
                echo '<td><span class="uv-db-badge good">Indexed</span><div><a href="file-examine.php?id=' . (int)$row['id'] . '">DB file #' . (int)$row['id'] . '</a></div><small>' . (int)$row['name_count'] . ' / ' . (int)$row['import_count'] . ' / ' . (int)$row['export_count'] . ' N/I/E</small></td>';
            } else {
                echo '<td><span class="uv-db-badge bad">Not indexed</span><div><a href="unverified-database-import.php">Run backfill</a></div></td>';
            }
            echo '<td class="mono">' . catalog_h(uv_page_header($item)) . '</td><td>' . catalog_h(catalog_bytes((int)$item['size'])) . '</td><td>' . uv_page_matches_html($matches) . '</td></tr>';
            if (trim((string)$item['reason']) !== '') echo '<tr class="uv-note-row"><td></td><td colspan="7"><div class="uv-note"><strong>Queue note</strong><br>' . nl2br(catalog_h((string)$item['reason'])) . '</div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</form><div class="uv-pagination"><span>' . ($page > 1 ? '<a class="button secondary" href="' . catalog_h($pageUrl($page - 1)) . '">Previous</a>' : '') . '</span><span>Page ' . $page . ' of ' . $pages . '</span><span>' . ($page < $pages ? '<a class="button secondary" href="' . catalog_h($pageUrl($page + 1)) . '">Next</a>' : '') . '</span></div>';
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Unverified Files Error');
    echo CatalogUi::alert('danger', 'Unverified Files could not be loaded.', $error->getMessage());
    catalog_foot();
}
