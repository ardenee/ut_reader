<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;

/**
 * Resolves one file's import table against the catalogue in bounded batches.
 *
 * Resolution follows Unreal's serialized package/object identity model. Package
 * providers are read from the materialized ue_package_providers table, which
 * combines verified primary package names and aliases into one indexed lookup.
 * Object resolution remains exact against serialized export paths.
 */
final class CatalogDependencyResolver
{
    private const MAX_VALUES_PER_QUERY = 500;
    private const MAX_OBJECT_PAIRS_PER_QUERY = 250;

    /**
     * @param list<array<string, mixed>> $imports
     * @return array<int, array{status:string, resolved_file_id:?int, resolved_export_id:?int, source:string, confidence:string}>
     */
    public static function resolve(PDO $db, int $gameId, int $fileId, array $imports): array
    {
        $packageNames = [];
        $objectLookups = [];

        foreach ($imports as $import) {
            if (self::isCommonImport($import)) {
                continue;
            }

            $rootPackage = trim((string)($import['root_package'] ?? ''));
            if ($rootPackage !== '') {
                $packageNames[$rootPackage] = true;
            }

            $relativeObjectPath = trim((string)($import['relative_object_path'] ?? ''));
            $fullPath = trim((string)($import['full_path'] ?? ''));
            if ($rootPackage !== '' && $relativeObjectPath !== '' && $fullPath !== '') {
                $objectLookups[$fullPath] = [
                    'lookup_value' => $fullPath,
                    'package_name' => $rootPackage,
                    'local_path' => $relativeObjectPath,
                ];
            }
        }

        $packageMatches = self::loadPackageMatches(
            $db,
            $gameId,
            $fileId,
            array_keys($packageNames)
        );
        $exportMatches = self::loadExportMatches(
            $db,
            $gameId,
            $fileId,
            array_values($objectLookups)
        );

        $resolved = [];
        foreach ($imports as $import) {
            $importId = (int)($import['id'] ?? 0);
            if ($importId < 1) {
                continue;
            }

            $rootPackage = (string)($import['root_package'] ?? '');
            $fullPath = (string)($import['full_path'] ?? '');
            $isObjectImport = (string)($import['relative_object_path'] ?? '') !== '';
            $result = self::missing();

            if (self::isCommonImport($import)) {
                $result = [
                    'status' => 'common',
                    'resolved_file_id' => null,
                    'resolved_export_id' => null,
                    'source' => 'common_script',
                    'confidence' => 'common',
                ];
            } elseif (!$isObjectImport) {
                $packageMatch = $packageMatches[self::normalizeLookup($rootPackage)] ?? null;
                if ($packageMatch !== null) {
                    $result = [
                        'status' => 'package_only',
                        'resolved_file_id' => $packageMatch['file_id'],
                        'resolved_export_id' => null,
                        'source' => $packageMatch['source'],
                        'confidence' => 'exact',
                    ];
                }
            } else {
                $exportMatch = $exportMatches[self::normalizeLookup($fullPath)] ?? null;
                if ($exportMatch !== null) {
                    $result = [
                        'status' => 'resolved',
                        'resolved_file_id' => $exportMatch['file_id'],
                        'resolved_export_id' => $exportMatch['export_id'],
                        'source' => $exportMatch['source'],
                        'confidence' => 'exact',
                    ];
                }
            }

            $resolved[$importId] = $result;
        }

        return $resolved;
    }

    /** @return array{status:string, resolved_file_id:?int, resolved_export_id:?int, source:string, confidence:string} */
    private static function missing(): array
    {
        return [
            'status' => 'missing',
            'resolved_file_id' => null,
            'resolved_export_id' => null,
            'source' => 'none',
            'confidence' => 'missing',
        ];
    }

    /** @param array<string, mixed> $import */
    private static function isCommonImport(array $import): bool
    {
        if ((int)($import['is_common'] ?? 0) === 1) {
            return true;
        }

        $rootPackage = strtolower(trim((string)($import['root_package'] ?? '')));
        return str_starts_with($rootPackage, '/script/');
    }

