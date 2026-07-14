<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogDependencySchema.php';

const CATALOG_ASSET_METADATA_MAX_SOFT_REFS = 2000;

function catalog_asset_meta_package_path(string $packageName): string
{
    $packageName = rtrim(str_replace('\\', '/', trim($packageName)), '/');
    if ($packageName === '') {
        return '';
    }
    $pos = strrpos($packageName, '/');
    return $pos === false || $pos === 0 ? '' : substr($packageName, 0, $pos);
}

/** @return list<string> */
function catalog_asset_meta_extract_soft_reference_candidates(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return [];
    }

    $sources = [
        preg_replace('/[^\x20-\x7E]+/', "\n", $bytes) ?? '',
        preg_replace('/[^\x20-\x7E]+/', "\n", str_replace("\0", '', $bytes)) ?? '',
    ];

    $refs = [];
    foreach ($sources as $source) {
        if ($source === '') {
            continue;
        }
        if (preg_match_all('~/(?:Game|Engine|Script|Plugin|Plugins|[A-Za-z0-9_]+)/[A-Za-z0-9_./$+\-]+(?:\.[A-Za-z0-9_$+\-]+)?~', $source, $matches)) {
            foreach ($matches[0] as $match) {
                $value = trim((string)$match, " \t\r\n\0\x0B.,;:'\"()[]{}<>");
                if ($value !== '' && strlen($value) <= 1000) {
                    $refs[$value] = true;
                }
            }
        }
    }

    return array_slice(array_keys($refs), 0, CATALOG_ASSET_METADATA_MAX_SOFT_REFS);
}

function catalog_asset_meta_resolve_path(array $config, array $file): ?string
{
    $relative = (string)($file['relative_path'] ?? '');
    if ($relative === '') {
        return null;
    }

    $path = realpath(__DIR__ . '/../' . $relative);
    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    if (!$path || !$storageRoot || !str_starts_with($path, $storageRoot)) {
        return null;
    }

    return $path;
}

function catalog_asset_meta_normalize_ref(string $ref): string
{
    $ref = trim(str_replace('\\', '/', $ref));
    $ref = trim($ref, " \t\r\n\0\x0B.,;:'\"()[]{}<>");
    return strlen($ref) <= 1000 ? $ref : '';
}

function catalog_asset_meta_insert_dependency(PDO $db, int $fileId, ?int $sourceAssetId, string $path, string $type): bool
{
    $path = catalog_asset_meta_normalize_ref($path);
    $type = preg_replace('/[^a-z0-9_\-]+/i', '_', trim($type)) ?? 'unknown';
    if ($path === '' || $type === '') {
        return false;
    }

    $stmt = $db->prepare('INSERT INTO ue_asset_registry_dependencies(file_id,source_asset_id,dependency_object_path,dependency_type) VALUES(?,?,?,?)');
    $stmt->execute([$fileId, $sourceAssetId, $path, $type]);
    return true;
}

/**
 * Rebuilds explicit export-derived asset metadata plus parsed UE4 summary-level
 * reference metadata. It does not invent redirector aliases or folder/object
 * guesses; every dependency row is tagged by source.
 *
 * @param object|null $reader Optional already-open package reader.
 * @return array{assets:int,string_asset_refs:int,preload_deps:int,soft_refs:int,redirectors:int}
 */
function catalog_asset_metadata_rebuild_file(PDO $db, array $config, int $fileId, ?object $reader = null): array
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

    $packageName = (string)$file['package_name'];
    $packagePath = catalog_asset_meta_package_path($packageName);
    $assets = 0;
    $redirectors = 0;

    foreach ($exports as $export) {
        $objectPath = trim((string)($export['full_path'] ?? ''));
        $assetName = trim((string)($export['object_name'] ?? ''));
        if ($objectPath === '' || $assetName === '') {
            continue;
        }

        $className = (string)($export['class_name'] ?? '');
        $insertAsset->execute([$fileId, $objectPath, $packageName, $packagePath, $assetName, $className]);
        $assets += $insertAsset->rowCount() > 0 ? 1 : 0;

        if (stripos($className, 'ObjectRedirector') !== false) {
            // Target properties require serialized export-property decoding. Keep
            // this visible as metadata instead of guessing an alias.
            catalog_asset_meta_insert_dependency($db, $fileId, null, $objectPath, 'object_redirector_unparsed');
            $redirectors++;
        }
    }

    $stringRefs = 0;
    if ($reader !== null && method_exists($reader, 'getStringAssetReferences')) {
        $refs = $reader->getStringAssetReferences();
        if (is_array($refs)) {
            foreach ($refs as $ref) {
                $path = is_array($ref) ? (string)($ref['path'] ?? '') : (string)$ref;
                if ($path === $packageName || str_starts_with($path, $packageName . '.')) {
                    continue;
                }
                if (catalog_asset_meta_insert_dependency($db, $fileId, null, $path, 'string_asset_reference')) {
                    $stringRefs++;
                }
            }
        }
    }

    $preloadDeps = 0;
    if ($reader !== null && method_exists($reader, 'getPreloadDependencies')) {
        $deps = $reader->getPreloadDependencies();
        if (is_array($deps)) {
            foreach ($deps as $dep) {
                if (!is_array($dep)) {
                    continue;
                }
                $path = (string)($dep['path'] ?? '');
                if ($path === '' || $path === $packageName || str_starts_with($path, $packageName . '.')) {
                    continue;
                }
                if (catalog_asset_meta_insert_dependency($db, $fileId, null, $path, 'preload_dependency')) {
                    $preloadDeps++;
                }
            }
        }
    }

    $path = catalog_asset_meta_resolve_path($config, $file);
    $softRefs = $path ? catalog_asset_meta_extract_soft_reference_candidates($path) : [];
    $softCount = 0;
    foreach ($softRefs as $ref) {
        if ($ref === $packageName || str_starts_with($ref, $packageName . '.')) {
            continue;
        }
        if (catalog_asset_meta_insert_dependency($db, $fileId, null, $ref, 'soft_reference_candidate')) {
            $softCount++;
        }
    }

    return [
        'assets' => $assets,
        'string_asset_refs' => $stringRefs,
        'preload_deps' => $preloadDeps,
        'soft_refs' => $softCount,
        'redirectors' => $redirectors,
    ];
}
