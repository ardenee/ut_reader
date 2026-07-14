<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogAssetMetadata.php';

const ASSET_METADATA_DEFAULT_BATCH_FILES = 950;
const ASSET_METADATA_MAX_BATCH_FILES = 950;

function asset_metadata_int_post(string $key, int $default = 0): int
{
    return max(0, (int)($_POST[$key] ?? $default));
}

function asset_metadata_batch_size(): int
{
    $requested = asset_metadata_int_post('batch_size', ASSET_METADATA_DEFAULT_BATCH_FILES);
    if ($requested <= 0) {
        $requested = ASSET_METADATA_DEFAULT_BATCH_FILES;
    }
    return max(1, min(ASSET_METADATA_MAX_BATCH_FILES, $requested));
}

function asset_metadata_totals_text(array $totals): string
{
    return 'Asset rows=' . (int)$totals['assets']
        . ', string asset references=' . (int)$totals['string_asset_refs']
        . ', preload dependencies=' . (int)$totals['preload_deps']
        . ', soft-reference candidates=' . (int)$totals['soft_refs']
        . ', unparsed redirectors=' . (int)$totals['redirectors'] . '.';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Asset Metadata Rebuild')) {
        exit;
    }

    catalog_dependency_schema_ensure($db);
    $games = catalog_all($db, 'SELECT id, name FROM ue_games ORDER BY name');
    $result = null;
    $continueForm = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('asset_metadata_rebuild');
        $fileId = asset_metadata_int_post('file_id');
        $gameId = asset_metadata_int_post('game_id');
        $offset = asset_metadata_int_post('offset');
        $batchSize = asset_metadata_batch_size();
        $totals = ['assets' => 0, 'string_asset_refs' => 0, 'preload_deps' => 0, 'soft_refs' => 0, 'redirectors' => 0];
        $processed = 0;
        $totalFiles = 0;
        $nextOffset = $offset;

        if ($fileId > 0) {
            $stats = catalog_asset_metadata_rebuild_file($db, $config, $fileId);
            $processed = 1;
            foreach ($totals as $key => $_) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }
            $result = 'Processed single file ID ' . $fileId . '. ' . asset_metadata_totals_text($totals);
        } elseif ($gameId > 0) {
            $countRow = catalog_one($db, 'SELECT COUNT(*) c FROM ue_files WHERE game_id=? AND scan_status="verified"', [$gameId]);
            $totalFiles = (int)($countRow['c'] ?? 0);

            $files = catalog_all(
                $db,
                'SELECT id, package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name, id LIMIT ' . $batchSize . ' OFFSET ' . $offset,
                [$gameId]
            );

            foreach ($files as $file) {
                $stats = catalog_asset_metadata_rebuild_file($db, $config, (int)$file['id']);
                $processed++;
                foreach ($totals as $key => $_) {
                    $totals[$key] += (int)($stats[$key] ?? 0);
                }
            }

            $nextOffset = $offset + $processed;
            $remaining = max(0, $totalFiles - $nextOffset);
            $result = 'Processed batch ' . ($offset + 1) . '–' . $nextOffset . ' of ' . $totalFiles . ' verified file(s). '
                . asset_metadata_totals_text($totals)
                . ($remaining > 0 ? ' Remaining=' . $remaining . '.' : ' All files in this game have been processed.');

            if ($remaining > 0) {
                $continueForm = [
                    'game_id' => $gameId,
                    'offset' => $nextOffset,
                    'batch_size' => $batchSize,
                    'remaining' => $remaining,
                ];
            }
        } else {
            throw new RuntimeException('Choose a file ID or a game.');
        }
    }

    catalog_head('Asset Metadata Rebuild');
    catalog_page_header(
        'Asset Metadata Rebuild',
        'Rebuilds explicit UE asset metadata rows from verified export tables and records parsed UE4 summary references plus conservative byte-scan candidates. Game rebuilds run in resumable batches so large catalogs do not stop at the first page.',
        ['Dashboard' => 'dashboard.php']
    );

    if ($result !== null) {
        echo '<div class="card"><p>' . catalog_h($result) . '</p>';
        if ($continueForm !== null) {
            echo '<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('asset_metadata_rebuild')) . '">';
            echo '<input type="hidden" name="game_id" value="' . (int)$continueForm['game_id'] . '">';
            echo '<input type="hidden" name="offset" value="' . (int)$continueForm['offset'] . '">';
            echo '<input type="hidden" name="batch_size" value="' . (int)$continueForm['batch_size'] . '">';
            echo '<p><button>Continue next batch from offset ' . (int)$continueForm['offset'] . '</button> <span class="muted">Remaining: ' . (int)$continueForm['remaining'] . '</span></p>';
            echo '</form>';
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Rebuild metadata</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('asset_metadata_rebuild')) . '">';
    echo '<p><label>Single file ID<br><input type="number" name="file_id" min="1" placeholder="optional"></label></p>';
    echo '<p><label>Or rebuild game in batches<br><select name="game_id"><option value="0">Select game...</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Start offset<br><input type="number" name="offset" min="0" value="0"></label> <span class="muted">Use 950 to continue after the old first-950 run.</span></p>';
    echo '<p><label>Batch size<br><input type="number" name="batch_size" min="1" max="' . ASSET_METADATA_MAX_BATCH_FILES . '" value="' . ASSET_METADATA_DEFAULT_BATCH_FILES . '"></label> <span class="muted">Maximum ' . ASSET_METADATA_MAX_BATCH_FILES . ' per request.</span></p>';
    echo '<p><button>Rebuild asset metadata batch</button></p></form></div>';

    echo '<div class="card"><h2>Notes</h2><p class="muted">Game batches use the stable order package name, then file ID. After a batch finishes, use the Continue button until it reports all files processed. ObjectRedirector rows are recorded as unparsed metadata until serialized export-property decoding can prove the target. They are not treated as package aliases and never use folder/object-name similarity.</p></div>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Asset Metadata Rebuild error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}