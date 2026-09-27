<?php
/**
 * Resolves UE3 Imports against the v4 export projection using the deterministic
 * file-backed portion of ULinkerLoad::VerifyImportInner.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

require_once dirname(__DIR__) . '/Metadata/CatalogUnrealIdentityHash.php';

final class PdoUe3VerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    private const HASH_BATCH_SIZE = 300;
    private const FAILURE_SENTINEL = -2147483648;

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @return array<int,int>
     */
    public static function resolveProvider(PDO $db, int $providerFileId, array $consumerImports): array
    {
        if ($providerFileId < 1 || $consumerImports === []) {
            return [];
        }

        $imports = [];
        $objectNames = [];
        foreach ($consumerImports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback;
            $imports[$index] = $row;
        }

        $imports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($imports);
        $objectNames = [];
        foreach ($imports as $row) {
            if (!is_array($row) || (int)($row['outer_index'] ?? 0) === 0) {
                continue;
            }
            $objectName = trim((string)($row['object_name'] ?? ''));
            if ($objectName !== '') {
                $objectNames[self::key($objectName)] = $objectName;
            }
        }
        $candidates = self::loadCandidates($db, $providerFileId, $objectNames);
        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $importIndex) {
            self::resolveImport((int)$importIndex, $imports, $candidates, $resolved, $visiting);
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if ($exportIndex !== null && $exportIndex !== self::FAILURE_SENTINEL) {
                $matches[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    /**
     * In-memory equivalent used while the provider package is being published.
     *
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
        ?int $providerPackageVersion = null
    ): array {
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
        $imports = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($imports);
        $providerImportsByIndex = \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3FixupImportMap($providerImportsByIndex);

        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (is_array($row)) {
                $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
            }
        }

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $objectKey = self::key((string)($export['object_name'] ?? ''));
            if ($objectKey === '') {
                continue;
            }
            [$classPackage, $className] =
                \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                    $export,
                    $providerImportsByIndex,
                    $providerExportsByIndex,
                    $providerPackageName,
                    $providerPackageVersion
                );
            $identityKey = self::identityKey((string)($export['object_name'] ?? ''), $className, $classPackage);
            $candidates[$identityKey][] = [
                'export_index' => (int)$exportIndex,
                'object_name' => (string)($export['object_name'] ?? ''),
                'outer_index' => (int)($export['outer_index'] ?? 0),
                'object_flags' => (int)($export['object_flags'] ?? 0),
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
            self::resolveImport((int)$importIndex, $imports, $candidates, $resolved, $visiting);
        }
        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if ($exportIndex !== null && $exportIndex !== self::FAILURE_SENTINEL) {
                $matches[(int)$importIndex] = (int)$exportIndex;
            }
        }
        return $matches;
    }

    /**
     * @param array<int,array<string,mixed>> $imports
     * @param array<string,list<array<string,mixed>>> $candidates
     * @param array<int,int|null> $resolved
     * @param array<int,true> $visiting
     */
    private static function resolveImport(
        int $importIndex,
        array $imports,
        array $candidates,
        array &$resolved,
        array &$visiting
    ): ?int {
        if (array_key_exists($importIndex, $resolved)) {
            return $resolved[$importIndex];
        }
        if (isset($visiting[$importIndex])) {
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $import = $imports[$importIndex] ?? null;
        if (!is_array($import)) {
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $objectName = trim((string)($import['object_name'] ?? ''));
        $className = trim((string)($import['class_name'] ?? ''));
        $classPackage = trim((string)($import['class_package'] ?? ''));
        if ($objectName === '' || $className === '' || $classPackage === '') {
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $outerIndex = (int)($import['outer_index'] ?? 0);
        if ($outerIndex === 0) {
            // UE3 VerifyImportInner requires top-level package imports to be
            // exactly Core.Package. A malformed root must not establish the
            // SourceLinker anchor used to qualify descendant imports.
            if (self::key($className) !== self::key('Package')
                || self::key($classPackage) !== self::key('Core')) {
                return $resolved[$importIndex] = self::FAILURE_SENTINEL;
            }
            // Valid package imports establish SourceLinker but never SourceIndex.
            return $resolved[$importIndex] = null;
        }
        if ($outerIndex > 0) {
            // UE3 cooked packages can contain import->export outers. The audited
            // source explicitly returns here with a TODO instead of inventing a
            // provider-linker resolution path, so the catalog does the same.
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $visiting[$importIndex] = true;
        $parentImportIndex = -$outerIndex - 1;
        $parentSourceIndex = self::resolveImport(
            $parentImportIndex,
            $imports,
            $candidates,
            $resolved,
            $visiting
        );
        if ($parentSourceIndex === self::FAILURE_SENTINEL) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::FAILURE_SENTINEL;
        }

        $identityKey = self::identityKey($objectName, $className, $classPackage);
        foreach ($candidates[$identityKey] ?? [] as $candidate) {
            if (self::identityKey(
                (string)($candidate['object_name'] ?? ''),
                (string)($candidate['class_name'] ?? ''),
                (string)($candidate['class_package'] ?? '')
            ) !== $identityKey) {
                continue;
            }

            $sourceOuter = (int)($candidate['outer_index'] ?? 0);
            if ($parentSourceIndex === null) {
                if ($sourceOuter !== 0) {
                    continue;
                }
            } elseif ($sourceOuter !== $parentSourceIndex + 1) {
                continue;
            }

            if ((((int)($candidate['object_flags'] ?? 0)) & self::RF_PUBLIC) === 0) {
                unset($visiting[$importIndex]);
                return $resolved[$importIndex] = self::FAILURE_SENTINEL;
            }

            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = (int)$candidate['export_index'];
        }

        unset($visiting[$importIndex]);
        // Only a valid top-level Core.Package import may carry SourceLinker with
        // SourceIndex == INDEX_NONE. Any unresolved non-root import must remain
        // a failure so descendants cannot be mistaken for root-level exports.
        return $resolved[$importIndex] = self::FAILURE_SENTINEL;
    }

    /**
     * Load UE3 candidates by serialized ObjectName, mirroring the first key of
     * VerifyImportInner. Derived full/local paths are deliberately not part of
     * provider-export identity; exact class identity and resolved OuterIndex are
     * qualified later by resolveImport().
     *
     * @param array<string,string> $objectNames normalized name => original name
     * @return array<string,list<array<string,mixed>>>
     */
    private static function loadCandidates(PDO $db, int $providerFileId, array $objectNames): array
    {
        if ($objectNames === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk(array_values($objectNames), self::HASH_BATCH_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $db->prepare(
                'SELECT e.export_index,ot.value_prefix object_name,l.outer_index,l.object_flags,'
                . 'cpt.value_prefix class_package,cnt.value_prefix class_name'
                . ' FROM ue_export_lookup e'
                . ' JOIN ue_export_path_lookup l ON l.file_id=e.file_id AND l.export_index=e.export_index'
                . ' JOIN ue_terms ot ON ot.id=e.object_term_id'
                . ' LEFT JOIN ue_terms cpt ON cpt.id=l.class_package_term_id'
                . ' LEFT JOIN ue_terms cnt ON cnt.id=l.class_name_term_id'
                . ' WHERE e.file_id=? AND CONVERT(ot.value_prefix USING utf8mb4)'
                . ' COLLATE utf8mb4_unicode_ci IN (' . $placeholders . ')'
                . ' ORDER BY e.export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], $chunk));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $objectKey = self::key((string)($row['object_name'] ?? ''));
                if ($objectKey === '' || !isset($objectNames[$objectKey])) {
                    continue;
                }
                $identityKey = self::identityKey(
                    (string)($row['object_name'] ?? ''),
                    (string)($row['class_name'] ?? ''),
                    (string)($row['class_package'] ?? '')
                );
                $result[$identityKey][] = [
                    'export_index' => (int)$row['export_index'],
                    'object_name' => (string)($row['object_name'] ?? ''),
                    'outer_index' => (int)($row['outer_index'] ?? 0),
                    'object_flags' => (int)($row['object_flags'] ?? 0),
                    'class_package' => (string)($row['class_package'] ?? ''),
                    'class_name' => (string)($row['class_name'] ?? ''),
                ];
            }
        }
        return $result;
    }

    private static function identityKey(string $objectName, string $className, string $classPackage): string
    {
        return CatalogUnrealIdentityHash::verifyImportHex($objectName, $className, $classPackage);
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::nameKey(trim($value));
    }
}
