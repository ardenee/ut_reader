<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Unverified Files.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedFileManager.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';

function uv_list_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : (int)$value;
}

function uv_list_text(string $key): string
{
    $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    return is_string($value) ? trim($value) : '';
}

function uv_list_detected(array $row): string
{
    $engine = strtoupper(trim((string)($row['detected_engine_key'] ?? 'UNKNOWN'))) ?: 'UNKNOWN';
    if ($engine === 'UNKNOWN') {
        return 'UNKNOWN / unreadable';
    }
    return $engine
        . ' v' . (int)($row['detected_package_version'] ?? 0)
        . ' / lic ' . (int)($row['detected_licensee_version'] ?? 0);
}

/** @param list<array{game_id:int,game_name:string,owner_count:int,import_count:int}> $matches */
function uv_list_possible_games(array $matches): string
{
    if ($matches === []) {
        return '';
    }

    $html = '<div class="uv-game-links">';
    foreach ($matches as $match) {
        $links = max(0, (int)($match['import_count'] ?? 0));
        if ($links < 1) {
            continue;
        }
        $owners = max(0, (int)($match['owner_count'] ?? 0));
        $html .= '<div><a href="game-files.php?id=' . (int)$match['game_id'] . '"><strong>'
            . catalog_h((string)$match['game_name']) . '</strong></a>'
            . '<small>' . number_format($links) . ' possible package link' . ($links === 1 ? '' : 's')
            . ($owners > 0 ? ' from ' . number_format($owners) . ' file' . ($owners === 1 ? '' : 's') : '')
            . '</small></div>';
    }
    return $html . '</div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Unverified Files')) {
        exit;
    }
    catalog_unverified_schema_ensure($db);

    $sourceGameId = uv_list_int('source_game_id', 0);
    $extension = strtolower(uv_list_text('extension'));
    $engine = strtoupper(uv_list_text('engine'));
    $version = uv_list_text('version');
    $licensee = uv_list_text('licensee');
    $page = max(1, uv_list_int('queue_page', 1));
    $limit = max(50, min(1000, uv_list_int('limit', 100)));

    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,g.slug,g.profile_id,p.engine_key'
        . ' FROM ue_games g'
        . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' ORDER BY g.name'
    );
    $gamesById = [];
    foreach ($games as $game) {
        $gamesById[(int)$game['id']] = $game;
    }
    $bucketGame = uvf_bucket_game();

    $where = ['f.scan_status="unverified"'];
    $args = [];
    if ($sourceGameId === -1) {
        $where[] = 'f.unverified_queue_game_id=0';
    } elseif ($sourceGameId > 0) {
        $where[] = 'f.unverified_queue_game_id=?';
        $args[] = $sourceGameId;
    }
    if ($extension !== '') {
        $where[] = 'f.extension=?';
        $args[] = $extension;
    }
    if ($engine !== '') {
        if ($engine === 'UNKNOWN') {
            $where[] = '(f.detected_engine_key IS NULL OR f.detected_engine_key="")';
        } else {
            $where[] = 'f.detected_engine_key=?';
            $args[] = $engine;
        }
    }
    if ($version !== '') {
        if (strtolower($version) === 'unknown') {
            $where[] = 'f.detected_package_version IS NULL';
        } elseif (preg_match('/^-?\d+$/', $version) === 1) {
            $where[] = 'f.detected_package_version=?';
            $args[] = (int)$version;
        } else {
            $where[] = '1=0';
        }
    }
    if ($licensee !== '') {
        if (strtolower($licensee) === 'unknown') {
            $where[] = 'f.detected_licensee_version IS NULL';
        } elseif (preg_match('/^-?\d+$/', $licensee) === 1) {
            $where[] = 'f.detected_licensee_version=?';
            $args[] = (int)$licensee;
        } else {
            $where[] = '1=0';
        }
    }
    $whereSql = implode(' AND ', $where);

    $countRow = catalog_one($db, 'SELECT COUNT(*) c FROM ue_files f WHERE ' . $whereSql, $args);
    $total = (int)($countRow['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;

    $items = catalog_all(
        $db,
        'SELECT f.id,f.package_name,f.original_name,f.stored_name,f.extension,f.md5,f.package_guid,'
        . 'f.file_size,f.detected_engine_key,f.detected_package_version,f.detected_licensee_version,'
        . 'f.name_count,f.import_count,f.export_count,f.unverified_queue_key,'
        . 'f.unverified_queue_game_id,f.unverified_queue_name,f.unverified_reason'
        . ' FROM ue_files f WHERE ' . $whereSql
        . ' ORDER BY f.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        $args
    );

    /* One package-reference query for the visible page; no per-file review analysis. */
    $referenceMatches = uvf_reference_matches(
        $db,
        array_values(array_unique(array_map(
            static fn(array $item): string => trim((string)($item['package_name'] ?? '')),
            $items
        )))
    );

    $summary = catalog_one(
        $db,
        'SELECT COUNT(*) indexed_count,COALESCE(SUM(file_size),0) indexed_bytes,'
        . 'SUM(CASE WHEN unverified_queue_game_id=0 THEN 1 ELSE 0 END) bucket_count'
        . ' FROM ue_files WHERE scan_status="unverified"'
    ) ?: [];

    $optionRows = catalog_all(
        $db,
        'SELECT extension,detected_engine_key FROM ue_files'
        . ' WHERE scan_status="unverified" GROUP BY extension,detected_engine_key'
    );
    $extensionOptions = [];
    $engineOptions = [];
    foreach ($optionRows as $optionRow) {
        $value = strtolower(trim((string)($optionRow['extension'] ?? '')));
        if ($value !== '') {
            $extensionOptions[$value] = true;
        }
        $value = strtoupper(trim((string)($optionRow['detected_engine_key'] ?? '')));
        $engineOptions[$value !== '' ? $value : 'UNKNOWN'] = true;
    }
    $extensionOptions = array_keys($extensionOptions);
    $engineOptions = array_keys($engineOptions);
    sort($extensionOptions);
    sort($engineOptions);

    catalog_head('Unverified Files');
    echo <<<'CSS'
<style>
.uv-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.uv-controls{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:8px;align-items:end}.uv-controls label{display:flex;flex-direction:column;gap:4px}.uv-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}.uv-table{min-width:1450px}.uv-table td{vertical-align:top}.uv-file strong{display:block}.uv-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.uv-badge.good{color:#b8f3cb;background:rgba(67,190,110,.15)}.uv-badge.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}.uv-game-links{display:grid;gap:7px;min-width:210px}.uv-game-links div{padding-bottom:6px;border-bottom:1px solid var(--line2)}.uv-game-links div:last-child{padding-bottom:0;border-bottom:0}.uv-game-links small{display:block;color:var(--muted)}.uv-note-row td{padding-top:0;border-top:0}.uv-note{padding:7px 10px;border-left:3px solid #f6c453;color:var(--muted)}.uv-pagination{display:flex;justify-content:space-between;align-items:center;margin:10px 0}@media(max-width:1100px){.uv-controls{grid-template-columns:repeat(3,1fr)}.uv-summary{grid-template-columns:repeat(2,1fr)}}
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Unverified Files',
        'This list is database-paginated. Possible games are shown only when verified files reference the staged package name.',
        [
            'Index existing queue files' => 'unverified-database-import.php',
            'Upload bucket' => 'upload-bucket-v2.php',
            'Upload to game' => 'profiled-upload.php',
        ]
    );

    echo '<div class="uv-summary">'
        . '<div class="stat"><h2>' . (int)($summary['indexed_count'] ?? 0) . '</h2><p>Indexed unverified files</p></div>'
        . '<div class="stat"><h2>' . $total . '</h2><p>Matching current filters</p></div>'
        . '<div class="stat"><h2>' . (int)($summary['bucket_count'] ?? 0) . '</h2><p>Upload Bucket files</p></div>'
        . '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)($summary['indexed_bytes'] ?? 0))) . '</h2><p>Indexed queue storage</p></div>'
        . '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Filters</h2><p>'
        . $total . ' matching file(s).</p></div></div><div class="ui-section__body">';
    echo '<form class="uv-controls" method="get">';
    echo '<label>Source queue<select name="source_game_id"><option value="0">All queues</option>'
        . '<option value="-1"' . ($sourceGameId === -1 ? ' selected' : '') . '>Upload Bucket</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"'
            . ($sourceGameId === (int)$game['id'] ? ' selected' : '') . '>'
            . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>';

    echo '<label>File type<select name="extension"><option value="">All</option>';
    foreach ($extensionOptions as $value) {
        echo '<option value="' . catalog_h($value) . '"'
            . ($extension === $value ? ' selected' : '') . '>' . catalog_h('.' . $value) . '</option>';
    }
    echo '</select></label>';

    echo '<label>UE engine<select name="engine"><option value="">All</option>';
    foreach ($engineOptions as $value) {
        echo '<option value="' . catalog_h($value) . '"'
            . ($engine === $value ? ' selected' : '') . '>' . catalog_h($value) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Package version<input name="version" value="' . catalog_h($version) . '" placeholder="Any or unknown"></label>';
    echo '<label>Licensee version<input name="licensee" value="' . catalog_h($licensee) . '" placeholder="Any or unknown"></label>';
    echo '<label>Rows<select name="limit">';
    foreach ([50, 100, 250, 500, 1000] as $value) {
        echo '<option value="' . $value . '"' . ($limit === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><div><button type="submit">Apply</button> '
        . '<a class="button secondary" href="unverified-files.php">Clear</a></div></form></div></section>';

    $query = $_GET;
    $pageUrl = static function (int $number) use ($query): string {
        $query['queue_page'] = $number;
        return 'unverified-files.php?' . http_build_query($query);
    };
    $pagination = '<div class="uv-pagination"><span>'
        . ($page > 1 ? '<a class="button secondary" href="' . catalog_h($pageUrl($page - 1)) . '">Previous</a>' : '')
        . '</span><span>Page ' . $page . ' of ' . $pages . '</span><span>'
        . ($page < $pages ? '<a class="button secondary" href="' . catalog_h($pageUrl($page + 1)) . '">Next</a>' : '')
        . '</span></div>';
    echo $pagination;

    echo '<form id="unverified-bulk-form" method="post" action="unverified-files-action.php">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('unverified-files')) . '">';
    echo '<div class="uv-actions"><label>Target game <select name="target_game_id"><option value="">Choose game</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">'
            . catalog_h((string)$game['name'] . ' / ' . (string)($game['engine_key'] ?? ''))
            . '</option>';
    }
    echo '</select></label>'
        . '<button name="action" value="import">Import selected</button>'
        . '<button name="action" value="move" class="secondary">Move queue</button>'
        . '<button name="action" value="delete" class="danger">Delete selected</button>'
        . '<label><input type="checkbox" name="allow_profile_override" value="1"> Allow profile override</label>'
        . '</div>';

    if ($items === []) {
        echo CatalogUi::emptyState('No indexed queued files', 'No indexed unverified files match the selected filters. Use Index existing queue files for physical files not yet recorded in the database.');
    } else {
        echo '<div class="table-wrap"><table class="uv-table"><thead><tr>'
            . '<th></th><th>Physical queue</th><th>File</th><th>Identity</th><th>Database</th>'
            . '<th>Detected</th><th>Size</th><th>Possible games</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $queueGameId = (int)($item['unverified_queue_game_id'] ?? 0);
            $queueName = basename(trim((string)($item['unverified_queue_name'] ?? '')));
            if ($queueName === '') {
                $queueName = basename((string)($item['stored_name'] ?? $item['original_name'] ?? ''));
            }
            $queueGame = $queueGameId === 0
                ? $bucketGame
                : ($gamesById[$queueGameId] ?? ['name' => 'Unknown queue #' . $queueGameId, 'slug' => 'unknown-' . $queueGameId]);
            $dir = uvf_unverified_dir($config, $queueGame, false);
            $path = $dir . DIRECTORY_SEPARATOR . $queueName;
            $exists = $queueName !== '' && is_file($path) && !is_link($path) && uvf_path_inside($path, $dir);
            $token = uvf_token($queueGameId, $queueName);
            $detailsUrl = 'unverified-file-details.php?id=' . (int)$item['id'];
            $packageKey = strtolower(trim((string)($item['package_name'] ?? '')));
            $possibleGames = $referenceMatches[$packageKey] ?? [];

            echo '<tr>';
            echo '<td><input class="unverified-select" type="checkbox" name="tokens[]" value="'
                . catalog_h($token) . '" aria-label="Select ' . catalog_h((string)$item['original_name']) . '"'
                . ($exists ? '' : ' disabled') . '></td>';
            echo '<td><strong>' . catalog_h((string)$queueGame['name']) . '</strong>'
                . '<small class="muted">' . catalog_h((string)$queueGame['slug']) . '/unverified</small><br>'
                . ($exists ? '<span class="uv-badge good">Present</span>' : '<span class="uv-badge bad">Missing physical file</span>')
                . '</td>';
            echo '<td class="uv-file"><strong><a href="' . catalog_h($detailsUrl) . '">'
                . catalog_h((string)$item['original_name']) . '</a></strong>'
                . '<span>Package: <span class="mono">' . catalog_h((string)$item['package_name']) . '</span></span>'
                . '<small>Queue name: ' . catalog_h($queueName) . '</small></td>';
            echo '<td><span class="mono small">MD5: ' . catalog_h((string)$item['md5']) . '</span><br>'
                . '<span class="mono small">GUID: ' . catalog_h((string)($item['package_guid'] ?? '')) . '</span></td>';
            echo '<td><span class="uv-badge good">Indexed</span>'
                . '<div><a href="' . catalog_h($detailsUrl) . '">DB file #' . (int)$item['id'] . '</a></div>'
                . '<small>Game assignment: none (NULL)</small><small>'
                . (int)$item['name_count'] . ' / ' . (int)$item['import_count'] . ' / '
                . (int)$item['export_count'] . ' N/I/E</small></td>';
            echo '<td class="mono">' . catalog_h(uv_list_detected($item)) . '</td>'
                . '<td>' . catalog_h(catalog_bytes((int)$item['file_size'])) . '</td>'
                . '<td>' . uv_list_possible_games($possibleGames) . '</td></tr>';

            if (trim((string)($item['unverified_reason'] ?? '')) !== '') {
                echo '<tr class="uv-note-row"><td></td><td colspan="7"><div class="uv-note">'
                    . '<strong>Queue note</strong><br>'
                    . nl2br(catalog_h((string)$item['unverified_reason']))
                    . '</div></td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    echo '</form>' . $pagination;
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Unverified Files Error');
    echo CatalogUi::alert('danger', 'Unverified Files could not be loaded.', $error->getMessage());
    catalog_foot();
}
