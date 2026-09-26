#!/usr/bin/env php
<?php
/**
 * Read-only diagnostic: inspect every currently missing legacy dependency and every physical provider candidate.
 * Dumps requested serialized import identity, provider exports, and strict VerifyImport-style rejection reasons.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassRemapRepository;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoLegacyVerifyImportProjectionResolver;

$options = getopt('', ['game-id:', 'max-files::']);
$gameId = (int)($options['game-id'] ?? 0);
$maxFiles = max(1, min(10000, (int)($options['max-files'] ?? 1000)));
if ($gameId < 1) {
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-missing-provider-matches.php --game-id=ID [--max-files=1000]\n");
    exit(2);
}

$config = catalog_config();
$db = catalog_db($config);
$storage = (string)($config['storage_path'] ?? ($root . '/storage'));
$loader = new BlockedCompressedMetadataSnapshotLoader($db, $storage);
$remaps = (new PdoClassRemapRepository($db))->mappingsForGame($gameId);
$key = static fn(string $v): string => function_exists('mb_strtolower') ? mb_strtolower(trim($v), 'UTF-8') : strtolower(trim($v));

$stmt = $db->prepare(
    'SELECT DISTINCT l.file_id FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'ORDER BY l.file_id LIMIT ' . $maxFiles
);
$stmt->execute([$gameId]);
$fileIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$details = [];
foreach ($fileIds as $fileId) {
    $consumer = $loader->loadDependencySnapshot($fileId);
    $imports = array_values((array)$consumer['imports']);
    $dependencies = array_values((array)$consumer['dependencies']);
    $missingIndexes = [];
    foreach ($dependencies as $dep) {
        if (is_array($dep) && (string)($dep['status'] ?? '') === 'missing') {
            $missingIndexes[(int)$dep['import_index']] = true;
        }
    }

    $byPackage = [];
    foreach ($imports as $fallback => $import) {
        if (!is_array($import)) continue;
        $idx = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
        if (!isset($missingIndexes[$idx])) continue;
        $pkg = trim((string)($import['root_package'] ?? ''));
        if ($pkg !== '') $byPackage[$key($pkg)]['name'] = $pkg;
    }

    $consumerResult = [
        'consumer_file_id' => $fileId,
        'consumer_package' => (string)($consumer['file']['package_name'] ?? ''),
        'missing_packages' => [],
    ];

    foreach ($byPackage as $packageKey => $packageInfo) {
        $packageName = (string)$packageInfo['name'];
        $packageImports = [];
        $missingPackageImports = [];
        foreach ($imports as $fallback => $import) {
            if (!is_array($import) || $key((string)($import['root_package'] ?? '')) !== $packageKey) continue;
            $idx = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            $packageImports[] = $import;
            if (isset($missingIndexes[$idx])) $missingPackageImports[] = $import;
        }

        $providers = catalog_all($db,
            'SELECT DISTINCT p.file_id,p.source_kind,f.package_name,f.original_name,f.game_id '
            . 'FROM ue_package_providers p JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'WHERE p.game_id=? AND p.package_name=? AND p.file_id<>? AND f.scan_status="verified" '
            . 'ORDER BY (p.source_kind="primary") DESC,p.file_id',
            [$gameId, $packageName, $fileId]
        );

        $packageResult = [
            'required_package' => $packageName,
            'missing_import_count' => count($missingPackageImports),
            'missing_imports' => [],
            'providers' => [],
        ];
        foreach ($missingPackageImports as $import) {
            $object = trim((string)($import['object_name'] ?? ''));
            $class = trim((string)($import['class_name'] ?? ''));
            $packageResult['missing_imports'][] = [
                'import_id' => (int)($import['id'] ?? 0),
                'import_index' => (int)($import['import_index'] ?? -1),
                'full_path' => (string)($import['full_path'] ?? ''),
                'relative_object_path' => (string)($import['relative_object_path'] ?? ''),
                'object_name' => $object,
                'class_name' => $class,
                'class_package' => (string)($import['class_package'] ?? ''),
                'outer_index' => (int)($import['outer_index'] ?? 0),
                'configured_object_remap' => $remaps[$key($object)] ?? null,
                'configured_class_remap' => $remaps[$key($class)] ?? null,
            ];
        }

        foreach ($providers as $provider) {
            $providerId = (int)$provider['file_id'];
            $providerSnapshot = $loader->load($providerId);
            $providerImports = array_values((array)$providerSnapshot['imports']);
            $providerExports = array_values((array)$providerSnapshot['exports']);
            $providerPackageName = (string)($providerSnapshot['file']['package_name'] ?? $provider['package_name']);

            $without = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
                $packageImports, $providerImports, $providerExports, $providerPackageName, []
            );
            $with = PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
                $packageImports, $providerImports, $providerExports, $providerPackageName, $remaps
            );
            $withoutMatches = (array)($without['unreal2'] ?? []);
            $withMatches = (array)($with['unreal2'] ?? []);

            $exportRows = [];
            foreach ($providerExports as $export) {
                if (!is_array($export)) continue;
                $exportRows[] = [
                    'export_index' => (int)($export['export_index'] ?? -1),
                    'object_name' => (string)($export['object_name'] ?? ''),
                    'local_path' => (string)($export['local_path'] ?? ''),
                    'full_path' => (string)($export['full_path'] ?? ''),
                    'class_index' => (int)($export['class_index'] ?? 0),
                    'outer_index' => (int)($export['outer_index'] ?? 0),
                    'object_flags' => (int)($export['object_flags'] ?? 0),
                ];
            }

            $perImport = [];
            foreach ($missingPackageImports as $import) {
                $idx = (int)($import['import_index'] ?? -1);
                $object = trim((string)($import['object_name'] ?? ''));
                $mappedObject = trim((string)($remaps[$key($object)] ?? ''));
                $interestingNames = [$key($object) => true];
                if ($mappedObject !== '') $interestingNames[$key($mappedObject)] = true;
                $near = [];
                foreach ($exportRows as $e) {
                    if (isset($interestingNames[$key((string)$e['object_name'])])) $near[] = $e;
                }
                $reasons = [];
                if (!isset($withoutMatches[$idx]) && !isset($withMatches[$idx])) {
                    if ($near === []) {
                        $reasons[] = 'no provider export has the original or configured-remap object name';
                    } else {
                        $reasons[] = 'name candidate exists but strict VerifyImport identity/outer checks did not resolve it';
                    }
                } elseif (!isset($withoutMatches[$idx]) && isset($withMatches[$idx])) {
                    $reasons[] = 'resolved only when configured ClassRemap is applied';
                } elseif (isset($withoutMatches[$idx])) {
                    $reasons[] = 'this import resolves against this provider without ClassRemap';
                }
                $perImport[] = [
                    'import_index' => $idx,
                    'full_path' => (string)($import['full_path'] ?? ''),
                    'object_name' => $object,
                    'class_name' => (string)($import['class_name'] ?? ''),
                    'class_package' => (string)($import['class_package'] ?? ''),
                    'outer_index' => (int)($import['outer_index'] ?? 0),
                    'configured_object_remap' => $mappedObject !== '' ? $mappedObject : null,
                    'matched_without_remap_export_index' => $withoutMatches[$idx] ?? null,
                    'matched_with_remap_export_index' => $withMatches[$idx] ?? null,
                    'nearby_provider_exports_by_object_name' => $near,
                    'diagnosis' => $reasons,
                ];
            }

            $requiredIndexes = [];
            foreach ($packageImports as $fallback => $import) {
                if (!is_array($import) || trim((string)($import['relative_object_path'] ?? '')) === '') continue;
                $requiredIndexes[] = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            }
            $missingWithout = array_values(array_diff($requiredIndexes, array_map('intval', array_keys($withoutMatches))));
            $missingWith = array_values(array_diff($requiredIndexes, array_map('intval', array_keys($withMatches))));

            $packageResult['providers'][] = [
                'provider_file_id' => $providerId,
                'source_kind' => (string)$provider['source_kind'],
                'package_name' => (string)$provider['package_name'],
                'original_name' => (string)$provider['original_name'],
                'provider_import_count' => count($providerImports),
                'provider_export_count' => count($providerExports),
                'required_import_count' => count($requiredIndexes),
                'matched_without_remap' => count($requiredIndexes) - count($missingWithout),
                'matched_with_remap' => count($requiredIndexes) - count($missingWith),
                'complete_without_remap' => $missingWithout === [],
                'complete_with_remap' => $missingWith === [],
                'missing_import_indexes_without_remap' => $missingWithout,
                'missing_import_indexes_with_remap' => $missingWith,
                'imports' => $perImport,
            ];
        }
        $consumerResult['missing_packages'][] = $packageResult;
    }
    $details[] = $consumerResult;
}

echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'configured_remaps' => $remaps,
    'missing_consumer_files_checked' => count($fileIds),
    'details' => $details,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
