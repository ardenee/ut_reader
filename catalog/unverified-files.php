<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the paginated Unverified Files administration page.
 * Why: Presentation parses filters and renders rows; database/queue hydration belongs to the page query.
 * Role: Web UI entry point over PdoUnverifiedFilesPageQuery.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedFilesPageQuery;

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

/** @param list<array<string,mixed>> $matches @param array<string,mixed> $state */
function uv_list_possible_games(array $matches, array $state = []): string
{
    $status = strtolower(trim((string)($state['status'] ?? 'missing'))) ?: 'missing';
    $calculatedAt = trim((string)($state['calculated_at'] ?? ''));
    $lastError = trim((string)($state['last_error'] ?? ''));
    $prefix = '';

    if ($status === 'missing') {
        return '<div class="uv-cache-state pending"><strong>Not calculated</strong>'
            . '<small>Exact dependency evidence is not cached yet. Use Refresh bucket matches.</small></div>';
    }
    if ($status === 'pending') {
        $prefix = '<div class="uv-cache-state pending"><strong>Background refresh queued</strong><small>'
            . ($calculatedAt !== ''
                ? 'Showing the previous cache from ' . catalog_h($calculatedAt) . ' UTC while it refreshes.'
                : 'Exact dependency evidence is being calculated in the background.')
            . '</small></div>';
        if ($matches === []) {
            return $prefix;
        }
    } elseif ($status === 'failed') {
        $prefix = '<div class="uv-cache-state failed"><strong>Last refresh failed</strong><small>'
            . catalog_h($lastError !== '' ? $lastError : 'Unknown match calculation error.')
            . ($calculatedAt !== '' ? ' · ' . catalog_h($calculatedAt) . ' UTC' : '')
            . '</small></div>';
        if ($matches === []) {
            return $prefix;
        }
    } elseif ($calculatedAt !== '') {
        $prefix = '<small class="uv-cache-time">Cached ' . catalog_h($calculatedAt) . ' UTC</small>';
    }

    if ($matches === []) {
        return $prefix . '<span class="muted">No verified files currently reference this package.</span>';
    }

    $html = '<div class="uv-game-links">';
    $shown = 0;
    foreach ($matches as $match) {
        $references = max(0, (int)($match['import_count'] ?? 0));
        if ($references < 1) {
            continue;
        }
        $shown++;
        $owners = max(0, (int)($match['owner_count'] ?? 0));
        $exact = max(0, (int)($match['exact_object_matches'] ?? 0));
        $compatible = !empty($match['compatible']);
        $matchPercent = $match['match_percent'] !== null
            ? number_format((float)$match['match_percent'], 1) . '%'
            : '0.0%';
        $engine = trim((string)($match['engine_key'] ?? ''));
        $compatibilityLabel = trim((string)($match['compatibility_label'] ?? ''));

        if ($compatible && $exact > 0) {
            $badgeClass = 'good';
            $badgeText = 'Exact compatible';
        } elseif ($exact > 0) {
            $badgeClass = 'bad';
            $badgeText = 'Exact objects / profile conflict';
        } else {
            $badgeClass = 'neutral';
            $badgeText = 'Package name only';
        }

        $html .= '<div class="uv-game-evidence">'
            . '<a href="game-files.php?id=' . (int)$match['game_id'] . '"><strong>'
            . catalog_h((string)$match['game_name']) . '</strong></a>'
            . '<span class="uv-evidence-badge ' . $badgeClass . '">' . catalog_h($badgeText) . '</span>';

        if ($exact > 0) {
            $html .= '<small><strong>' . number_format($exact) . ' / ' . number_format($references)
                . '</strong> dependency references match an exported object path (' . catalog_h($matchPercent) . ').</small>';
        } else {
            $html .= '<small><strong>0 / ' . number_format($references)
                . '</strong> required object paths matched; package-name reference only.</small>';
        }

        $html .= '<small>' . number_format($references) . ' dependency reference'
            . ($references === 1 ? '' : 's') . ' from ' . number_format($owners) . ' verified file'
            . ($owners === 1 ? '' : 's') . '.</small>';

        $profileText = $engine !== '' ? $engine : 'profile unknown';
        if ($compatibilityLabel !== '') {
            $profileText .= ' · ' . $compatibilityLabel;
        }
        if (!$compatible && trim((string)($match['reason'] ?? '')) !== '') {
            $profileText .= ' · ' . (string)$match['reason'];
        }
        $html .= '<small class="muted">' . catalog_h($profileText) . '</small></div>';
    }

    if ($shown === 0) {
        return $prefix . '<span class="muted">No verified files currently reference this package.</span>';
    }
    return $prefix . $html . '</div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Unverified Files')) {
        exit;
    }

    $sourceGameId = uv_list_int('source_game_id', 0);
    $extension = strtolower(uv_list_text('extension'));
    $engine = strtoupper(uv_list_text('engine'));
    $version = uv_list_text('version');
    $licensee = uv_list_text('licensee');
    $requestedPage = max(1, uv_list_int('queue_page', 1));
    $requestedLimit = max(50, min(1000, uv_list_int('limit', 100)));
    $matchRefreshJobId = max(0, uv_list_int('match_refresh_job', 0));
    $matchRefreshQueued = uv_list_text('match_refresh') === 'queued';
    $matchRefreshWorkerManual = uv_list_text('match_refresh_worker') === 'manual';

    $model = (new PdoUnverifiedFilesPageQuery($db, $config))->fetch(
        $sourceGameId,
        $extension,
        $engine,
        $version,
        $licensee,
        $requestedPage,
        $requestedLimit
    );
    $games = $model['games'];
    $total = (int)$model['total'];
    $pages = (int)$model['pages'];
    $page = (int)$model['page'];
    $limit = (int)$model['limit'];
    $items = $model['items'];
    $gameMatches = is_array($model['game_matches'] ?? null) ? $model['game_matches'] : [];
    $gameMatchStates = is_array($model['game_match_states'] ?? null) ? $model['game_match_states'] : [];
    $matchCacheSummary = is_array($model['match_cache_summary'] ?? null)
        ? $model['match_cache_summary']
        : ['ready' => 0, 'pending' => 0, 'failed' => 0, 'missing' => 0, 'total' => 0];
    $summary = $model['summary'];
    $publicUploads = is_array($model['public_uploads'] ?? null) ? $model['public_uploads'] : [];
    $extensionOptions = $model['extension_options'];
    $engineOptions = $model['engine_options'];

    catalog_head('Unverified Files');
    echo <<<'CSS'
