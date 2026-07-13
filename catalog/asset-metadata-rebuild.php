<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogDependencySchema.php';

const ASSET_METADATA_MAX_GAME_FILES = 950;
const ASSET_METADATA_MAX_SOFT_REFS = 2000;

function asset_meta_package_path(string $packageName): string
{
    $packageName = rtrim(str_replace('\\', '/', trim($packageName)), '/');
    if ($packageName === '') {
        return '';
    }
    $pos = strrpos($packageName, '/');
    return $pos === false || $pos === 0 ? '' : substr($packageName, 0, $pos);
}

function asset_meta_extract_soft_references(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return [];
    }

    $sources = [];
    $sources[] = preg_replace('/[^\x20-\x7E]+/', "\n", $bytes) ?? '';
    $sources[] = preg_replace('/[^\x20-\x7E]+/', "\n", str_replace("\0", '', $bytes)) ?? '';

    $refs = [];
    foreach ($sources as $source) {
        if ($source === '') {
            continue;
        }
        if (preg_match_all('~/(?:Game|Engine|Script|Plugin|Plugins|[A-Za-z0-9_]+)/[A-Za-z0-9_./$+\-]+(?:\.[A-Za-z0-9_$+\-]+)?~', $source, $matches)) {
            foreach ($matches[0] as $match) {
                $value = trim((string)$match, " \t\r\n\0\x0B.,;:'\"()[]{}<>");
                if ($value === '' || strlen($value) > 1000) {
                    continue;
                }
                $refs[$value] = true;
            }
        }
    }

    return array_slice(array_keys($refs), 0, ASSET_METADATA_MAX_SOFT_REFS);
}

function asset_meta_resolve_path(array $config, array $file): ?string
{
    $relative = (string)($file['relative_path'] ?? '');
    if ($relative === '') {
        return null;
    }
    $path = realpath(__DIR__ . '/' . $relative);
    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    if (!$path || !$storageRoot || !str_starts_with($path, $storageRoot)) {
        return null;
    }
    return $path;
}

/** @return array{assets:int,soft_refs:int} */
function asset_meta_rebuild_file(PDO $db, array $config, int $fileId): array
{
    catalog_dependency_schema_ensure($db);

    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File not found: ' . $fileId);
    }

    $db->prepare('DELETE FROM ue_asset_registry_dependencies WHERE file_id=?')->execute([$fileId]);
    $db->prepare('DELETE FROM ue_asset_registry_assets WHERE file_id=?')->execute([$fileId]);

    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$fileId]);
    $insertAsset = $db->prepare('INSERT IGNORE INTO ue_asset_registry_assets(file_id,object_path,package_name,package_path,asset_name,asset_class) VALUES(?,?,?,?,?,?)');
    $assets = 0;
    $packageName = (string)$file['package_name'];
    $packagePath = asset_meta_package_path($packageName);
    foreach ($exports as $export) {
        $objectPath = trim((string)($export['full_path'] ?? ''));
        $assetName = trim((string)($export['object_name'] ?? ''));
        if ($objectPath === '' || $assetName === '') {
            continue;
        }
        $insertAsset->execute([
            $fileId,
            $objectPath,
            $packageName,
            $packagePath,
            $assetName,
            (string)($export['class_name'] ?? ''),
        ]);
        $assets += $insertAsset->rowCount() > 0 ? 1 : 0;
    }

    $path = asset_meta_resolve_path($config, $file);
    $softRefs = $path ? asset_meta_extract_soft_references($path) : [];
    $insertDep = $db->prepare('INSERT INTO ue_asset_registry_dependencies(file_id,source_asset_id,dependency_object_path,dependency_type) VALUES(?,?,?,?)');
    $softCount = 0;
    foreach ($softRefs as $ref) {
        if ($ref === $packageName || str_starts_with($ref, $packageName . '.')) {
            continue;
        }
        $insertDep->execute([$fileId, null, $ref, 'soft_reference_candidate']);
        $softCount++;
    }

    return ['assets' => $assets, 'soft_refs' => $softCount];
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('asset_metadata_rebuild');
        $fileId = max(0, (int)($_POST['file_id'] ?? 0));
        $gameId = max(0, (int)($_POST['game_id'] ?? 0));
        $totalAssets = 0;
        $totalSoftRefs = 0;
        $processed = 0;

        if ($fileId > 0) {
            $stats = asset_meta_rebuild_file($db, $config, $fileId);
            $processed = 1;
            $totalAssets = $stats['assets'];
            $totalSoftRefs = $stats['soft_refs'];
        } elseif ($gameId > 0) {
            $files = catalog_all($db, 'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name, id LIMIT ' . ASSET_METADATA_MAX_GAME_FILES, [$gameId]);
            foreach ($files as $file) {
                $stats = asset_meta_rebuild_file($db, $config, (int)$file['id']);
                $processed++;
                $totalAssets += $stats['assets'];
                $totalSoftRefs += $stats['soft_refs'];
            }
        } else {
            throw new RuntimeException('Choose a file ID or a game.');
        }

        $result = 'Processed ' . $processed . ' file(s). Asset rows=' . $totalAssets . ', soft-reference candidates=' . $totalSoftRefs . '.';
    }

    catalog_head('Asset Metadata Rebuild');
    catalog_page_header('Asset Metadata Rebuild', 'Rebuilds explicit UE asset metadata rows from verified export tables and records soft-reference path candidates from package bytes. These rows are diagnostic/source metadata; they do not create same-folder dependency guesses.', ['Dashboard' => 'dashboard.php']);
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

    echo '<div class="card"><h2>Notes</h2><p class="muted">This tool does not treat redirectors as aliases. A future redirector resolver should only use a parsed ObjectRedirector target, not folder/object-name similarity.</p></div>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Asset Metadata Rebuild error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
