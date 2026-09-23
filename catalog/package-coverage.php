<?php
/**
 * Public package-coverage search and single-file contribution page.
 *
 * Coverage browsing is cache-only: requests never run the expensive catalogue
 * superset analyzer. Single-file contributions reuse the normal public-upload
 * browser inspection, indexed duplicate preflight and quarantine pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadTransferStoreFactory;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Settings\CatalogPublicUploadSettingsStore;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session(true);
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
    }

    $gameId = max(0, (int)($_GET['game_id'] ?? 0));
    $fileId = max(0, (int)($_GET['file_id'] ?? 0));
    if ($fileId > 0) {
        $sentFile = catalog_one(
            $db,
            'SELECT id,game_id,package_name,original_name FROM ue_files WHERE id=? AND scan_status="verified"',
            [$fileId]
        );
        if (!$sentFile) {
            throw new RuntimeException('The selected verified file was not found.');
        }
        $gameId = (int)$sentFile['game_id'];
        $sentPackage = trim((string)$sentFile['package_name']);
        if ($sentPackage === '') {
            $sentPackage = pathinfo((string)$sentFile['original_name'], PATHINFO_FILENAME);
        }
        header('Location: package-coverage.php?' . http_build_query([
            'game_id' => $gameId,
            'package' => $sentPackage,
            'coverage_game_id' => $gameId,
            'coverage_package' => $sentPackage,
            'selected_file_id' => $fileId,
        ]), true, 302);
        exit;
    }
    $selectedFileId = max(0, (int)($_GET['selected_file_id'] ?? 0));
    $query = trim((string)($_GET['package'] ?? ''));
    $query = substr($query, 0, 255);
    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,p.engine_key profile_engine FROM ue_games g '
        . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name'
    );
    $coverage = [];
    if ($query !== '') {
        $where = 'c.package_name LIKE ?';
        $args = ['%' . $query . '%'];
        if ($gameId > 0) {
            $where .= ' AND c.game_id=?';
            $args[] = $gameId;
        }
        $coverage = catalog_all(
            $db,
            'SELECT c.game_id,g.name game_name,gp.engine_key profile_engine,c.package_name,c.consumer_count,c.required_object_count,c.updated_at,'
            . 'COUNT(p.file_id) provider_count,'
            . 'SUM(p.fully_satisfies=1) complete_provider_count,'
            . 'MAX(p.matched_count) best_matched_count '
            . 'FROM ue_package_coverage_cache c '
            . 'JOIN ue_games g ON g.id=c.game_id '
            . 'LEFT JOIN ue_game_profiles gp ON gp.id=g.profile_id AND gp.is_active=1 '
            . 'LEFT JOIN ue_package_provider_coverage_cache p ON p.game_id=c.game_id AND p.package_name=c.package_name '
            . 'WHERE ' . $where . ' '
            . 'GROUP BY c.game_id,g.name,gp.engine_key,c.package_name,c.consumer_count,c.required_object_count,c.updated_at '
            . 'ORDER BY (c.package_name=?) DESC,c.package_name,g.name LIMIT 100',
            [...$args, $query]
        );
    }

    $detailGameId = max(0, (int)($_GET['coverage_game_id'] ?? 0));
    $detailPackage = substr(trim((string)($_GET['coverage_package'] ?? '')), 0, 255);
    $detail = null;
    $providers = [];
    if ($detailGameId > 0 && $detailPackage !== '') {
        $detail = catalog_one(
            $db,
            'SELECT c.*,g.name game_name,p.engine_key profile_engine FROM ue_package_coverage_cache c '
            . 'JOIN ue_games g ON g.id=c.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE c.game_id=? AND c.package_name=?',
            [$detailGameId, $detailPackage]
        );
        if ($detail) {
            $providers = catalog_all(
                $db,
                'SELECT p.*,f.original_name,f.file_size,f.package_guid,f.md5,f.sha1,f.scan_status '
                . 'FROM ue_package_provider_coverage_cache p JOIN ue_files f ON f.id=p.file_id '
                . 'WHERE p.game_id=? AND p.package_name=? '
                . 'ORDER BY p.fully_satisfies DESC,p.matched_count DESC,p.file_id',
                [$detailGameId, $detailPackage]
            );
        }
    }

    $settings = (new CatalogPublicUploadSettingsStore($db, $config))->settings();
    $policy = new CatalogUploadBucketFilePolicy($db, $config);
    $allowedExtensions = $policy->allowedPackageExtensions();
    sort($allowedExtensions, SORT_NATURAL | SORT_FLAG_CASE);
    $chunkBytes = CatalogBucketUploadTransferStoreFactory::effectiveChunkBytes($config);

    catalog_head('Package Coverage');
    catalog_page_header(
        'Package Coverage',
        'Find whether one known package version satisfies every object required by all known catalogue consumers. Coverage results are precomputed during Full Sync.',
        ['Contribute files' => 'public-upload.php', 'Search files' => 'index.php?page=search']
    );

    echo '<section class="card"><h2>Find a common package</h2>'
        . '<form method="get" action="package-coverage.php"><div class="ui-inline-actions">'
        . '<label>Game <select name="game_id"><option value="0">All games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ((int)$game['id'] === $gameId ? ' selected' : '') . '>'
            . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label> '
        . '<label>Package <input name="package" value="' . catalog_h($query) . '" placeholder="Foo" maxlength="255"></label> '
        . '<button type="submit">Search coverage</button></div></form>'
        . '<p class="muted small">Search reads only the persistent coverage cache; it does not recalculate package coverage.</p></section>';

    if ($query !== '') {
        echo '<section class="card"><h2>Coverage results</h2>';
        if ($coverage === []) {
            echo '<p class="muted">No cached multi-provider package coverage matched this search. The game may need a Full Sync, or the package may have only one known provider.</p>';
        } else {
            echo '<table><tr><th>Game</th><th>Package</th><th>Consumers</th><th>Required objects</th><th>Providers</th><th>Best coverage</th><th>Complete common version</th><th>Updated</th></tr>';
            foreach ($coverage as $row) {
                $required = (int)$row['required_object_count'];
                $best = (int)$row['best_matched_count'];
                $complete = (int)$row['complete_provider_count'] > 0;
                $href = 'package-coverage.php?coverage_game_id=' . (int)$row['game_id']
                    . '&coverage_package=' . rawurlencode((string)$row['package_name'])
                    . '&game_id=' . (int)$row['game_id']
                    . '&package=' . rawurlencode((string)$row['package_name']);
                echo '<tr><td>' . catalog_h((string)$row['game_name']) . '</td>'
                    . '<td class="mono"><a href="' . catalog_h($href) . '">' . catalog_h((string)$row['package_name']) . '</a></td>'
                    . '<td>' . number_format((int)$row['consumer_count']) . '</td>'
                    . '<td>' . number_format($required) . '</td>'
                    . '<td>' . number_format((int)$row['provider_count']) . '</td>'
                    . '<td>' . number_format($best) . ' / ' . number_format($required) . '</td>'
                    . '<td><span class="dep ' . ($complete ? 'resolved' : 'missing') . '">' . ($complete ? 'Yes' : 'No') . '</span></td>'
                    . '<td class="small">' . catalog_h((string)$row['updated_at']) . '</td></tr>';
            }
            echo '</table>';
        }
        echo '</section>';
    }

    if ($detail) {
        echo '<section class="card"><h2>' . catalog_h((string)$detail['package_name']) . ' · ' . catalog_h((string)$detail['game_name']) . '</h2>'
            . '<div class="grid">';
        catalog_stat_card('Known consumers', (int)$detail['consumer_count']);
        catalog_stat_card('Combined required objects', (int)$detail['required_object_count']);
        catalog_stat_card('Known competing providers', count($providers));
        catalog_stat_card('Coverage calculated', (string)$detail['updated_at']);
        echo '</div>';
        if ($providers === []) {
            echo '<p class="muted">No cached provider rows are available.</p>';
        } else {
            echo '<table><tr><th>File</th><th>Identity</th><th>Size</th><th>Catalogue coverage</th><th>Status</th><th>Missing objects</th></tr>';
            foreach ($providers as $provider) {
                $missing = json_decode((string)($provider['missing_paths_json'] ?? '[]'), true);
                $missing = is_array($missing) ? array_values(array_filter(array_map('strval', $missing))) : [];
                $complete = (int)$provider['fully_satisfies'] === 1;
                $selected = $selectedFileId > 0 && (int)$provider['file_id'] === $selectedFileId;
                echo '<tr' . ($selected ? ' class="is-reference-target"' : '') . '><td>' . ($selected ? '<strong>Selected file</strong><br>' : '') . '<a href="file-examine.php?id=' . (int)$provider['file_id'] . '">' . catalog_h(catalog_clean_unreal_filename((string)$provider['original_name'])) . '</a>'
                    . '<br><a class="small" href="file-info.php?id=' . (int)$provider['file_id'] . '">File information</a></td>'
                    . '<td>' . CatalogUi::identity((string)$provider['package_guid'], (string)$provider['md5'], (string)$provider['sha1']) . '</td>'
                    . '<td style="white-space:nowrap">' . catalog_h(catalog_bytes((int)$provider['file_size'])) . '</td>'
                    . '<td><strong>' . number_format((int)$provider['matched_count']) . ' / ' . number_format((int)$detail['required_object_count']) . '</strong></td>'
                    . '<td><span class="dep ' . ($complete ? 'resolved' : 'missing') . '">' . ($complete ? 'Complete' : 'Missing ' . (int)$provider['missing_count']) . '</span></td><td>';
                if ($missing === []) {
                    echo '<span class="muted">—</span>';
                } else {
                    echo '<details><summary>' . count($missing) . ' gap(s)</summary><div class="mono small" style="max-height:240px;overflow:auto">';
                    foreach ($missing as $path) echo catalog_h($path) . '<br>';
                    echo '</div></details>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '<p class="muted small">“Complete” means complete for the combined requirements currently known to UnrealDB; it does not claim that the file contains every object ever distributed under this package name.</p></section>';
    }

    echo '<section class="card"><h2>Check or contribute one package file</h2>';
    if (empty($settings['enabled'])) {
        echo '<p class="muted">Public contribution uploads are currently disabled.</p></section>';
    } else {
        echo '<p>Select one package. The browser performs the same extension, Unreal header/magic, redirect decoding, MD5, SHA-1 and GUID checks used by the standard public uploader. Exact catalogue duplicates are not transferred and open their existing UnrealDB file page instead. New bytes use the normal quarantine/background-validation pipeline and are added as unverified until an administrator assigns and verifies them.</p>'
            . '<form id="public-upload-form" data-allowed-extensions="' . catalog_h(json_encode($allowedExtensions, JSON_UNESCAPED_SLASHES) ?: '[]') . '">'
            . '<p><input id="public-upload-files" type="file"> <button id="public-upload-start" type="submit">Check package</button> '
            . '<button id="public-upload-stop" class="secondary" type="button" hidden disabled>Stop</button></p></form></section>';

        $workerPath = __DIR__ . '/assets/upload-file-inspector-worker-compatible.js';
        $workerDelegate = __DIR__ . '/assets/upload-file-inspector-worker.js';
        $redirectReaderPath = __DIR__ . '/assets/unreal-redirect-reader.js';
        $legacyUzDecoderPath = __DIR__ . '/assets/legacy-uz-decoder.js';
        $workerVersion = max(
            is_file($workerPath) ? (int)(filemtime($workerPath) ?: 1) : 1,
            is_file($workerDelegate) ? (int)(filemtime($workerDelegate) ?: 1) : 1,
            is_file($redirectReaderPath) ? (int)(filemtime($redirectReaderPath) ?: 1) : 1,
            is_file($legacyUzDecoderPath) ? (int)(filemtime($legacyUzDecoderPath) ?: 1) : 1
        );
        echo '<section id="public-upload-progress" class="card public-upload-progress" hidden'
            . ' data-preflight-url="api/v1/public-upload-preflight.php" data-upload-url="api/v1/public-upload.php"'
            . ' data-worker-url="assets/upload-file-inspector-worker-compatible.js?v=' . $workerVersion . '"'
            . ' data-csrf="' . catalog_h(catalog_csrf('public_upload')) . '"'
            . ' data-feedback-url="feedback.php" data-feedback-csrf="' . catalog_h(catalog_csrf('public_feedback')) . '"'
            . ' data-chunk-bytes="' . (int)$chunkBytes . '" data-max-file-bytes="' . (int)$settings['max_file_bytes'] . '"'
            . ' data-duplicate-redirect="1" data-single-file="1">'
            . '<h2>Package check</h2><p id="public-upload-progress-label">Waiting to start.</p>'
            . '<progress id="public-upload-progress-bar" value="0" max="100"></progress>'
            . '<p id="public-upload-summary" class="muted">0 checked</p>'
            . '<div id="public-upload-log" class="public-upload-log" role="log"></div>'
            . '<div class="public-upload-log-actions"><button id="public-upload-export-log" type="button" class="secondary" disabled>Export troubleshooting log</button>'
            . '<button id="public-upload-submit-log" type="button" class="secondary" disabled>Submit error log</button></div></section>';
        echo '<style>.public-upload-progress progress{width:100%;height:18px}.public-upload-log{max-height:260px;overflow:auto;background:rgba(0,0,0,.18);border:1px solid var(--line);border-radius:6px;padding:8px;font:12px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}.public-upload-log-line{overflow-wrap:anywhere;padding:2px 0}.public-upload-log-uploaded,.public-upload-log-accepted{color:#a7f3d0}.public-upload-log-rejected,.public-upload-log-failed{color:#fecdd3}.public-upload-log-skipped{color:#bfdbfe}.public-upload-log-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}</style>';
        $script = __DIR__ . '/assets/public-upload.js';
        $version = is_file($script) ? (string)(filemtime($script) ?: 1) : '1';
        echo '<script src="assets/public-upload.js?v=' . catalog_h($version) . '"></script>';
    }

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB][' . catalog_request_id() . '] package coverage page failed: ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) catalog_head('Package Coverage');
    echo CatalogUi::alert('danger', 'Package coverage is temporarily unavailable. Reference: ' . catalog_request_id(), 'Package coverage unavailable');
    catalog_foot();
}
