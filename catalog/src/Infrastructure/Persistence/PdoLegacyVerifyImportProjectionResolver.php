<?php
/**
 * Resolves UE1/UE2 Imports with the same in-memory ULinkerLoad::VerifyImport
 * implementation used by diagnostics. SQL projections may locate package
 * providers, but they are not authoritative for the final VerifyImport result.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

require_once dirname(__DIR__) . '/Metadata/CatalogUnrealIdentityHash.php';

final class PdoLegacyVerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    private const PRIVATE_FAILURE = -2147483648;

    /**
     * Resolve one physical provider from its authoritative current-format metadata.
     *
     * Provider discovery remains indexed in SQL, but the final UE1/UE2
     * VerifyImport decision must not be made from ue_legacy_export_identity_lookup.
     * This keeps dependency rebuilding and the diagnostic comparison on one path.
     *
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function resolveProviderVariants(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        array $classRemaps = []
    ): array {
        if ($providerFileId < 1 || $consumerImports === []) {
            return ['standard' => [], 'unreal2' => [], 'unreal2_only' => []];
        }

        if (!function_exists('catalog_config')) {
            throw new RuntimeException('Catalog configuration is required for authoritative VerifyImport resolution.');
        }
        $config = \catalog_config();
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for authoritative VerifyImport resolution.');
        }

        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $file = (array)($snapshot['file'] ?? []);

        return self::resolveInMemoryVariants(
            $consumerImports,
            (array)($snapshot['imports'] ?? []),
            (array)($snapshot['exports'] ?? []),
            (string)($file['package_name'] ?? ''),
            $classRemaps
        );
    }

    /**
     * Single authoritative UE1/UE2 VerifyImport implementation.
     *
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function resolveInMemoryVariants(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array $classRemaps = []
    ): array {
        $imports = [];
        foreach ($consumerImports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $imports[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
        }

        $providerImportsByIndex = [];
        foreach ($providerImports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $providerImportsByIndex[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
        }

        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
        }

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            [$classPackage, $className] = self::exportClassIdentity(
                $export,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName
            );
            $objectName = trim((string)($export['object_name'] ?? ''));
            if ($objectName === '' || $classPackage === '' || $className === '') {
                continue;
            }
            $key = bin2hex(self::identityHash($objectName, $className, $classPackage));
            $candidates[$key][] = [
                'export_index' => (int)$exportIndex,
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'object_name' => $objectName,
                'class_name' => $className,
                'class_package' => $classPackage,
            ];
        }
        foreach ($candidates as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }
        unset($rows);

        // Standard UE1/UE2.5 follows the normal RF_Public VerifyImport rule.
        // Unreal II deliberately has that private-export failure disabled and is
        // the only profile allowed to apply configured ClassRemap mappings.
        $standard = self::resolveVariant($imports, $candidates, true, []);
        $unreal2 = self::resolveVariant($imports, $candidates, false, $classRemaps);
        $unreal2Only = [];
        foreach ($unreal2 as $index => $exportIndex) {
            if (!isset($standard[$index])) {
                $unreal2Only[(int)$index] = (int)$exportIndex;
            }
        }

        return ['standard' => $standard, 'unreal2' => $unreal2, 'unreal2_only' => $unreal2Only];
    }

    private static function exportClassIdentity(
        array $export,
        array $providerImports,
        array $providerExports,
        string $providerPackageName
    ): array {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex < 0) {
            $classImport = $providerImports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = trim((string)($classImport['object_name'] ?? ''));
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter >= 0) {
                return ['', $className];
            }
            $classPackageImport = $providerImports[-$classOuter - 1] ?? null;
            return [
                is_array($classPackageImport) ? trim((string)($classPackageImport['object_name'] ?? '')) : '',
                $className,
            ];
        }
        if ($classIndex > 0) {
            $classExport = $providerExports[$classIndex - 1] ?? null;
            return [
                trim($providerPackageName),
                is_array($classExport) ? trim((string)($classExport['object_name'] ?? '')) : '',
            ];
        }
        return ['Core', 'Class'];
    }

    private static function resolveVariant(
        array $imports,
        array $candidates,
        bool $requirePublic,
        array $classRemaps
    ): array {
        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $index) {
            self::resolveImport((int)$index, $imports, $candidates, $requirePublic, $classRemaps, $resolved, $visiting);
        }
        $matches = [];
        foreach ($resolved as $index => $exportIndex) {
            if ($exportIndex !== null && $exportIndex !== self::PRIVATE_FAILURE) {
                $matches[(int)$index] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    private static function resolveImport(
        int $importIndex,
        array $imports,
        array $candidates,
        bool $requirePublic,
        array $classRemaps,
        array &$resolved,
        array &$visiting
    ): ?int {
        if (array_key_exists($importIndex, $resolved)) {
            return $resolved[$importIndex];
        }
        if (isset($visiting[$importIndex])) {
            return $resolved[$importIndex] = null;
        }

        $import = $imports[$importIndex] ?? null;
        if (!is_array($import)) {
            return $resolved[$importIndex] = null;
        }
        $objectName = trim((string)($import['object_name'] ?? ''));
        $className = trim((string)($import['class_name'] ?? ''));
        $classPackage = trim((string)($import['class_package'] ?? ''));
        if ($objectName === '' || $className === '' || $classPackage === '') {
            return $resolved[$importIndex] = null;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0 || $outerIndex > 0) {
            return $resolved[$importIndex] = null;
        }

        $visiting[$importIndex] = true;
        $parent = -$outerIndex - 1;
        $parentSource = self::resolveImport(
            $parent,
            $imports,
            $candidates,
            $requirePublic,
            $classRemaps,
            $resolved,
            $visiting
        );
        if ($parentSource === self::PRIVATE_FAILURE) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::PRIVATE_FAILURE;
        }

        $candidateClass = $className;
        $matched = self::findCandidate(
            $candidates,
            self::identityHash($objectName, $candidateClass, $classPackage),
            $objectName,
            $candidateClass,
            $classPackage,
            $parentSource,
            $requirePublic
        );

        // Unreal II/legacy VerifyImport retries Mesh as LodMesh.
        if ($matched === null && self::key($candidateClass) === 'mesh') {
            $candidateClass = 'LodMesh';
            $matched = self::findCandidate(
                $candidates,
                self::identityHash($objectName, $candidateClass, $classPackage),
                $objectName,
                $candidateClass,
                $classPackage,
                $parentSource,
                $requirePublic
            );
        }

        // ClassRemap is supplied only for the Unreal II policy by the caller.
        if ($matched === null) {
            $mappedObject = trim((string)($classRemaps[self::key($objectName)] ?? ''));
            if ($mappedObject !== '' && self::key($mappedObject) !== self::key($objectName)) {
                $matched = self::findCandidate(
                    $candidates,
                    self::identityHash($mappedObject, $candidateClass, $classPackage),
                    $mappedObject,
                    $candidateClass,
                    $classPackage,
                    $parentSource,
                    $requirePublic
                );
            }
        }

        unset($visiting[$importIndex]);
        return $resolved[$importIndex] = $matched;
    }

    private static function findCandidate(
        array $candidates,
        string $identityHash,
        string $objectName,
        string $className,
        string $classPackage,
        ?int $parentSourceIndex,
        bool $requirePublic
    ): ?int {
        foreach ($candidates[bin2hex($identityHash)] ?? [] as $candidate) {
            if (CatalogUnrealIdentityHash::nameKey((string)$candidate['object_name']) !== CatalogUnrealIdentityHash::nameKey($objectName)
                || CatalogUnrealIdentityHash::nameKey((string)$candidate['class_name']) !== CatalogUnrealIdentityHash::nameKey($className)
                || CatalogUnrealIdentityHash::nameKey((string)$candidate['class_package']) !== CatalogUnrealIdentityHash::nameKey($classPackage)) {
                continue;
            }

            $sourceOuter = (int)$candidate['outer_index'];
            if ($parentSourceIndex === null) {
                if ($sourceOuter !== 0) {
                    continue;
                }
            } elseif ($sourceOuter !== 0 && $sourceOuter !== $parentSourceIndex + 1) {
                continue;
            }

            if ($requirePublic && (((int)$candidate['object_flags'] & self::RF_PUBLIC) === 0)) {
                return self::PRIVATE_FAILURE;
            }
            return (int)$candidate['export_index'];
        }
        return null;
    }

    public static function identityHash(string $objectName, string $className, string $classPackage): string
    {
        return CatalogUnrealIdentityHash::verifyImportBinary($objectName, $className, $classPackage);
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::nameKey($value);
    }
}
