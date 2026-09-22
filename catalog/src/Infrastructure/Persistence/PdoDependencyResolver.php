<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves package/object Imports against verified current-format catalog providers and export projections.
 * Why: Dependency resolution must use ue_package_providers/ue_export_lookup and current compact metadata only.
 * Role: Infrastructure current-metadata dependency resolver.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use PDOException;

final class PdoDependencyResolver
{
    private const MAX_VALUES_PER_QUERY = 500;
    private const MAX_OBJECT_PAIRS_PER_QUERY = 250;

    /**
     * @param list<array<string,mixed>> $imports
     * @return array<int,array{status:string,resolved_file_id:?int,resolved_export_id:?int,resolved_export_index:?int,source:string,confidence:string}>
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
                $key = self::normalizeLookup($rootPackage);
                if ($key !== '' && !isset($packageNames[$key])) {
                    $packageNames[$key] = $rootPackage;
                }
            }

            $relativeObjectPath = trim((string)($import['relative_object_path'] ?? ''));
            $fullPath = trim((string)($import['full_path'] ?? ''));
            if ($rootPackage !== '' && $relativeObjectPath !== '' && $fullPath !== '') {
                $key = self::normalizeLookup($fullPath);
                if ($key !== '' && !isset($objectLookups[$key])) {
                    $objectLookups[$key] = [
                        'lookup_value' => $fullPath,
                        'package_name' => $rootPackage,
                        'local_path' => $relativeObjectPath,
                        'class_package' => trim((string)($import['class_package'] ?? '')),
                        'class_name' => trim((string)($import['class_name'] ?? '')),
                    ];
                }
            }
        }

        $packageMatches = self::loadPackageMatches($db, $gameId, $fileId, array_values($packageNames));
        $packageRequirements = [];
        foreach ($objectLookups as $lookup) {
            $packageKey = self::normalizeLookup($lookup['package_name']);
            $packageRequirements[$packageKey]['package_name'] ??= $lookup['package_name'];
            $packageRequirements[$packageKey]['paths'][] = $lookup['local_path'];
            $packageRequirements[$packageKey]['classes'][$lookup['local_path']] = [
                'class_package' => (string)($lookup['class_package'] ?? ''),
                'class_name' => (string)($lookup['class_name'] ?? ''),
            ];
        }
        $completeProviders = [];
        foreach ($packageRequirements as $packageKey => $requirement) {
            $provider = PdoPackageObjectCoverageResolver::chooseCompleteProvider(
                $db,
                $gameId,
                (string)$requirement['package_name'],
                array_values(array_unique((array)$requirement['paths'])),
                $fileId,
                (array)($requirement['classes'] ?? [])
            );
            if ($provider !== null) {
                $completeProviders[$packageKey] = $provider;
            }
        }
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
                $packageKey = self::normalizeLookup($rootPackage);
                $completeProvider = $completeProviders[$packageKey] ?? null;
                $relativeKey = self::normalizeLookup((string)($import['relative_object_path'] ?? ''));
                $exportIndex = is_array($completeProvider)
                    ? (($completeProvider['matched_exports'][$relativeKey] ?? null))
                    : null;
                $exportMatch = $exportIndex !== null
                    ? [
                        'file_id' => (int)$completeProvider['file_id'],
                        'export_index' => (int)$exportIndex,
                        'source' => 'complete_package_object',
                    ]
                    : null;
                if ($exportMatch !== null) {
                    $result = [
                        'status' => 'resolved',
                        'resolved_file_id' => $exportMatch['file_id'],
                        'resolved_export_id' => null,
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

    /** @param array<string,mixed> $import */
    private static function isCommonImport(array $import): bool
    {
        if ((int)($import['is_common'] ?? 0) === 1) {
            return true;
        }
        return str_starts_with(strtolower(trim((string)($import['root_package'] ?? ''))), '/script/');
    }

    /** @param list<string> $packageNames @return array<string,array{file_id:int,source:string}> */
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
            if ($missing !== []) {
                $rows = \catalog_all(
                    $db,
                    'SELECT f.package_name lookup_value,f.id file_id,"primary" source_kind'
                    . ' FROM ue_files f'
                    . ' WHERE f.game_id=? AND f.scan_status="verified"'
                    . ' AND f.package_name IN (' . self::placeholders(count($missing)) . ')'
                    . ' ORDER BY f.package_name,(f.id=?) DESC,f.uploaded_at DESC',
                    array_merge([$gameId], $missing, [$fileId])
                );
                foreach ($rows as $row) {
                    self::collectPackageMatch($row, $matches);
                }
            }

            $missing = self::missingLookupValues($missing, $matches);
            if ($missing !== []) {
                $rows = \catalog_all(
                    $db,
                    'SELECT a.package_name lookup_value,a.file_id,"alias" source_kind'
                    . ' FROM ue_file_package_aliases a'
                    . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id'
                    . ' WHERE a.game_id=? AND f.scan_status="verified"'
                    . ' AND a.package_name IN (' . self::placeholders(count($missing)) . ')'
                    . ' ORDER BY a.package_name,(f.id=?) DESC,f.uploaded_at DESC,a.id ASC',
                    array_merge([$gameId], $missing, [$fileId])
                );
                foreach ($rows as $row) {
                    self::collectPackageMatch($row, $matches);
                }
            }
        }
        return $matches;
    }

    /** @param array<string,mixed> $row @param array<string,array{file_id:int,source:string}> $matches */
    private static function collectPackageMatch(array $row, array &$matches): void
    {
        $key = self::normalizeLookup((string)($row['lookup_value'] ?? ''));
        if ($key === '' || isset($matches[$key])) {
            return;
        }
        $matches[$key] = [
            'file_id' => (int)$row['file_id'],
            'source' => (string)($row['source_kind'] ?? '') === 'alias'
                ? 'exact_package_alias'
                : 'exact_package',
        ];
    }

    /** @param list<string|int> $values @param array<string,mixed> $matches @return list<string> */
    private static function missingLookupValues(array $values, array $matches): array
    {
        $missing = [];
        foreach ($values as $value) {
            $value = (string)$value;
            if (!isset($matches[self::normalizeLookup($value)])) {
                $missing[] = $value;
            }
        }
        return $missing;
    }

    private static function normalizeLookup(string|int $value): string
    {
        $value = (string)$value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
