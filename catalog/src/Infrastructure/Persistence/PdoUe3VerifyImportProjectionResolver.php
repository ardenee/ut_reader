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
    private const PRIVATE_FAILURE = -2147483648;

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
        $paths = [];
        foreach ($consumerImports as $fallback => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row['import_index']) ? (int)$row['import_index'] : (int)$fallback;
            $imports[$index] = $row;
            $relative = trim((string)($row['relative_object_path'] ?? ''));
            if ($relative !== '') {
                $paths[self::key($relative)] = $relative;
            }
        }

        $candidates = self::loadCandidates($db, $providerFileId, $paths);
        $resolved = [];
        $visiting = [];
        foreach (array_keys($imports) as $importIndex) {
            self::resolveImport((int)$importIndex, $imports, $candidates, $resolved, $visiting);
        }

        $matches = [];
        foreach ($resolved as $importIndex => $exportIndex) {
            if ($exportIndex !== null && $exportIndex !== self::PRIVATE_FAILURE) {
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
        string $providerPackageName
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
        $providerExportsByIndex = [];
        foreach ($providerExports as $fallback => $row) {
            if (is_array($row)) {
                $providerExportsByIndex[isset($row['export_index']) ? (int)$row['export_index'] : (int)$fallback] = $row;
            }
        }

        $candidates = [];
        foreach ($providerExportsByIndex as $exportIndex => $export) {
            $relative = self::key((string)($export['local_path'] ?? ''));
            if ($relative === '') {
                continue;
            }
            [$classPackage, $className] =
                \UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
                    $export,
                    $providerImportsByIndex,
                    $providerExportsByIndex,
                    $providerPackageName
                );
            $candidates[$relative][] = [
                'export_index' => (int)$exportIndex,
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
            if ($exportIndex !== null && $exportIndex !== self::PRIVATE_FAILURE) {
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
        if ($outerIndex === 0) {
            // Core.Package imports establish SourceLinker but never SourceIndex.
            return $resolved[$importIndex] = null;
        }
        if ($outerIndex > 0) {
            // UE3 cooked packages can contain import->export outers. The audited
            // source explicitly returns here with a TODO instead of inventing a
            // provider-linker resolution path, so the catalog does the same.
            return $resolved[$importIndex] = null;
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
        if ($parentSourceIndex === self::PRIVATE_FAILURE) {
            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = self::PRIVATE_FAILURE;
        }

        $relative = self::key((string)($import['relative_object_path'] ?? ''));
        foreach ($candidates[$relative] ?? [] as $candidate) {
            if (self::key((string)($candidate['class_name'] ?? '')) !== self::key($className)
                || self::key((string)($candidate['class_package'] ?? '')) !== self::key($classPackage)) {
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
                return $resolved[$importIndex] = self::PRIVATE_FAILURE;
            }

            unset($visiting[$importIndex]);
            return $resolved[$importIndex] = (int)$candidate['export_index'];
        }

        unset($visiting[$importIndex]);
        return $resolved[$importIndex] = null;
    }

    /**
     * @param array<string,string> $paths normalized path => original path
     * @return array<string,list<array<string,mixed>>>
     */
    private static function loadCandidates(PDO $db, int $providerFileId, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $hashes = [];
        foreach ($paths as $key => $path) {
            $hashes[bin2hex(CatalogUnrealIdentityHash::objectPathBinary($path))] = $key;
        }

        $result = [];
        foreach (array_chunk($hashes, self::HASH_BATCH_SIZE, true) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), 'UNHEX(?)'));
            $statement = $db->prepare(
                'SELECT l.export_index,l.path_hash_ci,l.outer_index,l.object_flags,'
                . 'pt.value_prefix local_path,cpt.value_prefix class_package,cnt.value_prefix class_name'
                . ' FROM ue_export_path_lookup l'
                . ' JOIN ue_terms pt ON pt.id=l.local_path_term_id'
                . ' LEFT JOIN ue_terms cpt ON cpt.id=l.class_package_term_id'
                . ' LEFT JOIN ue_terms cnt ON cnt.id=l.class_name_term_id'
                . ' WHERE l.file_id=? AND l.path_hash_ci IN (' . $placeholders . ')'
                . ' ORDER BY l.export_index DESC'
            );
            $statement->execute(array_merge([$providerFileId], array_keys($chunk)));
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $hash = bin2hex((string)$row['path_hash_ci']);
                $expectedKey = $chunk[$hash] ?? null;
                if (!is_string($expectedKey)
                    || self::key((string)$row['local_path']) !== $expectedKey) {
                    continue;
                }
                $result[$expectedKey][] = [
                    'export_index' => (int)$row['export_index'],
                    'outer_index' => (int)($row['outer_index'] ?? 0),
                    'object_flags' => (int)($row['object_flags'] ?? 0),
                    'class_package' => (string)($row['class_package'] ?? ''),
                    'class_name' => (string)($row['class_name'] ?? ''),
                ];
            }
        }
        return $result;
    }

    private static function key(string $value): string
    {
        return CatalogUnrealIdentityHash::nameKey(trim($value));
    }
}
