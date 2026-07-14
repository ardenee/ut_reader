<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogAssetMetadata.php';

const ASSET_METADATA_MAX_GAME_FILES = 950;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Asset Metadata Rebuild')) {
        exit;
    }

    catalog_dependency_schema_ensure($db);
    $games = catalog_all($db, 'SELECT id, name FROM ue_games ORDER BY name');
    $result = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('asset_metadata_rebuild');
        $fileId = max(0, (int)($_POST['file_id'] ?? 0));
        $gameId = max(0, (int)($_POST['game_id'] ?? 0));
        $totals = ['assets' => 0, 'string_asset_refs' => 0, 'preload_deps' => 0, 'soft_refs' => 0, 'redirectors' => 0];
        $processed = 0;

        if ($fileId > 0) {
            $stats = catalog_asset_metadata_rebuild_file($db, $config, $fileId);
            $processed = 1;
            foreach ($totals as $key => $_) {
                $totals[$key] += (int)($stats[$key] ?? 0);
            }
        } elseif ($gameId > 0) {
            $files = catalog_all($db, 'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name, id LIMIT ' . ASSET_METADATA_MAX_GAME_FILES, [$gameId]);
            foreach ($files as $file) {
                $stats = catalog_asset_metadata_rebuild_file($db, $config, (int)$file['id']);
                $processed++;
                foreach ($totals as $key => $_) {
                    $totals[$key] += (int)($stats[$key] ?? 0);
                }
            }
        } else {
            throw new RuntimeException('Choose a file ID or a game.');
        }

        $result = 'Processed ' . $processed . ' file(s). Asset rows=' . $totals['assets']
            . ', string asset references=' . $totals['string_asset_refs']
            . ', preload dependencies=' . $totals['preload_deps']
            . ', soft-reference candidates=' . $totals['soft_refs']
            . ', unparsed redirectors=' . $totals['redirectors'] . '.';
    }

    catalog_head('Asset Metadata Rebuild');
    catalog_page_header('Asset Metadata Rebuild', 'Rebuilds explicit UE asset metadata rows from verified export tables and records parsed UE4 summary references plus conservative byte-scan candidates. These rows are diagnostic/source metadata; they do not create same-folder dependency guesses.', ['Dashboard' => 'dashboard.php']);
    if ($result !== null) {
        echo '<div class="card"><p>' . catalog_h($result) . '</p></div>';
    }

    echo '<div class="card"><h2>Rebuild metadata</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('asset_metadata_rebuild')) . '">';
    echo '<p><label>Single file ID<br><input type="number" name="file_id" min="1" placeholder="optional"></label></p>';
    echo '<p><label>Or rebuild first ' . ASSET_METADATA_MAX_GAME_FILES . ' verified files in game<br><select name="game_id"><option value="0">Select game...</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label></p><p><button>Rebuild asset metadata</button></p></form></div>';

    echo '<div class="card"><h2>Notes</h2><p class="muted">ObjectRedirector rows are recorded as unparsed metadata until serialized export-property decoding can prove the target. They are not treated as package aliases and never use folder/object-name similarity.</p></div>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Asset Metadata Rebuild error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
