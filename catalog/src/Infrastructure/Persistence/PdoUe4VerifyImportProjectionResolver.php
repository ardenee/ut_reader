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
    public static function resolveProvider(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        return self::resolveProviderOutcome(
            $db,
            $providerFileId,
            $consumerImports,
            $consumerExports,
            $consumerGraphImports
        )['matches'];
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<array<string,mixed>> $consumerGraphImports
     * @return array{matches:array<int,int>,redirectors:array<int,int>}
     */
    public static function resolveProviderOutcome(
        PDO $db,
        int $providerFileId,
        array $consumerImports,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        if ($providerFileId < 1 || $consumerImports === []) {
            return ['matches' => [], 'redirectors' => []];
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
        return self::resolveInMemoryOutcome(
            $consumerImports,
            (array)($snapshot['imports'] ?? []),
            (array)($snapshot['exports'] ?? []),
            (string)($file['package_name'] ?? ''),
            $consumerExports,
            $consumerGraphImports
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
        string $providerPackageName,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        return self::resolveInMemoryOutcome(
            $consumerImports,
            $providerImports,
            $providerExports,
            $providerPackageName,
            $consumerExports,
            $consumerGraphImports
        )['matches'];
    }

    /**
     * Deterministic table-level part of VerifyImport + VerifyImportInner.
     * A matching ObjectRedirector is reported separately because UE4 must
     * preload its UObject payload and validate DestinationObject before the
     * original Import can be considered resolved.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @param list<array<string,mixed>> $consumerExports
     * @param list<array<string,mixed>> $consumerGraphImports
     * @return array{matches:array<int,int>,redirectors:array<int,int>}
     */
    public static function resolveInMemoryOutcome(
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName,
        array $consumerExports = [],
        array $consumerGraphImports = []
    ): array {
        $imports = self::indexRows($consumerImports, 'import_index');
        $graphImports = self::indexRows(
            $consumerGraphImports !== [] ? $consumerGraphImports : $consumerImports,
            'import_index'
        );
        $providerImportsByIndex = self::indexRows($providerImports, 'import_index');
        $providerExportsByIndex = self::indexRows($providerExports, 'export_index');
        $consumerExportsByIndex = self::indexRows($consumerExports, 'export_index');

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
            self::resolveImportIndex(
                (int)$importIndex,
                $imports,
                $consumerExportsByIndex,
                $graphImports,
                $candidates,
                $resolved,
                $visiting
            );
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if (is_int($exportIndex) && $exportIndex >= 0) {
                $matches[(int)$importIndex] = $exportIndex;
            }
        }

        $redirectors = [];
        foreach ($imports as $importIndex => $import) {
            $importIndex = (int)$importIndex;
            if (isset($matches[$importIndex])) {
                continue;
            }
            $objectName = trim((string)($import['object_name'] ?? ''));
            $className = trim((string)($import['class_name'] ?? ''));
            $classPackage = trim((string)($import['class_package'] ?? ''));
            if ($objectName === '' || $className === '' || $classPackage === ''
                || self::key($objectName) === self::key('ObjectRedirector')) {
                continue;
            }
            $outerIndex = (int)($import['outer_index'] ?? 0);
            if ($outerIndex >= 0) {
                continue;
            }
            $parentIndex = -$outerIndex - 1;
            $parentSource = $resolved[$parentIndex] ?? null;
            if (!is_int($parentSource) || $parentSource === self::PRIVATE_FAILURE) {
                continue;
            }
            $expectedOuter = $parentSource === self::TOP_LEVEL_PACKAGE ? 0 : $parentSource + 1;
            $redirector = self::findCandidate(
                $candidates,
                $objectName,
                'ObjectRedirector',
                '/Script/CoreUObject',
                $expectedOuter,
                self::privateImportAllowed($importIndex, $graphImports, $consumerExportsByIndex)
            );
            if (is_int($redirector) && $redirector >= 0) {
                $redirectors[$importIndex] = $redirector;
            }
        }

        return ['matches' => $matches, 'redirectors' => $redirectors];
    }

    /**
     * UE4 editor-only private-import exception used by VerifyImportInner.
     * The predicates mirror FLinker::ImportIsInAnyExport, AnyExportIsInImport,
     * and AnyExportShareOuterWithImport over serialized FPackageIndex graphs.
     *
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $consumerExports
     */
    public static function privateImportAllowedInMemory(
        int $importIndex,
        array $consumerImports,
        array $consumerExports
    ): bool {
        return self::privateImportAllowed(
            $importIndex,
            self::indexRows($consumerImports, 'import_index'),
            self::indexRows($consumerExports, 'export_index')
        );
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
            $classOuter = (int)($classImport['outer_index'] ?? 0);
            if ($classOuter === 0) {
                return ['', $className];
            }
            $classPackageResource = $classOuter < 0
                ? ($providerImports[-$classOuter - 1] ?? null)
                : ($providerExports[$classOuter - 1] ?? null);
            return [
                is_array($classPackageResource)
                    ? trim((string)($classPackageResource['object_name'] ?? ''))
                    : '',
                $className,
            ];
        }

        $classExport = $providerExports[$classIndex - 1] ?? null;
        if (!is_array($classExport)) {
            return ['', ''];
        }
        return [trim($providerPackageName), trim((string)($classExport['object_name'] ?? ''))];
    }


    /** @param array<int,array<string,mixed>> $imports @param array<int,array<string,mixed>> $exports */
    private static function privateImportAllowed(int $importIndex, array $imports, array $exports): bool
    {
        return self::importIsInAnyExport($importIndex, $imports, $exports)
            || self::anyExportIsInImport($importIndex, $imports, $exports)
            || self::anyExportShareOuterWithImport($importIndex, $imports, $exports);
    }

    /** Mirrors FLinker::ImportIsInAnyExport. */
    private static function importIsInAnyExport(int $importIndex, array $imports, array $exports): bool
    {
        if (!isset($imports[$importIndex])) {
            return false;
        }
        $linkerIndex = (int)($imports[$importIndex]['outer_index'] ?? 0);
        $seen = [];
        while ($linkerIndex !== 0) {
            if (isset($seen[$linkerIndex])) {
                return false;
            }
            $seen[$linkerIndex] = true;
            $outer = self::resourceOuterIndex($linkerIndex, $imports, $exports);
            if ($outer === null) {
                return false;
            }
            $linkerIndex = $outer;
            if ($linkerIndex > 0) {
                return true;
            }
        }
        return false;
    }

    /** Mirrors FLinker::AnyExportIsInImport. */
    private static function anyExportIsInImport(int $importIndex, array $imports, array $exports): bool
    {
        $outerIndex = -$importIndex - 1;
        foreach (array_keys($exports) as $exportIndex) {
            if (self::resourceIsIn((int)$exportIndex + 1, $outerIndex, $imports, $exports)) {
                return true;
            }
        }
        return false;
    }

    /** Mirrors FLinker::AnyExportShareOuterWithImport. */
    private static function anyExportShareOuterWithImport(int $importIndex, array $imports, array $exports): bool
    {
        $importResource = -$importIndex - 1;
        $importOutermost = self::resourceGetOutermost($importResource, $imports, $exports);
        if ($importOutermost === null) {
            return false;
        }
        foreach ($exports as $exportIndex => $export) {
            if ((int)($export['outer_index'] ?? 0) >= 0) {
                continue;
            }
            $exportOutermost = self::resourceGetOutermost((int)$exportIndex + 1, $imports, $exports);
            if ($exportOutermost !== null && $exportOutermost === $importOutermost) {
                return true;
            }
        }
        return false;
    }

    /** Mirrors FLinker::ResourceGetOutermost. */
    private static function resourceGetOutermost(int $linkerIndex, array $imports, array $exports): ?int
    {
        if ($linkerIndex === 0) {
            return 0;
        }
        $seen = [];
        while (true) {
            if (isset($seen[$linkerIndex])) {
                return null;
            }
            $seen[$linkerIndex] = true;
            $outer = self::resourceOuterIndex($linkerIndex, $imports, $exports);
            if ($outer === null) {
                return null;
            }
            if ($outer === 0) {
                return $linkerIndex;
            }
            $linkerIndex = $outer;
        }
    }

    /** Mirrors FLinker::ResourceIsIn, including its first-outer step. */
    private static function resourceIsIn(
        int $linkerIndex,
        int $outerIndex,
        array $imports,
        array $exports
    ): bool {
        $current = self::resourceOuterIndex($linkerIndex, $imports, $exports);
        if ($current === null) {
            return false;
        }
        $seen = [];
        while ($current !== 0) {
            if (isset($seen[$current])) {
                return false;
            }
            $seen[$current] = true;
            $next = self::resourceOuterIndex($current, $imports, $exports);
            if ($next === null) {
                return false;
            }
            $current = $next;
            if ($current === $outerIndex) {
                return true;
            }
        }
        return false;
    }

    /** FPackageIndex resource lookup: negative=Import, positive=Export, zero=null. */
    private static function resourceOuterIndex(int $linkerIndex, array $imports, array $exports): ?int
    {
        if ($linkerIndex === 0) {
            return 0;
        }
        if ($linkerIndex < 0) {
            $index = -$linkerIndex - 1;
            return isset($imports[$index]) ? (int)($imports[$index]['outer_index'] ?? 0) : null;
        }
        $index = $linkerIndex - 1;
        return isset($exports[$index]) ? (int)($exports[$index]['outer_index'] ?? 0) : null;
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
        array $consumerExports,
        array $consumerGraphImports,
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
        $parentSource = self::resolveImportIndex(
            $parentIndex,
            $imports,
            $consumerExports,
            $consumerGraphImports,
            $candidates,
            $resolved,
            $visiting
        );
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
            $expectedOuter,
            self::privateImportAllowed($index, $consumerGraphImports, $consumerExports)
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
        int $expectedOuter,
        bool $privateImportAllowed
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
            if ((((int)$candidate['object_flags']) & self::RF_PUBLIC) === 0 && !$privateImportAllowed) {
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
