<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog asset metadata.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogDependencySchema.php';
require_once __DIR__ . '/GameProfiles.php';
require_once __DIR__ . '/CatalogReaderResolver.php';
require_once __DIR__ . '/CatalogUE4ParserProfile.php';

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

function catalog_asset_meta_reader_engine(array $file): string
{
    $engine = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
    if (in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        return $engine;
    }

    $fallback = strtoupper((string)(gp_detect_from_extension((string)($file['extension'] ?? '')) ?? ''));
    return in_array($fallback, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true) ? $fallback : '';
}

function catalog_asset_meta_open_reader(PDO $db, array $config, array $file, ?string $path): ?object
{
    if (!$path || !is_file($path)) {
        return null;
    }

    $engine = catalog_asset_meta_reader_engine($file);
    if (!in_array($engine, ['UE4', 'UE5'], true)) {
        return null;
    }

    $readerClass = CatalogReaderResolver::resolve(
        $config,
        $engine,
        'Reader not found for package engine',
        'Reader file loaded for package engine ',
        ['UE4', 'UE5']
    );

    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [(int)$file['game_id']]) ?: [];
    $profile = gp_required_profile_for_game($db, (int)$file['game_id']);
    catalog_ue4_set_next_reader_options(catalog_ue4_reader_options($config, $game, $profile));

    $reader = new $readerClass($path);
    if (method_exists($reader, 'validatePackage')) {
        $issues = $reader->validatePackage();
        if (is_array($issues)) {
            foreach ($issues as $issue) {
                $text = trim((string)$issue);
                if ($text !== '' && !str_starts_with($text, 'Package is unversioned; using assumed UE4 parser version ')) {
                    error_log('[UnrealDB asset metadata reader] file_id=' . (int)$file['id'] . ' issue=' . $text);
                }
            }
        }
    }

    return $reader;
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

    $path = catalog_asset_meta_resolve_path($config, $file);
    if ($reader === null) {
        try {
            $reader = catalog_asset_meta_open_reader($db, $config, $file, $path);
        } catch (Throwable $e) {
            error_log('[UnrealDB asset metadata reader] file_id=' . $fileId . ' error=' . $e->getMessage());
        }
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
            catalog_asset_meta_insert_dependency($db, $fileId, null, $objectPath, 'object_redirector_unparsed');
            $redirectors++;
        }
    }

    $stringRefs = 0;
    if ($reader !== null && method_exists($reader, 'getStringAssetReferences')) {
        $refs = $reader->getStringAssetReferences();
        if (is_array($refs)) {
            foreach ($refs as $ref) {
                $refPath = is_array($ref) ? (string)($ref['path'] ?? '') : (string)$ref;
                if ($refPath === $packageName || str_starts_with($refPath, $packageName . '.')) {
                    continue;
                }
                if (catalog_asset_meta_insert_dependency($db, $fileId, null, $refPath, 'string_asset_reference')) {
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
                $depPath = (string)($dep['path'] ?? '');
                if ($depPath === '' || $depPath === $packageName || str_starts_with($depPath, $packageName . '.')) {
                    continue;
                }
                if (catalog_asset_meta_insert_dependency($db, $fileId, null, $depPath, 'preload_dependency')) {
                    $preloadDeps++;
                }
            }
        }
    }

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