    /**
     * @param list<string> $packageNames
     * @return array<string, array{file_id:int, source:string}>
     */
    private static function loadPackageMatches(PDO $db, int $gameId, int $fileId, array $packageNames): array
    {
        $matches = [];
        foreach (array_chunk($packageNames, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $placeholders = self::placeholders(count($chunk));
            $rows = \catalog_all(
                $db,
                'SELECT p.package_name lookup_value,p.file_id,p.source_kind'
                . ' FROM ue_package_providers p'
                . ' JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id'
                . ' WHERE p.game_id=? AND f.scan_status="verified"'
                . ' AND p.package_name IN (' . $placeholders . ')'
                . ' ORDER BY p.package_name,(p.source_kind="primary") DESC,'
                . ' (p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC',
                array_merge([$gameId], $chunk, [$fileId])
            );

            foreach ($rows as $row) {
                $lookupKey = self::normalizeLookup((string)$row['lookup_value']);
                if ($lookupKey === '' || isset($matches[$lookupKey])) {
                    continue;
                }
                $matches[$lookupKey] = [
                    'file_id' => (int)$row['file_id'],
                    'source' => (string)$row['source_kind'] === 'alias'
                        ? 'exact_package_alias'
                        : 'exact_package',
                ];
            }
        }

        return $matches;
    }

    /**
     * @param list<array{lookup_value:string, package_name:string, local_path:string}> $objectLookups
     * @return array<string, array{file_id:int, export_id:int, source:string}>
     */
    private static function loadExportMatches(PDO $db, int $gameId, int $fileId, array $objectLookups): array
    {
        $matches = [];
        foreach (array_chunk($objectLookups, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $fullPaths = array_values(array_map(
                static fn(array $lookup): string => $lookup['lookup_value'],
                $chunk
            ));
            $rows = \catalog_all(
                $db,
                'SELECT e.full_path lookup_value,e.id export_id,f.id file_id'
                . ' FROM ue_exports e'
                . ' JOIN ue_files f ON f.id=e.file_id'
                . ' WHERE f.game_id=? AND f.scan_status="verified"'
                . ' AND e.full_path IN (' . self::placeholders(count($fullPaths)) . ')'
                . ' ORDER BY e.full_path,(f.id=?) DESC,f.uploaded_at DESC,e.export_index ASC',
                array_merge([$gameId], $fullPaths, [$fileId])
            );
            self::collectExportMatches($rows, 'exact_object', $matches);
        }

        foreach (array_chunk($objectLookups, self::MAX_OBJECT_PAIRS_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $pairSql = [];
            $args = [$gameId];
            foreach ($chunk as $lookup) {
                $pairSql[] = '(a.package_name=? AND e.local_path=?)';
                $args[] = $lookup['package_name'];
                $args[] = $lookup['local_path'];
            }
            $args[] = $fileId;

            $rows = \catalog_all(
                $db,
                'SELECT CONCAT(a.package_name,".",e.local_path) lookup_value,'
                . ' e.id export_id,f.id file_id'
                . ' FROM ue_file_package_aliases a'
                . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id'
                . ' JOIN ue_exports e ON e.file_id=f.id'
                . ' WHERE a.game_id=? AND f.scan_status="verified" AND ('
                . implode(' OR ', $pairSql) . ')'
                . ' ORDER BY lookup_value,(f.id=?) DESC,f.uploaded_at DESC,'
                . ' e.export_index ASC,a.id ASC',
                $args
            );
            self::collectExportMatches($rows, 'exact_object_alias', $matches);
        }

        return $matches;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string, array{file_id:int, export_id:int, source:string}> $matches
     */
    private static function collectExportMatches(array $rows, string $source, array &$matches): void
    {
        foreach ($rows as $row) {
            $lookupKey = self::normalizeLookup((string)($row['lookup_value'] ?? ''));
            if ($lookupKey === '' || isset($matches[$lookupKey])) {
                continue;
            }
            $matches[$lookupKey] = [
                'file_id' => (int)$row['file_id'],
                'export_id' => (int)$row['export_id'],
                'source' => $source,
            ];
        }
    }

    private static function normalizeLookup(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
