#!/usr/bin/env php
<?php
/**
 * Read-only diagnostic for game-scoped ClassRemap behavior in UE1/UE2 VerifyImport resolution.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

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
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-class-remap.php --game-id=ID [--max-files=1000]\n");
    exit(2);
}

$config = catalog_config();
$db = catalog_db($config);
$remaps = (new PdoClassRemapRepository($db))->mappingsForGame($gameId);
if ($remaps === []) {
    fwrite(STDERR, "No ClassRemap mappings are configured for game {$gameId}.\n");
    exit(3);
}

$key = static fn(string $value): string => function_exists('mb_strtolower')
    ? mb_strtolower(trim($value), 'UTF-8')
    : strtolower(trim($value));

$stmt = $db->prepare(
    'SELECT DISTINCT l.file_id FROM ue_dependency_links l '
    . 'JOIN ue_files f ON f.id=l.file_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'ORDER BY l.file_id LIMIT ' . $maxFiles
);
$stmt->execute([$gameId]);
$fileIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

$loader = new BlockedCompressedMetadataSnapshotLoader(
    $db,
    (string)($config['storage_path'] ?? ($root . '/storage'))
);
$details = [];
foreach ($fileIds as $fileId) {
    $snapshot = $loader->loadDependencySnapshot($fileId);
    $imports = array_values((array)($snapshot['imports'] ?? []));
    $targetsByPackage = [];
    foreach ($imports as $import) {
        if (!is_array($import)) {
            continue;
        }
        $objectName = trim((string)($import['object_name'] ?? ''));
        $sourceKey = $key($objectName);
        if (!isset($remaps[$sourceKey])) {
            continue;
        }
        $package = trim((string)($import['root_package'] ?? ''));
        if ($package !== '') {
            $targetsByPackage[$key($package)][] = $import;
        }
    }
    if ($targetsByPackage === []) {
        continue;
    }

    foreach ($targetsByPackage as $packageKey => $targetImports) {
        $packageName = trim((string)($targetImports[0]['root_package'] ?? ''));
        $packageImports = array_values(array_filter(
            $imports,
            static fn(mixed $import): bool => is_array($import)
                && $key((string)($import['root_package'] ?? '')) === $packageKey
        ));

        $providers = catalog_all(
            $db,
            'SELECT DISTINCT p.file_id,p.source_kind FROM ue_package_providers p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'WHERE p.game_id=? AND p.package_name=? AND p.file_id<>? AND f.scan_status="verified" '
            . 'ORDER BY (p.source_kind="primary") DESC,p.file_id',
            [$gameId, $packageName, $fileId]
        );

        $requiredIndexes = [];
        foreach ($packageImports as $fallback => $import) {
            if (trim((string)($import['relative_object_path'] ?? '')) !== '') {
                $requiredIndexes[] = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
            }
        }

        $providerResults = [];
        foreach ($providers as $provider) {
            $providerId = (int)$provider['file_id'];
            $without = PdoLegacyVerifyImportProjectionResolver::resolveProviderVariants(
                $db,
                $providerId,
                $packageImports,
                []
            );
            $with = PdoLegacyVerifyImportProjectionResolver::resolveProviderVariants(
                $db,
                $providerId,
                $packageImports,
                $remaps
            );
            $policy = 'unreal2';
            $withoutMatches = (array)($without[$policy] ?? []);
            $withMatches = (array)($with[$policy] ?? []);
            $targetResults = [];
            foreach ($targetImports as $target) {
                $index = (int)($target['import_index'] ?? -1);
                $source = trim((string)($target['object_name'] ?? ''));
                $targetResults[] = [
                    'import_index' => $index,
                    'source_object_name' => $source,
                    'mapped_object_name' => $remaps[$key($source)] ?? null,
                    'class_package' => (string)($target['class_package'] ?? ''),
                    'class_name' => (string)($target['class_name'] ?? ''),
                    'outer_index' => (int)($target['outer_index'] ?? 0),
                    'without_remap_export_index' => $withoutMatches[$index] ?? null,
                    'with_remap_export_index' => $withMatches[$index] ?? null,
                ];
            }
            $missingWithout = array_values(array_diff($requiredIndexes, array_map('intval', array_keys($withoutMatches))));
            $missingWith = array_values(array_diff($requiredIndexes, array_map('intval', array_keys($withMatches))));
            $providerResults[] = [
                'provider_file_id' => $providerId,
                'source_kind' => (string)$provider['source_kind'],
                'required_import_count' => count($requiredIndexes),
                'matched_without_remap' => count($requiredIndexes) - count($missingWithout),
                'matched_with_remap' => count($requiredIndexes) - count($missingWith),
                'complete_without_remap' => $missingWithout === [],
                'complete_with_remap' => $missingWith === [],
                'missing_import_indexes_without_remap' => $missingWithout,
                'missing_import_indexes_with_remap' => $missingWith,
                'remapped_imports' => $targetResults,
            ];
        }

        $details[] = [
            'consumer_file_id' => $fileId,
            'consumer_package' => (string)(($snapshot['file']['package_name'] ?? '')),
            'required_package' => $packageName,
            'package_import_count' => count($packageImports),
            'configured_remap_import_count' => count($targetImports),
            'providers' => $providerResults,
        ];
    }
}

echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game_id' => $gameId,
    'configured_remaps' => $remaps,
    'missing_consumer_files_checked' => count($fileIds),
    'groups_with_configured_remap_imports' => count($details),
    'details' => $details,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
