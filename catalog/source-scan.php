<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogSourceScanNoContainers.php';

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Source scan')) {
        exit;
    }

    catalog_head('Source scan');
    catalog_page_header(
        'Source scanner',
        'Recursively scan game-owned folders. Unchanged files reuse the source fingerprint cache; changed and new files still receive full package verification.',
        [
            'Game Sources' => 'sources.php',
            'HTTP Source Scan' => 'http-source-scan.php',
            'Upload Files' => 'profiled-upload.php',
            'Unverified Files' => 'unverified-files.php',
        ]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('source_scan');
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $result = catalog_source_scan_run_without_containers(
            $db,
            $config,
            (int)($_POST['source_id'] ?? 0),
            (string)($_POST['import_unknown'] ?? '0') === '1',
            (string)($_POST['strict_profile'] ?? '1') === '1',
            $userId
        );

        echo '<div class="card"><h2>Scan result</h2><table>';
        echo '<tr><th>Source</th><td>' . catalog_h((string)$result['source']['name']) . '</td></tr>';
        echo '<tr><th>Game</th><td>' . catalog_h((string)$result['source']['game_name']) . '</td></tr>';
        $labels = [
            'found' => 'Package-like files found',
            'fingerprint_hits' => 'Unchanged files reused from cache',
            'cached_hashes' => 'Cached full hashes reused',
            'fingerprints_written' => 'Fingerprint rows updated',
            'fingerprint_errors' => 'Fingerprint cache errors',
            'redirect_archives' => 'Redirect archives decompressed',
            'redirect_cache_hits' => 'Redirect archives reused without decompression',
            'matched_md5' => 'Matched by MD5',
            'matched_guid' => 'Matched by GUID',
            'guid_ambiguous' => 'Ambiguous GUID matches',
            'parse_failed' => 'Parse failed',
            'unknown' => 'Unknown / not cataloged',
            'imported' => 'Imported by profiled scanner',
            'duplicates' => 'Duplicate imports',
            'import_failed' => 'Profiled import failed',
            'staged_unverified' => 'Moved to unverified staging',
            'locations' => 'Locations recorded',
            'containers_skipped' => 'PAK containers skipped on this package pass',
        ];
        foreach ($labels as $key => $label) {
            echo '<tr><th>' . catalog_h($label) . '</th><td>' . (int)($result[$key] ?? 0) . '</td></tr>';
        }
        echo '</table>';
        if (empty($result['fingerprint_cache_available'])) {
            echo '<p class="muted">The fingerprint cache is unavailable until migration 202607270008 is applied. The scan completed using full verification.</p>';
        }
        echo '</div>';

        foreach ([
            'import_samples' => 'Profiled import samples',
            'unknown_samples' => 'Unknown / ambiguous samples',
            'parse_failed_samples' => 'Parse failed samples',
        ] as $key => $title) {
            if (empty($result[$key])) {
                continue;
            }
            echo '<div class="card"><h2>' . catalog_h($title) . '</h2><table><tr><th>Path / result</th></tr>';
            foreach ($result[$key] as $sample) {
                echo '<tr><td class="mono path">' . catalog_h((string)$sample) . '</td></tr>';
            }
            echo '</table></div>';
        }
    }

    $sources = catalog_all(
        $db,
        'SELECT s.*,g.name game_name,p.engine_key profile_engine '
        . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
        . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
        . 'WHERE s.is_active=1 ORDER BY g.name,s.name'
    );
    echo '<div class="card"><h2>Run scan</h2>';
    if (!$sources) {
        echo '<p class="muted">No sources configured. Add one in <a href="sources.php">Game Sources</a>.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('source_scan')) . '">';
        echo '<p><label>Source<br><select name="source_id">';
        foreach ($sources as $source) {
            echo '<option value="' . (int)$source['id'] . '">'
                . catalog_h((string)$source['game_name'] . ' / ' . ((string)($source['profile_engine'] ?: 'no profile')) . ' - ' . (string)$source['name'])
                . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="import_unknown" value="1"> Import unknown files and stage failed valid packages</label></p>';
        echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject mismatches</option><option value="0">Loose: use detected reader</option></select></label></p>';
        echo '<p><button type="submit">Run source scan</button></p></form>';
        echo '<p class="muted">UE4/UE5 PAK containers are queued by the background Source Scan job. This direct package pass skips PAK files.</p>';
    }
    echo '</div>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Source scan error');
    }
    echo '<div class="card"><h1>Source scan error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
