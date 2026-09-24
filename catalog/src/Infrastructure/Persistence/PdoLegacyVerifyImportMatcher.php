<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reproduces the serialized-data portion of UE1/UE2 ULinkerLoad::VerifyImport().
 * Why: Dependency matching must follow engine ObjectName/ClassName/ClassPackage and PackageIndex rules,
 *      rather than treating reconstructed object paths as the identity.
 * Role: Pure UE1/UE2 import matcher plus compact-provider adapter.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;

final class PdoLegacyVerifyImportMatcher
{
    private const PAGE_SIZE = 5000;
    private const RF_PUBLIC = 0x00000004;

    /**
     * Resolve normalized consumer Imports against one already-selected package provider.
     *
     * Provider selection itself cannot be reproduced from package serialization because
     * GetPackageLinker() also depends on the engine's runtime package/search paths.
     *
     * The later VerifyImport() native/public/transient StaticFindObject fallback is also
     * not reproducible from package data alone: those runtime native objects need not be
     * serialized in the provider's ExportMap. An unresolved serialized match therefore
     * remains unresolved here rather than being replaced with an invented catalog fallback.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @return array<int,int> consumer import_index => provider export_index
     */
    public static function resolveProvider(PDO $db, int $providerFileId, array $consumerImports): array
    {
        if ($providerFileId < 1 || $consumerImports === []) {
            return [];
        }

        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $storageRoot = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for VerifyImport provider matching.');
        }

        $reader = new BlockedCompressedMetadataReader($db, $storageRoot);
        $providerImports = self::loadSection($reader, $providerFileId, 'imports');
        $providerExports = self::loadSection($reader, $providerFileId, 'exports');

        $row = \catalog_one(
            $db,
            'SELECT package_name FROM ue_files WHERE id=? LIMIT 1',
            [$providerFileId]
        );
        $providerPackageName = trim((string)($row['package_name'] ?? ''));
        if ($providerPackageName === '') {
            return [];
        }

