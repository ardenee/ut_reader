<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogUnverifiedIndex.php';
require_once __DIR__ . '/lib/CatalogUnverifiedGameMatches.php';

function ufd_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : max(0, (int)$value);
}

function ufd_tab(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['names', 'imports', 'exports'], true) ? $value : 'names';
}

function ufd_assessment(array $row): array
{
    return match ((string)($row['assessment'] ?? '')) {
        'likely' => ['Likely usable', 'good'],
        'possible' => ['Possible match', 'warn'],
        'package_only' => ['Package-name only', 'warn'],
        'compatible' => ['Profile compatible', 'info'],
        'conflict' => ['Evidence conflicts', 'bad'],
        default => ['Not compatible', 'bad'],
    };
}

function ufd_match_text(array $row): string
{
    $imports = (int)($row['import_count'] ?? 0);
    if ($imports < 1) {
        return 'No package references';
    }
    $rate = $row['match_percent'] === null
        ? ''
        : rtrim(rtrim(number_format((float)$row['match_percent'], 1), '0'), '.') . '%';
    return (int)$row['exact_object_matches'] . ' / ' . $imports . ' exact' . ($rate !== '' ? ' (' . $rate . ')' : '');
}

function ufd_page_url(int $id, string $tab, int $page): string
{
    return 'unverified-file-details.php?' . http_build_query(['id' => $id, 'tab' => $tab, 'table_page' => $page]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Unverified File Details')) {
        exit;
    }
    catalog_unverified_schema_ensure($db);

    $id = ufd_int('id');
    $tab = ufd_tab((string)($_GET['tab'] ?? ''));
    $page = max(1, ufd_int('table_page', 1));
    $limit = 250;

    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1', [$id]);
    if (!$file) {
        throw new RuntimeException('Unverified staging row not found.');
    }

    $queueName = (string)($file['unverified_queue_name'] ?? '');
    $queueGameId = (int)($file['unverified_queue_game_id'] ?? 0);
    $queueLabel = 'Upload Bucket';
    if ($queueGameId > 0) {
        $queueGame = catalog_one($db, 'SELECT name FROM ue_games WHERE id=?', [$queueGameId]);
        $queueLabel = (string)($queueGame['name'] ?? ('Game #' . $queueGameId));
    }

    $matches = catalog_unverified_game_matches_v2($db, $id);
    $possible = array_values(array_filter($matches, static fn(array $row): bool => (int)$row['rank'] <= 4));
    $best = $possible[0] ?? null;

    $tableName = match ($tab) {
        'imports' => 'ue_imports',
        'exports' => 'ue_exports',
        default => 'ue_names',
    };
    $order = match ($tab) {
        'imports' => 'import_index',
        'exports' => 'export_index',
        default => 'name_index',
    };
    $rowCount = catalog_count($db, 'SELECT COUNT(*) c FROM ' . $tableName . ' WHERE file_id=?', [$id]);
    $pages = max(1, (int)ceil($rowCount / $limit));
    $page = min($page, $pages);
    $rows = catalog_all(
        $db,
        'SELECT * FROM ' . $tableName . ' WHERE file_id=? ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
        [$id]
    );

    catalog_head('Unverified ' . (string)$file['package_name']);
    echo <<<'CSS'
<style>
.ufd-hero{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.8fr);gap:14px}.ufd-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}.ufd-stat{padding:10px;border:1px solid var(--line2);border-radius:8px}.ufd-stat strong{display:block;font-size:17px}.ufd-stat span{display:block;color:var(--muted);font-size:12px}.ufd-decision{padding:13px;border:1px solid var(--line2);border-radius:9px;background:rgba(72,132,255,.08)}.ufd-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.ufd-badge.good{color:#b8f3cb;background:rgba(67,190,110,.15)}.ufd-badge.warn{color:#ffe1a0;background:rgba(246,196,83,.14)}.ufd-badge.info{color:#b8d7ff;background:rgba(72,132,255,.15)}.ufd-badge.bad{color:#ffb5b5;background:rgba(230,78,78,.14)}.ufd-games{min-width:1050px}.ufd-games td{vertical-align:top}.ufd-games small{display:block;color:var(--muted)}.ufd-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}.ufd-tabs a{padding:7px 10px;border:1px solid var(--line2);border-radius:8px}.ufd-tabs a.active{background:rgba(72,132,255,.18);border-color:var(--blue)}.ufd-pagination{display:flex;justify-content:space-between;align-items:center;margin:10px 0}.ufd-table{min-width:1100px}.ufd-table td{vertical-align:top}.ufd-path{overflow-wrap:anywhere}.ufd-note{white-space:pre-wrap}.mono-block{display:block;font-family:Consolas,ui-monospace,monospace;overflow-wrap:anywhere}@media(max-width:1000px){.ufd-hero{grid-template-columns:1fr}.ufd-grid{grid-template-columns:repeat(3,1fr)}}
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Unverified: ' . (string)$file['original_name'],
        'Database-staged package tables. This row is not assigned to a game and is hidden from verified game listings.',
        ['Back to Unverified Files' => 'unverified-files.php']
    );

    echo '<div class="ufd-hero">';
    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Staging identity</h2></div></div><div class="ui-section__body">';
    echo '<table>';
    echo '<tr><th>Database ID</th><td>' . $id . '</td></tr>';
    echo '<tr><th>Package</th><td class="mono">' . catalog_h((string)$file['package_name']) . '</td></tr>';
    echo '<tr><th>Filename</th><td class="mono">' . catalog_h((string)$file['original_name']) . '</td></tr>';
    echo '<tr><th>Physical queue</th><td>' . catalog_h($queueLabel) . '<span class="mono-block">' . catalog_h($queueName) . '</span></td></tr>';
    echo '<tr><th>MD5</th><td class="mono">' . catalog_h((string)$file['md5']) . '</td></tr>';
    echo '<tr><th>SHA1</th><td class="mono">' . catalog_h((string)$file['sha1']) . '</td></tr>';
    echo '<tr><th>GUID</th><td class="mono">' . catalog_h((string)($file['package_guid'] ?? '')) . '</td></tr>';
    echo '<tr><th>Source-relative path</th><td class="mono ufd-path">' . catalog_h((string)($file['source_relative_path'] ?? '')) . '</td></tr>';
    echo '</table></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Best game evidence</h2></div></div><div class="ui-section__body">';
    if ($best) {
        [$label, $tone] = ufd_assessment($best);
        echo '<div class="ufd-decision"><span class="ufd-badge ' . catalog_h($tone) . '">' . catalog_h($label) . '</span><h2>' . catalog_h((string)$best['game_name']) . '</h2><p><strong>' . catalog_h(ufd_match_text($best)) . '</strong><br>' . (int)$best['owner_count'] . ' verified catalogue file(s) require this package.</p></div>';
    } else {
        echo CatalogUi::alert('warning', 'No catalogue-backed target', 'No configured game has compatible profile evidence or imports requiring this exact package name.');
    }
    echo '</div></section></div>';

    echo '<div class="ufd-grid">';
    $stats = [
        'Engine' => (string)($file['detected_engine_key'] ?? 'UNKNOWN'),
        'Package version' => $file['detected_package_version'] === null ? '—' : (string)$file['detected_package_version'],
        'Licensee' => $file['detected_licensee_version'] === null ? '—' : (string)$file['detected_licensee_version'],
        'Names' => (string)$file['name_count'],
        'Imports' => (string)$file['import_count'],
        'Exports' => (string)$file['export_count'],
    ];
    foreach ($stats as $label => $value) {
        echo '<div class="ufd-stat"><strong>' . catalog_h($value) . '</strong><span>' . catalog_h($label) . '</span></div>';
    }
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Ranked game targets</h2><p>Profile compatibility and exact dependency/object evidence are shown separately.</p></div></div><div class="ui-section__body">';
    if ($matches === []) {
        echo CatalogUi::emptyState('No games configured', 'No game profiles are available for comparison.');
    } else {
        echo '<div class="table-wrap"><table class="ufd-games"><thead><tr><th>Game</th><th>Assessment</th><th>Profile</th><th>Object matches</th><th>Requiring files</th><th>Compatibility checks</th></tr></thead><tbody>';
        foreach ($matches as $match) {
            [$label, $tone] = ufd_assessment($match);
            $checks = [
                'extension' => !empty($match['extension_ok']),
                'engine' => !empty($match['engine_ok']),
                'version' => !empty($match['version_ok']),
                'licensee' => !empty($match['licensee_ok']),
            ];
            $checkText = [];
            foreach ($checks as $name => $ok) {
                $checkText[] = ucfirst($name) . ': ' . ($ok ? 'OK' : 'No');
            }
            echo '<tr><td><strong><a href="game-files.php?id=' . (int)$match['game_id'] . '">' . catalog_h((string)$match['game_name']) . '</a></strong></td>';
            echo '<td><span class="ufd-badge ' . catalog_h($tone) . '">' . catalog_h($label) . '</span>' . ((string)$match['reason'] !== '' ? '<small>' . catalog_h((string)$match['reason']) . '</small>' : '') . '</td>';
            echo '<td><span class="mono">' . catalog_h((string)$match['engine_key']) . '</span><small>' . catalog_h((string)$match['profile_name']) . '</small></td>';
            echo '<td><strong>' . catalog_h(ufd_match_text($match)) . '</strong><small>' . (int)$match['unmatched_object_count'] . ' required object(s) not exported</small></td>';
            echo '<td>' . (int)$match['owner_count'] . '</td><td>' . catalog_h(implode(' · ', $checkText)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    if (trim((string)($file['unverified_reason'] ?? '')) !== '' || trim((string)($file['scan_notes'] ?? '')) !== '') {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Staging notes</h2></div></div><div class="ui-section__body">';
        if (trim((string)($file['unverified_reason'] ?? '')) !== '') {
            echo '<h3>Queue reason</h3><div class="ufd-note">' . catalog_h((string)$file['unverified_reason']) . '</div>';
        }
        if (trim((string)($file['scan_notes'] ?? '')) !== '') {
            echo '<h3>Parser notes</h3><div class="ufd-note">' . catalog_h((string)$file['scan_notes']) . '</div>';
        }
        echo '</div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Stored package tables</h2><p>' . $rowCount . ' row(s) in ' . catalog_h(ucfirst($tab)) . '.</p></div></div><div class="ui-section__body">';
    echo '<nav class="ufd-tabs">';
    foreach (['names' => (int)$file['name_count'], 'imports' => (int)$file['import_count'], 'exports' => (int)$file['export_count']] as $name => $count) {
        echo '<a class="' . ($tab === $name ? 'active' : '') . '" href="' . catalog_h(ufd_page_url($id, $name, 1)) . '">' . catalog_h(ucfirst($name)) . ' (' . $count . ')</a>';
    }
    echo '</nav>';

    echo '<div class="ufd-pagination"><span>' . ($page > 1 ? '<a class="button secondary" href="' . catalog_h(ufd_page_url($id, $tab, $page - 1)) . '">Previous</a>' : '') . '</span><span>Page ' . $page . ' of ' . $pages . '</span><span>' . ($page < $pages ? '<a class="button secondary" href="' . catalog_h(ufd_page_url($id, $tab, $page + 1)) . '">Next</a>' : '') . '</span></div>';

    if ($rows === []) {
        echo CatalogUi::emptyState('No stored rows', 'The package reader did not produce any rows for this table.');
    } else {
        echo '<div class="table-wrap"><table class="ufd-table"><thead><tr>';
        if ($tab === 'names') {
            echo '<th>#</th><th>Name</th><th>Flags</th>';
        } elseif ($tab === 'imports') {
            echo '<th>#</th><th>Class Package</th><th>Class</th><th>Object</th><th>Outer</th><th>Root Package</th><th>Relative Path</th><th>Full Path</th>';
        } else {
            echo '<th>#</th><th>Class</th><th>Object</th><th>Outer</th><th>Local Path</th><th>Full Path</th><th>Flags</th><th>Serial Size</th><th>Serial Offset</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            if ($tab === 'names') {
                echo '<td class="mono">' . (int)$row['name_index'] . '</td><td class="mono">' . catalog_h((string)$row['name_text']) . '</td><td class="mono">' . catalog_h((string)($row['flags'] ?? '')) . '</td>';
            } elseif ($tab === 'imports') {
                echo '<td class="mono">' . (int)$row['import_index'] . '</td><td class="mono">' . catalog_h((string)$row['class_package']) . '</td><td class="mono">' . catalog_h((string)$row['class_name']) . '</td><td class="mono">' . catalog_h((string)$row['object_name']) . '</td><td class="mono">' . (int)$row['outer_index'] . '</td><td class="mono">' . catalog_h((string)$row['root_package']) . '</td><td class="mono ufd-path">' . catalog_h((string)$row['relative_object_path']) . '</td><td class="mono ufd-path">' . catalog_h((string)$row['full_path']) . '</td>';
            } else {
                echo '<td class="mono">' . (int)$row['export_index'] . '</td><td class="mono ufd-path">' . catalog_h((string)$row['class_name']) . '</td><td class="mono">' . catalog_h((string)$row['object_name']) . '</td><td class="mono">' . (int)$row['outer_index'] . '</td><td class="mono ufd-path">' . catalog_h((string)$row['local_path']) . '</td><td class="mono ufd-path">' . catalog_h((string)$row['full_path']) . '</td><td class="mono">' . catalog_h((string)($row['object_flags'] ?? '')) . '</td><td class="mono">' . catalog_h((string)($row['serial_size'] ?? '')) . '</td><td class="mono">' . catalog_h((string)($row['serial_offset'] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Unverified File Error');
    echo CatalogUi::alert('danger', 'The staged file could not be opened.', $error->getMessage());
    echo '<p><a class="button" href="unverified-files.php">Back to Unverified Files</a></p>';
    catalog_foot();
}