<style>
.uv-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.uv-controls{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:8px;align-items:end}.uv-controls label{display:flex;flex-direction:column;gap:4px}.uv-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}.uv-table{min-width:1450px}.uv-table td{vertical-align:top}.uv-file strong{display:block}.uv-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.uv-badge.good{color:#b8f3cb;background:rgba(67,190,110,.15)}.uv-badge.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}.uv-game-links{display:grid;gap:9px;min-width:290px}.uv-game-evidence{padding-bottom:8px;border-bottom:1px solid var(--line2)}.uv-game-evidence:last-child{padding-bottom:0;border-bottom:0}.uv-game-evidence small{display:block;color:var(--muted);margin-top:2px}.uv-evidence-badge{display:inline-flex;margin-left:7px;padding:2px 6px;border-radius:999px;font-size:10px;font-weight:700;vertical-align:1px}.uv-evidence-badge.good{color:#b8f3cb;background:rgba(67,190,110,.15)}.uv-evidence-badge.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}.uv-evidence-badge.neutral{color:#f5d98b;background:rgba(246,196,83,.13)}.uv-note-row td{padding-top:0;border-top:0}.uv-note{padding:7px 10px;border-left:3px solid #f6c453;color:var(--muted)}.uv-note.bad{border-left-color:#e64e4e;background:rgba(230,78,78,.06)}.uv-pagination{display:flex;justify-content:space-between;align-items:center;margin:10px 0}.uv-evidence-help{margin:0 0 10px;padding:9px 11px;border:1px solid var(--line2);border-radius:8px;color:var(--muted);background:rgba(255,255,255,.025)}.uv-cache-panel{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.uv-cache-panel p{margin:3px 0;color:var(--muted)}.uv-cache-state{margin:0 0 7px;padding:6px 8px;border-left:3px solid #f6c453;background:rgba(246,196,83,.06)}.uv-cache-state.failed{border-left-color:#e64e4e;background:rgba(230,78,78,.06)}.uv-cache-state strong,.uv-cache-state small,.uv-cache-time{display:block}.uv-cache-state small,.uv-cache-time{color:var(--muted);font-size:11px}.uv-cache-time{margin-bottom:6px}@media(max-width:1100px){.uv-controls{grid-template-columns:repeat(3,1fr)}.uv-summary{grid-template-columns:repeat(2,1fr)}}
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Unverified Files',
        'Game suggestions are cached dependency evidence, not MD5/GUID identity matches. Exact means the staged package exports the full object path required by verified files in that game; Package name only means only the required package name matched.',
        [
            'Cross-examine games' => 'dependency-cross-examine.php',
            'Index existing queue files' => 'unverified-database-import.php',
            'Upload bucket' => 'upload-bucket-v2.php',
            'Upload to game' => 'profiled-upload.php',
        ]
    );

    if ($matchRefreshQueued && $matchRefreshJobId > 0) {
        echo CatalogUi::alert(
            $matchRefreshWorkerManual ? 'warning' : 'success',
            'Upload Bucket match refresh queued.',
            'Background job #' . $matchRefreshJobId . ' will rebuild the cached exact dependency evidence.'
                . ($matchRefreshWorkerManual ? ' The worker could not be started automatically; start it from Background Jobs.' : '')
        );
    }

    echo '<div class="uv-summary">'
        . '<div class="stat"><h2>' . (int)($summary['indexed_count'] ?? 0) . '</h2><p>Indexed unverified files</p></div>'
        . '<div class="stat"><h2>' . $total . '</h2><p>Matching current filters</p></div>'
        . '<div class="stat"><h2>' . (int)($summary['bucket_count'] ?? 0) . '</h2><p>Upload Bucket files</p></div>'
        . '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)($summary['indexed_bytes'] ?? 0))) . '</h2><p>Indexed queue storage</p></div>'
        . '</div>';

    if ($publicUploads !== []) {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Public contribution status</h2>'
            . '<p>Recent public uploads that have not yet become normal Unverified Files rows.</p></div></div>'
            . '<div class="ui-section__body"><div class="table-wrap"><table><thead><tr>'
            . '<th>Contribution</th><th>Status</th><th>Background job</th><th>Result</th><th>Updated</th>'
            . '</tr></thead><tbody>';
        foreach ($publicUploads as $publicUpload) {
            $status = strtolower(trim((string)($publicUpload['status'] ?? '')));
            $jobId = max(0, (int)($publicUpload['background_job_id'] ?? 0));
            $resultFileId = max(0, (int)($publicUpload['unverified_file_id'] ?? 0));
            $name = trim((string)($publicUpload['relative_path'] ?? ''));
            if ($name === '') {
                $name = trim((string)($publicUpload['original_name'] ?? ''));
            }
            $badge = $status === 'failed' ? 'bad' : 'neutral';
            echo '<tr><td><strong>' . catalog_h($name !== '' ? $name : 'Public upload #' . (int)$publicUpload['id']) . '</strong>'
                . '<small class="muted">Public upload #' . (int)$publicUpload['id'] . '</small></td>'
                . '<td><span class="uv-badge ' . $badge . '">' . catalog_h(strtoupper($status)) . '</span></td>'
                . '<td>' . ($jobId > 0
                    ? '<a href="background-jobs.php">Job #' . $jobId . '</a>'
                    : '<span class="muted">Not queued yet</span>') . '</td>'
                . '<td>' . catalog_h((string)($publicUpload['result_message'] ?? ''))
                . ($resultFileId > 0
                    ? '<br><a href="file-info.php?id=' . $resultFileId . '">File #' . $resultFileId . '</a>'
                    : '')
                . '</td>'
                . '<td class="mono small">' . catalog_h((string)($publicUpload['updated_at'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div></div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__body"><div class="uv-cache-panel"><div>'
        . '<h2>Dependency evidence cache</h2><p>Upload Bucket: '
        . number_format((int)($matchCacheSummary['ready'] ?? 0)) . ' ready · '
        . number_format((int)($matchCacheSummary['pending'] ?? 0)) . ' pending · '
        . number_format((int)($matchCacheSummary['failed'] ?? 0)) . ' failed/unavailable · '
        . number_format((int)($matchCacheSummary['missing'] ?? 0)) . ' not calculated.</p>'
        . '<p>New bucket files are calculated automatically in the background. Refresh rebuilds all current bucket entries when dependency data may have changed.</p></div>'
        . '<form method="post" action="unverified-game-matches-refresh.php">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('unverified-files')) . '">'
        . '<button type="submit" class="secondary">Refresh bucket matches</button></form></div></div></section>';

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
    unset($query['match_refresh'], $query['match_refresh_job'], $query['match_refresh_worker']);
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
    echo '<div class="uv-actions"><label>Target game <select name="target_game_id"><option value="">Choose game</option>'
        . '<option value="-1">All exact compatible games</option>';
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
    echo '<p class="uv-evidence-help"><strong>All exact compatible games</strong> imports the first exact compatible match immediately and queues proper copies for every other game where at least one required full object path is exported by this package. Package-name-only suggestions are never auto-imported.</p>';

    if ($items === []) {
        echo CatalogUi::emptyState(
            'No indexed queued files',
            'No indexed unverified files match the selected filters. Use Index existing queue files for physical files not yet recorded in the database.'
        );
    } else {
        echo '<div class="table-wrap"><table class="uv-table"><thead><tr>'
            . '<th></th><th>Physical queue</th><th>File</th><th>Identity</th><th>Database</th>'
            . '<th>Detected</th><th>Size</th><th>Dependency evidence</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $queueGame = is_array($item['queue_game'] ?? null) ? $item['queue_game'] : [];
            $queueName = (string)($item['queue_name'] ?? '');
            $exists = !empty($item['physical_exists']);
            $token = (string)($item['queue_token'] ?? '');
            $fileId = (int)$item['id'];
            $detailsUrl = 'unverified-file-details.php?id=' . $fileId;
            $parseError = trim((string)($item['package_parse_error'] ?? ''));
            $tablesReadable = $parseError === '';
            $possibleGames = is_array($gameMatches[$fileId] ?? null)
                ? $gameMatches[$fileId]
                : [];
            $matchState = is_array($gameMatchStates[$fileId] ?? null)
                ? $gameMatchStates[$fileId]
                : ['status' => 'missing'];

            echo '<tr>';
            echo '<td><input class="unverified-select" type="checkbox" name="tokens[]" value="'
                . catalog_h($token) . '" aria-label="Select ' . catalog_h((string)$item['original_name']) . '"'
                . ($exists ? '' : ' disabled') . '></td>';
            echo '<td><strong>' . catalog_h((string)($queueGame['name'] ?? 'Unknown queue')) . '</strong>'
                . '<small class="muted">' . catalog_h((string)($queueGame['slug'] ?? 'unknown')) . '/unverified</small><br>'
                . ($exists ? '<span class="uv-badge good">Present</span>' : '<span class="uv-badge bad">Missing physical file</span>')
                . '</td>';
            $sourceRelativePath = trim((string)($item['source_relative_path'] ?? ''));
            echo '<td class="uv-file"><strong><a href="' . catalog_h($detailsUrl) . '">'
                . catalog_h((string)$item['original_name']) . '</a></strong>'
                . '<span>Package: <span class="mono">' . catalog_h((string)$item['package_name']) . '</span></span>'
                . '<small>Queue name: ' . catalog_h($queueName) . '</small>'
                . ($sourceRelativePath !== ''
                    ? '<small>Source contribution: <span class="mono">' . catalog_h($sourceRelativePath) . '</span></small>'
                    : '')
                . '</td>';
            $guid = trim((string)($item['package_guid'] ?? ''));
            echo '<td><span class="mono small">GUID: '
                . catalog_h($tablesReadable ? ($guid !== '' ? $guid : '—') : 'unavailable') . '</span><br>'
                . '<span class="mono small">MD5: ' . catalog_h((string)$item['md5']) . '</span><br>'
                . '<span class="mono small">SHA: ' . catalog_h((string)($item['sha1'] ?? '')) . '</span></td>';
            if ($tablesReadable) {
                echo '<td><span class="uv-badge good">Indexed</span>'
                    . '<div><a href="' . catalog_h($detailsUrl) . '">DB file #' . $fileId . '</a></div>'
                    . '<small>Game assignment: none (NULL)</small><small>'
                    . (int)$item['name_count'] . ' / ' . (int)$item['import_count'] . ' / '
                    . (int)$item['export_count'] . ' N/I/E</small></td>';
            } else {
                echo '<td><span class="uv-badge bad">Package tables unreadable</span>'
                    . '<div><a href="' . catalog_h($detailsUrl) . '">DB file #' . $fileId . '</a></div>'
                    . '<small>Basic identity only</small><small>N/I/E unavailable</small></td>';
            }
            $evidence = $tablesReadable
                ? uv_list_possible_games($possibleGames, $matchState)
                : '<div class="uv-cache-state failed"><strong>Unavailable</strong>'
                    . '<small>Package tables could not be read, so exact dependency evidence was not calculated.</small></div>';
            echo '<td class="mono">' . catalog_h(uv_list_detected($item)) . '</td>'
                . '<td>' . catalog_h(catalog_bytes((int)$item['file_size'])) . '</td>'
                . '<td>' . $evidence . '</td></tr>';

            if (!$tablesReadable) {
                echo '<tr class="uv-note-row"><td></td><td colspan="7"><div class="uv-note bad">'
                    . '<strong>Parser issue</strong><br>'
                    . nl2br(catalog_h($parseError))
                    . '<br><a href="' . catalog_h($detailsUrl) . '">Open file details</a>'
                    . ' · <a href="unverified-database-import.php?source_game_id=-1">Repair missing metadata</a>'
                    . '</div></td></tr>';
            }
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