        return self::match($consumerImports, $providerImports, $providerExports, $providerPackageName, true);
    }

    /**
     * Evaluate both reviewed UE2 behaviours from the same serialized package data.
     *
     * "standard" follows UE2.5/UT2004 and requires RF_Public.
     * "unreal2" follows the supplied Unreal II revision where FailedImportPrivate
     * is compiled out and therefore accepts the same identity/outer match even
     * when RF_Public is absent.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function resolveProviderVariants(PDO $db, int $providerFileId, array $consumerImports): array
    {
        if ($providerFileId < 1 || $consumerImports === []) {
            return ['standard' => [], 'unreal2' => [], 'unreal2_only' => []];
        }

        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $storageRoot = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for VerifyImport provider matching.');
        }

        $reader = new BlockedCompressedMetadataReader($db, $storageRoot);
        $providerImports = self::loadSection($reader, $providerFileId, 'imports');
        $providerExports = self::loadSection($reader, $providerFileId, 'exports');
        $row = \catalog_one($db, 'SELECT package_name FROM ue_files WHERE id=? LIMIT 1', [$providerFileId]);
        $providerPackageName = trim((string)($row['package_name'] ?? ''));
        if ($providerPackageName === '') {
            return ['standard' => [], 'unreal2' => [], 'unreal2_only' => []];
        }

        return self::matchVariants($consumerImports, $providerImports, $providerExports, $providerPackageName);
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>}
     */
    public static function matchVariants(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName
    ): array {
        $standard = self::match($consumerImports, $providerImports, $providerExports, $providerPackageName, true);
        $unreal2 = self::match($consumerImports, $providerImports, $providerExports, $providerPackageName, false);
        $unreal2Only = [];
        foreach ($unreal2 as $importIndex => $exportIndex) {
            if (!isset($standard[$importIndex])) {
                $unreal2Only[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return ['standard' => $standard, 'unreal2' => $unreal2, 'unreal2_only' => $unreal2Only];
    }

    /**
     * Pure serialized-data VerifyImport matcher.
     *
     * Reproduces:
     * - ObjectName + ClassName + ClassPackage identity
     * - recursive parent Import resolution through PackageIndex
     * - Source.PackageIndex == Parent.SourceIndex + 1, with Source.PackageIndex == 0 fallback
     * - Mesh -> LodMesh retry
     *
     * The $requirePublic switch preserves the reviewed source difference:
     * UE2.5/UT2004 reject a private Export, while the supplied Unreal II revision
     * compiles FailedImportPrivate out and accepts the same match.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @return array<int,int>
     */
    public static function match(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        bool $requirePublic = true
    ): array {
        $consumerByIndex = self::indexRows($consumerImports, 'import_index');
        $providerImportsByIndex = self::indexRows($providerImports, 'import_index');
        $providerExportsByIndex = self::indexRows($providerExports, 'export_index');

        // UE builds ExportHash by prepending each Export while iterating ascending
        // indices, so VerifyImport sees the highest matching Export index first.
        krsort($providerExportsByIndex, SORT_NUMERIC);

        $resolved = [];
        $visiting = [];
        foreach (array_keys($consumerByIndex) as $importIndex) {
            self::resolveImport(
                (int)$importIndex,
                $consumerByIndex,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName,
                $resolved,
                $visiting,
                $requirePublic
            );
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if ($exportIndex !== null) {
                $matches[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    /**
     * @param array<int,array<string,mixed>> $consumerImports
     * @param array<int,array<string,mixed>> $providerImports
     * @param array<int,array<string,mixed>> $providerExports
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImport(
        int $importIndex,
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array &$resolved,
        array &$visiting,
        bool $requirePublic
    ): ?int {
        if (array_key_exists($importIndex, $resolved)) {
            return $resolved[$importIndex];
        }
        if (isset($visiting[$importIndex])) {
            return $resolved[$importIndex] = null;
        }

        $import = $consumerImports[$importIndex] ?? null;
        if (!is_array($import)) {
            return $resolved[$importIndex] = null;
        }

        $classPackage = trim((string)($import['class_package'] ?? ''));
        $className = trim((string)($import['class_name'] ?? ''));
        $objectName = trim((string)($import['object_name'] ?? ''));
        if ($classPackage === '' || $className === '' || $objectName === '') {
            return $resolved[$importIndex] = null;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0) {
            // Top-level Core.Package import: VerifyImport loads the SourceLinker
            // but does not assign SourceIndex.
            return $resolved[$importIndex] = null;
        }
        if ($outerIndex > 0) {
            // UE1/UE2 VerifyImport requires non-package Imports to have a negative
            // PackageIndex referencing another Import.
            return $resolved[$importIndex] = null;
        }

        $visiting[$importIndex] = true;
        $parentImportIndex = -$outerIndex - 1;
        $parentSourceIndex = self::resolveImport(
            $parentImportIndex,
            $consumerImports,
            $providerImports,
            $providerExports,
            $providerPackageName,
            $resolved,
            $visiting,
            $requirePublic
        );

        $matched = self::findExport(
            $objectName,
            $className,
            $classPackage,
            $parentSourceIndex,
            $providerImports,
            $providerExports,
            $providerPackageName,
            $requirePublic
        );

        if ($matched === null && self::key($className) === 'mesh') {
            $matched = self::findExport(
                $objectName,
                'LodMesh',
                $classPackage,
                $parentSourceIndex,
                $providerImports,
                $providerExports,
                $providerPackageName,
                $requirePublic
            );
        }

        unset($visiting[$importIndex]);
        return $resolved[$importIndex] = $matched;
    }

    /**
     * @param array<int,array<string,mixed>> $providerImports
     * @param array<int,array<string,mixed>> $providerExports
     */
    private static function findExport(
        string $objectName,
        string $className,
        string $classPackage,
        ?int $parentSourceIndex,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        bool $requirePublic
    ): ?int {
        foreach ($providerExports as $exportIndex => $export) {
            if (self::key((string)($export['object_name'] ?? '')) !== self::key($objectName)) {
                continue;
            }

            [$sourceClassPackage, $sourceClassName] = self::exportClassIdentity(
                $export,
                $providerImports,
                $providerExports,
                $providerPackageName
            );
            if (self::key($sourceClassName) !== self::key($className)
                || self::key($sourceClassPackage) !== self::key($classPackage)) {
                continue;
            }

            $sourceOuter = (int)($export['outer_index'] ?? 0);
            if ($parentSourceIndex === null) {
                if ($sourceOuter !== 0) {
                    continue;
                }
            } elseif ($sourceOuter !== 0 && $sourceOuter !== $parentSourceIndex + 1) {
                continue;
            }

            if ($requirePublic && (((int)($export['object_flags'] ?? 0) & self::RF_PUBLIC) === 0)) {
                continue;
            }

            return (int)$exportIndex;
        }

        return null;
    }

    /**
     * Mirrors ULinkerLoad::GetExportClassName/GetExportClassPackage for UE1/UE2.
     *
     * @param array<string,mixed> $export
     * @param array<int,array<string,mixed>> $providerImports
     * @param array<int,array<string,mixed>> $providerExports
     * @return array{0:string,1:string}
     */
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
            $className = (string)($classImport['object_name'] ?? '');
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter >= 0) {
                return ['', $className];
            }
            $classPackageImport = $providerImports[-$classOuter - 1] ?? null;
            return [
                is_array($classPackageImport) ? (string)($classPackageImport['object_name'] ?? '') : '',
                $className,
            ];
        }

        if ($classIndex > 0) {
            $classExport = $providerExports[$classIndex - 1] ?? null;
            return [
                $providerPackageName,
                is_array($classExport) ? (string)($classExport['object_name'] ?? '') : '',
            ];
        }

        return ['Core', 'Class'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadSection(
        BlockedCompressedMetadataReader $reader,
        int $fileId,
        string $section
    ): array {
        $rows = [];
        for ($start = 0; ; $start += self::PAGE_SIZE) {
            $page = $reader->page($fileId, $section, $start, self::PAGE_SIZE);
            foreach ($page as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            if (count($page) < self::PAGE_SIZE) {
                break;
            }
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function indexRows(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row[$field]) ? (int)$row[$field] : (int)$fallback;
            $indexed[$index] = $row;
        }
        return $indexed;
    }

    private static function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
