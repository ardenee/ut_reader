<?php
/**
 * Resolves UE1/UE2 Imports against provider exports.
 *
 * Dependency requirements come exclusively from the consumer ImportMap. A
 * provider export can satisfy an import, but the provider export hierarchy must
 * never manufacture additional consumer requirements.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeProviderSnapshotLoader;

final class PdoLegacyVerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    private const PRIVATE_FAILURE = -2147483648;

    /** @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>} */
    public static function resolveProviderVariants(PDO $db, int $providerFileId, array $consumerImports, array $classRemaps = []): array
    {
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
        $snapshot = (new Uedb5RuntimeProviderSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $file = (array)($snapshot['file'] ?? []);
        return self::resolveInMemoryVariants($consumerImports, (array)($snapshot['imports'] ?? []), (array)($snapshot['exports'] ?? []), (string)($file['package_name'] ?? ''), $classRemaps);
    }

    /** @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>} */
    public static function resolveInMemoryVariants(array $consumerImports, array $providerImports, array $providerExports, string $providerPackageName, array $classRemaps = []): array
    {
        $imports = [];
        foreach ($consumerImports as $fallback => $row) {
            if (is_array($row)) {
                $imports[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
            }
        }
        $providerImportsByIndex = [];
        foreach ($providerImports as $fallback => $row) {
            if (is_array($row)) {
                $providerImportsByIndex[isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback] = $row;
            }
        }
        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (is_array($row)) {
                $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
            }
        }

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            [$classPackage, $className] = self::exportClassIdentity($export, $providerImportsByIndex, $providerExportsByIndex, $providerPackageName);
            $objectName = (string)($export['object_name'] ?? '');
            if ($objectName === '' || $classPackage === '' || $className === '') {
                continue;
            }
            $key = bin2hex(self::identityHash($objectName, $className, $classPackage));
            $candidates[$key][] = [
                'export_index' => (int)$exportIndex,
                'outer_index' => (int)($export['outer_index'] ?? $export['package_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'object_name' => $objectName,
                'class_name' => $className,
                'class_package' => $classPackage,
            ];
        }
        // UT99 builds ExportHash by walking ExportMap from low to high and
        // prepending each export to its bucket. Traversal is therefore descending
        // export index for candidates in the same identity bucket.
        foreach ($candidates as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }
        unset($rows);

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

    private static function exportClassIdentity(array $export, array $providerImports, array $providerExports, string $providerPackageName): array
    {
        $classIndex = (int)($export['class_index'] ?? 0);
        if ($classIndex < 0) {
            $classImport = $providerImports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) return ['', ''];
            $className = (string)($classImport['object_name'] ?? '');
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter >= 0) return ['', $className];
            $classPackageImport = $providerImports[-$classOuter - 1] ?? null;
            return [is_array($classPackageImport) ? (string)($classPackageImport['object_name'] ?? '') : '', $className];
        }
        if ($classIndex > 0) {
            $classExport = $providerExports[$classIndex - 1] ?? null;
            return [$providerPackageName, is_array($classExport) ? (string)($classExport['object_name'] ?? '') : ''];
        }
        return ['Core', 'Class'];
    }

    private static function resolveVariant(array $imports, array $candidates, bool $requirePublic, array $classRemaps): array
    {
        $matches = [];
        $resolving = [];
        foreach (array_keys($imports) as $index) {
            self::resolveImportIndex((int)$index, $imports, $candidates, $requirePublic, $classRemaps, $matches, $resolving);
        }
        return $matches;
    }

    private static function resolveImportIndex(int $index, array $imports, array $candidates, bool $requirePublic, array $classRemaps, array &$matches, array &$resolving): ?int
    {
        if (array_key_exists($index, $matches)) {
            return (int)$matches[$index];
        }
        if (isset($resolving[$index]) || !isset($imports[$index])) {
            return null;
        }
        $resolving[$index] = true;
        $import = $imports[$index];
        if (self::isNameNoneImport($import) || self::hasNameNoneAncestor($imports, $index)) {
            unset($resolving[$index]);
            return null;
        }

        $expectedOuterIndex = null;
        $outerIndex = (int)($import['outer_index'] ?? $import['package_index'] ?? 0);
        if ($outerIndex < 0) {
            $parentImportIndex = -$outerIndex - 1;
            $parentSourceIndex = self::resolveImportIndex($parentImportIndex, $imports, $candidates, $requirePublic, $classRemaps, $matches, $resolving);
            // Epic accepts provider-root exports (PackageIndex==0) both when the
            // parent import has no SourceIndex and as the fallback for a resolved
            // parent. findCandidate() preserves that provider-root acceptance.
            $expectedOuterIndex = $parentSourceIndex === null ? 0 : $parentSourceIndex + 1;
        }

        $matched = self::resolveImport($import, $candidates, $requirePublic, $classRemaps, $expectedOuterIndex);
        unset($resolving[$index]);
        if ($matched !== null && $matched !== self::PRIVATE_FAILURE) {
            $matches[$index] = $matched;
            return $matched;
        }
        return null;
    }

    private static function resolveImport(array $import, array $candidates, bool $requirePublic, array $classRemaps, ?int $expectedOuterIndex): ?int
    {
        $objectName = (string)($import['object_name'] ?? '');
        $className = (string)($import['class_name'] ?? '');
        $classPackage = (string)($import['class_package'] ?? '');
        if ($objectName === '' || $className === '' || $classPackage === '') return null;

        $candidateClass = $className;
        $matched = self::findCandidate($candidates, self::identityHash($objectName, $candidateClass, $classPackage), $objectName, $candidateClass, $classPackage, $requirePublic, $expectedOuterIndex);
        if ($matched === null && self::key($candidateClass) === 'mesh') {
            $candidateClass = 'LodMesh';
            $matched = self::findCandidate($candidates, self::identityHash($objectName, $candidateClass, $classPackage), $objectName, $candidateClass, $classPackage, $requirePublic, $expectedOuterIndex);
        }
        if ($matched === null) {
            $mappedObject = trim((string)($classRemaps[self::key($objectName)] ?? ''));
            if ($mappedObject !== '' && self::key($mappedObject) !== self::key($objectName)) {
                $matched = self::findCandidate($candidates, self::identityHash($mappedObject, $candidateClass, $classPackage), $mappedObject, $candidateClass, $classPackage, $requirePublic, $expectedOuterIndex);
            }
        }
        return $matched;
    }

    private static function findCandidate(array $candidates, string $identityHash, string $objectName, string $className, string $classPackage, bool $requirePublic, ?int $expectedOuterIndex): ?int
    {
        foreach ($candidates[bin2hex($identityHash)] ?? [] as $candidate) {
            if (self::key((string)$candidate['object_name']) !== self::key($objectName)
                || self::key((string)$candidate['class_name']) !== self::key($className)
                || self::key((string)$candidate['class_package']) !== self::key($classPackage)) {
                continue;
            }
            if ($expectedOuterIndex !== null) {
                $candidateOuterIndex = (int)($candidate['outer_index'] ?? 0);
                if ($candidateOuterIndex !== $expectedOuterIndex && $candidateOuterIndex !== 0) {
                    continue;
                }
            }
            // Epic performs the outer check before RF_Public. Once the first
            // identity+outer candidate in ExportHash traversal is private, normal
            // VerifyImport fails rather than searching for a later public duplicate.
            if ($requirePublic && (((int)$candidate['object_flags'] & self::RF_PUBLIC) === 0)) {
                return self::PRIVATE_FAILURE;
            }
            return (int)$candidate['export_index'];
        }
        return null;
    }

    private static function isNameNoneImport(array $import): bool
    {
        foreach (['class_package','class_name','object_name'] as $field) {
            if (self::key((string)($import[$field] ?? '')) === self::key('None')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $imports */
    private static function hasNameNoneAncestor(array $imports, int $index): bool
    {
        $seen = [];
        while (isset($imports[$index]) && !isset($seen[$index])) {
            $seen[$index] = true;
            $outer = (int)($imports[$index]['outer_index'] ?? $imports[$index]['package_index'] ?? 0);
            if ($outer >= 0) {
                return false;
            }
            $index = -$outer - 1;
            $parent = $imports[$index] ?? null;
            if (!is_array($parent)) {
                return false;
            }
            if (self::isNameNoneImport($parent)) {
                return true;
            }
        }
        return false;
    }

    public static function identityHash(string $objectName, string $className, string $classPackage): string
    {
        // VerifyImport compares FName identity. Do not use the catalog-wide
        // normalized search key here because that intentionally trims text.
        return md5(
            self::key($objectName) . "\0"
            . self::key($className) . "\0"
            . self::key($classPackage),
            true
        );
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
