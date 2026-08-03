<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;
use PDOException;

/**
 * Resolves one file's import table against the catalogue in bounded batches.
 *
 * Package providers are read from the materialized provider table with exact
 * authoritative fallbacks. Object resolution prefers compact Export projections
 * and returns Unreal's serialized export_index; the legacy ue_exports identifier
 * is retained only while transition rows still exist.
 */
final class CatalogDependencyResolver
{
    private const MAX_VALUES_PER_QUERY = 500;
    private const MAX_OBJECT_PAIRS_PER_QUERY = 250;

    /**
     * @param list<array<string, mixed>> $imports
     * @return array<int, array{
     *   status:string,
     *   resolved_file_id:?int,
     *   resolved_export_id:?int,
     *   resolved_export_index:?int,
     *   source:string,
     *   confidence:string
     * }>
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
                $packageKey = self::normalizeLookup($rootPackage);
                if ($packageKey !== '' && !isset($packageNames[$packageKey])) {
                    $packageNames[$packageKey] = $rootPackage;
                }
            }

            $relativeObjectPath = trim((string)($import['relative_object_path'] ?? ''));
            $fullPath = trim((string)($import['full_path'] ?? ''));
            if ($rootPackage !== '' && $relativeObjectPath !== '' && $fullPath !== '') {
                $lookupKey = self::normalizeLookup($fullPath);
                if ($lookupKey !== '' && !isset($objectLookups[$lookupKey])) {
                    $objectLookups[$lookupKey] = [
                        'lookup_value' => $fullPath,
                        'package_name' => $rootPackage,
                        'local_path' => $relativeObjectPath,
                    ];
                }
            }
        }

        $packageMatches = self::loadPackageMatches(
            $db,
            $gameId,
            $fileId,
            array_values($packageNames)
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
                    'resolved_export_index' => null,
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
                        'resolved_export_index' => null,
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
                        'resolved_export_index' => $exportMatch['export_index'],
                        'source' => $exportMatch['source'],
                        'confidence' => 'exact',
                    ];
                }
            }

            $resolved[$importId] = $result;
        }

        return $resolved;
    }

    /** @return array{status:string,resolved_file_id:?int,resolved_export_id:?int,resolved_export_index:?int,source:string,confidence:string} */
    private static function missing(): array
    {
        return [
            'status' => 'missing',
            'resolved_file_id' => null,
            'resolved_export_id' => null,
            'resolved_export_index' => null,
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
            try {
                $rows = \catalog_all(
                    $db,
                    'SELECT p.package_name lookup_value,p.file_id,p.source_kind'
                    . ' FROM ue_package_providers p'
                    . ' JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id'
                    . ' LEFT JOIN ue_file_package_aliases a'
                    . ' ON p.source_kind="alias" AND a.id=p.source_id'
                    . ' AND a.file_id=p.file_id AND a.game_id=p.game_id'
                    . ' AND a.package_name=p.package_name'
                    . ' WHERE p.game_id=? AND f.scan_status="verified"'
                    . ' AND p.package_name IN (' . $placeholders . ')'
                    . ' AND ((p.source_kind="primary" AND f.package_name=p.package_name)'
                    . ' OR (p.source_kind="alias" AND a.id IS NOT NULL))'
                    . ' ORDER BY p.package_name,(p.source_kind="primary") DESC,'
                    . ' (p.file_id=?) DESC,p.provider_created_at DESC,p.source_id ASC',
                    array_merge([$gameId], $chunk, [$fileId])
                );
            } catch (PDOException) {
                $rows = [];
            }

            foreach ($rows as $row) {
                self::collectPackageMatch($row, $matches);
            }

            $missing = self::missingLookupValues($chunk, $matches);
            if ($missing === []) {
                continue;
            }

            $primaryRows = \catalog_all(
                $db,
                'SELECT f.package_name lookup_value,f.id file_id,"primary" source_kind'
                . ' FROM ue_files f'
                . ' WHERE f.game_id=? AND f.scan_status="verified"'
                . ' AND f.package_name IN (' . self::placeholders(count($missing)) . ')'
                . ' ORDER BY f.package_name,(f.id=?) DESC,f.uploaded_at DESC',
                array_merge([$gameId], $missing, [$fileId])
            );
            foreach ($primaryRows as $row) {
                self::collectPackageMatch($row, $matches);
            }

            $missing = self::missingLookupValues($missing, $matches);
            if ($missing === []) {
                continue;
            }

            $aliasRows = \catalog_all(
                $db,
                'SELECT a.package_name lookup_value,a.file_id,"alias" source_kind'
                . ' FROM ue_file_package_aliases a'
                . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id'
                . ' WHERE a.game_id=? AND f.scan_status="verified"'
                . ' AND a.package_name IN (' . self::placeholders(count($missing)) . ')'
                . ' ORDER BY a.package_name,(f.id=?) DESC,f.uploaded_at DESC,a.id ASC',
                array_merge([$gameId], $missing, [$fileId])
            );
            foreach ($aliasRows as $row) {
                self::collectPackageMatch($row, $matches);
            }
        }

        return $matches;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string, array{file_id:int, source:string}> $matches
     */
    private static function collectPackageMatch(array $row, array &$matches): void
    {
        $lookupKey = self::normalizeLookup((string)($row['lookup_value'] ?? ''));
        if ($lookupKey === '' || isset($matches[$lookupKey])) {
            return;
        }
        $matches[$lookupKey] = [
            'file_id' => (int)$row['file_id'],
            'source' => (string)($row['source_kind'] ?? '') === 'alias'
                ? 'exact_package_alias'
                : 'exact_package',
        ];
    }

    /**
     * @param list<string|int> $values
     * @param array<string,mixed> $matches
     * @return list<string>
     */
    private static function missingLookupValues(array $values, array $matches): array
    {
        $missing = [];
        foreach ($values as $value) {
            $stringValue = (string)$value;
            if (!isset($matches[self::normalizeLookup($stringValue)])) {
                $missing[] = $stringValue;
            }
        }
        return $missing;
    }

    /**
     * @param list<array{lookup_value:string,package_name:string,local_path:string}> $objectLookups
     * @return array<string, array{file_id:int,export_id:?int,export_index:int,source:string}>
     */
    private static function loadExportMatches(PDO $db, int $gameId, int $fileId, array $objectLookups): array
    {
        $matches = [];

        self::loadCompactPrimaryExportMatches($db, $gameId, $fileId, $objectLookups, $matches);
        self::loadCompactAliasExportMatches($db, $gameId, $fileId, $objectLookups, $matches);

        $missingLookups = array_values(array_filter(
            $objectLookups,
            static fn(array $lookup): bool => !isset($matches[self::normalizeLookup($lookup['lookup_value'])])
        ));

        foreach (array_chunk($missingLookups, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $fullPaths = array_values(array_map(
                static fn(array $lookup): string => $lookup['lookup_value'],
                $chunk
            ));
            $rows = \catalog_all(
                $db,
                'SELECT e.full_path lookup_value,e.id export_id,e.export_index,f.id file_id'
                . ' FROM ue_exports e'
                . ' JOIN ue_files f ON f.id=e.file_id'
                . ' WHERE f.game_id=? AND f.scan_status="verified"'
                . ' AND e.full_path IN (' . self::placeholders(count($fullPaths)) . ')'
                . ' ORDER BY e.full_path,(f.id=?) DESC,f.uploaded_at DESC,e.export_index ASC',
                array_merge([$gameId], $fullPaths, [$fileId])
            );
            self::collectExportMatches($rows, 'exact_object_legacy', $matches);
        }

        $missingLookups = array_values(array_filter(
            $objectLookups,
            static fn(array $lookup): bool => !isset($matches[self::normalizeLookup($lookup['lookup_value'])])
        ));
        foreach (array_chunk($missingLookups, self::MAX_OBJECT_PAIRS_PER_QUERY) as $chunk) {
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
                . ' e.id export_id,e.export_index,f.id file_id'
                . ' FROM ue_file_package_aliases a'
                . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id'
                . ' JOIN ue_exports e ON e.file_id=f.id'
                . ' WHERE a.game_id=? AND f.scan_status="verified" AND ('
                . implode(' OR ', $pairSql) . ')'
                . ' ORDER BY lookup_value,(f.id=?) DESC,f.uploaded_at DESC,'
                . ' e.export_index ASC,a.id ASC',
                $args
            );
            self::collectExportMatches($rows, 'exact_object_alias_legacy', $matches);
        }

        return $matches;
    }

    /**
     * @param list<array{lookup_value:string,package_name:string,local_path:string}> $objectLookups
     * @param array<string,array{file_id:int,export_id:?int,export_index:int,source:string}> $matches
     */
    private static function loadCompactPrimaryExportMatches(
        PDO $db,
        int $gameId,
        int $fileId,
        array $objectLookups,
        array &$matches
    ): void {
        foreach (array_chunk($objectLookups, self::MAX_OBJECT_PAIRS_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $pairSql = [];
            $args = [$gameId];
            $keys = [];
            foreach ($chunk as $lookup) {
                $localPath = $lookup['local_path'];
                $pairSql[] = '(f.package_name=? AND t.value_hash=? AND t.value_length=? AND t.value_prefix=?)';
                array_push($args, $lookup['package_name'], md5($localPath, true), strlen($localPath), substr($localPath, 0, 200));
                $keys[self::compactPairKey($lookup['package_name'], $localPath)] = $lookup['lookup_value'];
            }
            $args[] = $fileId;

            try {
                $rows = \catalog_all(
                    $db,
                    'SELECT f.package_name,l.file_id,l.export_index,t.value_hash,t.value_length,le.id export_id'
                    . ' FROM ue_export_lookup l'
                    . ' JOIN ue_files f ON f.id=l.file_id'
                    . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
                    . ' JOIN ue_terms t ON t.id=l.local_path_term_id'
                    . ' LEFT JOIN ue_exports le ON le.file_id=l.file_id AND le.export_index=l.export_index'
                    . ' WHERE f.game_id=? AND f.scan_status="verified" AND (' . implode(' OR ', $pairSql) . ')'
                    . ' ORDER BY f.package_name,(f.id=?) DESC,f.uploaded_at DESC,l.export_index ASC',
                    $args
                );
            } catch (PDOException) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $pairKey = self::compactPairKeyFromHash(
                    (string)$row['package_name'],
                    (string)$row['value_hash'],
                    (int)$row['value_length']
                );
                $lookupValue = $keys[$pairKey] ?? null;
                if ($lookupValue === null) {
                    continue;
                }
                self::collectCompactExportMatch($lookupValue, $row, 'exact_object_compact', $matches);
            }
        }
    }

    /**
     * @param list<array{lookup_value:string,package_name:string,local_path:string}> $objectLookups
     * @param array<string,array{file_id:int,export_id:?int,export_index:int,source:string}> $matches
     */
    private static function loadCompactAliasExportMatches(
        PDO $db,
        int $gameId,
        int $fileId,
        array $objectLookups,
        array &$matches
    ): void {
        $missing = array_values(array_filter(
            $objectLookups,
            static fn(array $lookup): bool => !isset($matches[self::normalizeLookup($lookup['lookup_value'])])
        ));
        foreach (array_chunk($missing, self::MAX_OBJECT_PAIRS_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $pairSql = [];
            $args = [$gameId];
            $keys = [];
            foreach ($chunk as $lookup) {
                $localPath = $lookup['local_path'];
                $pairSql[] = '(a.package_name=? AND t.value_hash=? AND t.value_length=? AND t.value_prefix=?)';
                array_push($args, $lookup['package_name'], md5($localPath, true), strlen($localPath), substr($localPath, 0, 200));
                $keys[self::compactPairKey($lookup['package_name'], $localPath)] = $lookup['lookup_value'];
            }
            $args[] = $fileId;

            try {
                $rows = \catalog_all(
                    $db,
                    'SELECT a.package_name,l.file_id,l.export_index,t.value_hash,t.value_length,le.id export_id'
                    . ' FROM ue_file_package_aliases a'
                    . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id'
                    . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
                    . ' JOIN ue_export_lookup l ON l.file_id=f.id'
                    . ' JOIN ue_terms t ON t.id=l.local_path_term_id'
                    . ' LEFT JOIN ue_exports le ON le.file_id=l.file_id AND le.export_index=l.export_index'
                    . ' WHERE a.game_id=? AND f.scan_status="verified" AND (' . implode(' OR ', $pairSql) . ')'
                    . ' ORDER BY a.package_name,(f.id=?) DESC,f.uploaded_at DESC,l.export_index ASC,a.id ASC',
                    $args
                );
            } catch (PDOException) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $pairKey = self::compactPairKeyFromHash(
                    (string)$row['package_name'],
                    (string)$row['value_hash'],
                    (int)$row['value_length']
                );
                $lookupValue = $keys[$pairKey] ?? null;
                if ($lookupValue === null) {
                    continue;
                }
                self::collectCompactExportMatch($lookupValue, $row, 'exact_object_alias_compact', $matches);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array{file_id:int,export_id:?int,export_index:int,source:string}> $matches
     */
    private static function collectCompactExportMatch(
        string $lookupValue,
        array $row,
        string $source,
        array &$matches
    ): void {
        $lookupKey = self::normalizeLookup($lookupValue);
        if ($lookupKey === '' || isset($matches[$lookupKey])) {
            return;
        }
        $matches[$lookupKey] = [
            'file_id' => (int)$row['file_id'],
            'export_id' => isset($row['export_id']) && $row['export_id'] !== null ? (int)$row['export_id'] : null,
            'export_index' => (int)$row['export_index'],
            'source' => $source,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,array{file_id:int,export_id:?int,export_index:int,source:string}> $matches
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
                'export_id' => isset($row['export_id']) ? (int)$row['export_id'] : null,
                'export_index' => (int)($row['export_index'] ?? 0),
                'source' => $source,
            ];
        }
    }

    private static function compactPairKey(string $packageName, string $localPath): string
    {
        return self::normalizeLookup($packageName) . "\0" . md5($localPath) . ':' . strlen($localPath);
    }

    private static function compactPairKeyFromHash(string $packageName, string $hash, int $length): string
    {
        return self::normalizeLookup($packageName) . "\0" . bin2hex($hash) . ':' . $length;
    }

    private static function normalizeLookup(string|int $value): string
    {
        return mb_strtolower((string)$value, 'UTF-8');
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
