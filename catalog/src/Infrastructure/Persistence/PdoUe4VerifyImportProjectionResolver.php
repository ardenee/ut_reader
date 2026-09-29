<?php
/**
 * Resolves UE4 Imports against one physical provider using the deterministic,
 * file-backed portion of FLinkerLoad::VerifyImportInner.
 *
 * Runtime-only CoreRedirects, instancing and already-loaded native/transient
 * object behavior are deliberately excluded from static catalog resolution.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

final class PdoUe4VerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000001;
    private const TOP_LEVEL_PACKAGE = -2147483647;
    private const PRIVATE_FAILURE = -2147483648;

    /** @param list<array<string,mixed>> $consumerImports @return array<int,int> */
    public static function resolveProvider(PDO $db, int $providerFileId, array $consumerImports): array
    {
        if ($providerFileId < 1 || $consumerImports === []) {
            return [];
        }
        if (!function_exists('catalog_config')) {
            throw new RuntimeException('Catalog configuration is required for authoritative UE4 VerifyImport resolution.');
        }
        $config = \catalog_config();
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for authoritative UE4 VerifyImport resolution.');
        }

        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($providerFileId);
        $file = (array)($snapshot['file'] ?? []);
        return self::resolveInMemory(
            $consumerImports,
            (array)($snapshot['imports'] ?? []),
            (array)($snapshot['exports'] ?? []),
            (string)($file['package_name'] ?? '')
        );
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @return array<int,int>
     */
    public static function resolveInMemory(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName
    ): array {
        $imports = self::indexRows($consumerImports, 'import_index');
        $providerImportsByIndex = self::indexRows($providerImports, 'import_index');
        $providerExportsByIndex = self::indexRows($providerExports, 'export_index');

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $objectName = trim((string)($export['object_name'] ?? ''));
            if ($objectName === '') {
                continue;
            }
            [$classPackage, $className] = self::exportClassIdentity(
                $export,
                $providerImportsByIndex,
                $providerExportsByIndex,
                $providerPackageName
            );
            if ($classPackage === '' || $className === '') {
                continue;
            }
            $key = self::candidateKey($objectName, $className);
            $candidates[$key][] = [
                'export_index' => (int)$exportIndex,
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
                'object_name' => $objectName,
                'class_package' => $classPackage,
                'class_name' => $className,
            ];
        }
        foreach ($candidates as &$rows) {
            usort($rows, static fn(array $a, array $b): int => $b['export_index'] <=> $a['export_index']);
        }
        unset($rows);

        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $importIndex) {
            self::resolveImportIndex((int)$importIndex, $imports, $candidates, $resolved, $visiting);
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if (is_int($exportIndex) && $exportIndex >= 0) {
                $matches[(int)$importIndex] = $exportIndex;
            }
        }
        return $matches;
    }

    /**
     * UE4 FLinker::GetExportClassName/GetExportClassPackage equivalent using the
     * parsed signed ClassIndex graph. Current v4 metadata retains the complete
     * provider import/export graph required for pre-520 packages.
     *
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
        if ($classIndex === 0) {
            return ['/Script/CoreUObject', 'Class'];
        }

        if ($classIndex < 0) {
            $classImport = $providerImports[-$classIndex - 1] ?? null;
            if (!is_array($classImport)) {
                return ['', ''];
            }
            $className = trim((string)($classImport['object_name'] ?? ''));
            $package = self::rootPackageForImport($classImport, $providerImports, $providerExports, $providerPackageName);
            return [$package, $className];
        }

        $classExport = $providerExports[$classIndex - 1] ?? null;
        if (!is_array($classExport)) {
            return ['', ''];
        }
        return [trim($providerPackageName), trim((string)($classExport['object_name'] ?? ''))];
    }

    /** @param array<int,array<string,mixed>> $imports @param array<int,array<string,mixed>> $exports */
    private static function rootPackageForImport(array $import, array $imports, array $exports, string $providerPackageName): string
    {
        $seen = [];
        $current = $import;
        while (true) {
            $outer = (int)($current['outer_index'] ?? 0);
            if ($outer === 0) {
                $name = trim((string)($current['object_name'] ?? ''));
                return $name !== '' ? $name : trim($providerPackageName);
            }
            if ($outer < 0) {
                $index = -$outer - 1;
                if (isset($seen['i' . $index]) || !isset($imports[$index])) {
                    return '';
                }
                $seen['i' . $index] = true;
                $current = $imports[$index];
                continue;
            }

            // Pre-v5 metadata cannot preserve UE4 >=520 FObjectImport::PackageName.
            // An import whose package is independent from an export outer must stay
            // unresolved until the v5 contract provides that serialized field.
            return '';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImportIndex(
        int $index,
        array $imports,
        array $candidates,
        array &$resolved,
        array &$visiting
    ): ?int {
        if (array_key_exists($index, $resolved)) {
            return $resolved[$index];
        }
        if (isset($visiting[$index]) || !isset($imports[$index])) {
            return $resolved[$index] = null;
        }
        $visiting[$index] = true;
        $import = $imports[$index];

        $objectName = trim((string)($import['object_name'] ?? ''));
        $className = trim((string)($import['class_name'] ?? ''));
        $classPackage = trim((string)($import['class_package'] ?? ''));
        if ($objectName === '' || $className === '' || $classPackage === '') {
            unset($visiting[$index]);
            return $resolved[$index] = null;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0) {
            unset($visiting[$index]);
            return $resolved[$index] = self::key($className) === 'package'
                ? self::TOP_LEVEL_PACKAGE
                : null;
        }
        if ($outerIndex > 0) {
            // Modern UE4 supports import/export mixed outer graphs, but correct
            // provider selection for those imports can depend on serialized
            // FObjectImport::PackageName (>=520), which v4 metadata discarded.
            unset($visiting[$index]);
            return $resolved[$index] = null;
        }

        $parentIndex = -$outerIndex - 1;
        $parentSource = self::resolveImportIndex($parentIndex, $imports, $candidates, $resolved, $visiting);
        if ($parentSource === null || $parentSource === self::PRIVATE_FAILURE) {
            unset($visiting[$index]);
            return $resolved[$index] = $parentSource === self::PRIVATE_FAILURE ? self::PRIVATE_FAILURE : null;
        }
        $expectedOuter = $parentSource === self::TOP_LEVEL_PACKAGE ? 0 : $parentSource + 1;

        $matched = self::findCandidate(
            $candidates,
            $objectName,
            $className,
            $classPackage,
            $expectedOuter
        );
        unset($visiting[$index]);
        return $resolved[$index] = $matched;
    }

    /** @param array<string,list<array<string,mixed>>> $candidates */
    private static function findCandidate(
        array $candidates,
        string $objectName,
        string $className,
        string $classPackage,
        int $expectedOuter
    ): ?int {
        $rows = $candidates[self::candidateKey($objectName, $className)] ?? [];
        if ($rows === []) {
            return null;
        }

        // UE4's package-name transition rule first determines whether an exact
        // full ClassPackage match exists. Only when no such candidate exists may
        // the short package name be used. Outer filtering happens afterwards.
        $hasFullPackageMatch = false;
        foreach ($rows as $candidate) {
            if (self::key((string)$candidate['class_package']) === self::key($classPackage)) {
                $hasFullPackageMatch = true;
                break;
            }
        }

        $expectedPackageKey = $hasFullPackageMatch
            ? self::key($classPackage)
            : self::key(self::shortPackageName($classPackage));

        foreach ($rows as $candidate) {
            $candidatePackage = $hasFullPackageMatch
                ? (string)$candidate['class_package']
                : self::shortPackageName((string)$candidate['class_package']);
            if (self::key($candidatePackage) !== $expectedPackageKey) {
                continue;
            }
            if ((int)$candidate['outer_index'] !== $expectedOuter) {
                continue;
            }
            if ((((int)$candidate['object_flags']) & self::RF_PUBLIC) === 0) {
                return self::PRIVATE_FAILURE;
            }
            return (int)$candidate['export_index'];
        }
        return null;
    }

    private static function candidateKey(string $objectName, string $className): string
    {
        return self::key($objectName) . "\0" . self::key($className);
    }

    private static function shortPackageName(string $packageName): string
    {
        $packageName = trim($packageName);
        if ($packageName === '') {
            return '';
        }
        $slash = strrpos($packageName, '/');
        $dot = strrpos($packageName, '.');
        $position = max($slash === false ? -1 : $slash, $dot === false ? -1 : $dot);
        return $position >= 0 ? substr($packageName, $position + 1) : $packageName;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array<string,mixed>> */
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
        return CatalogUnrealIdentityHash::nameKey(trim($value));
    }
}
