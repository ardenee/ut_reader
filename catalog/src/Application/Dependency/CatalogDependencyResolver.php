<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;

/**
 * Resolves one file's import table against the catalog in batches.
 *
 * This deliberately follows the loader/linker identity model used by Unreal:
 * an import is resolved by its serialized package/object identity. The resolver
 * therefore uses exact package-name matches and exact full object-path matches
 * only, including mounted source identities preserved as verified package
 * aliases. It does not use AssetRegistry rows, byte-scan soft reference
 * candidates, same-folder/same-object guesses, or inferred package/object-name
 * variants.
 *
 * Known redirectors should only affect resolution after their serialized target
 * can be parsed and represented explicitly; they are not guessed here.
 */
final class CatalogDependencyResolver
{
    private const MAX_VALUES_PER_QUERY = 500;

    /**
     * @param list<array<string, mixed>> $imports
     * @return array<int, array{status:string, resolved_file_id:?int, resolved_export_id:?int, source:string, confidence:string}>
     */
    public static function resolve(PDO $db, int $gameId, int $fileId, array $imports): array
    {
        if (\function_exists('catalog_package_aliases_ensure')) {
            \catalog_package_aliases_ensure($db);
        }

        $packageNames = [];
        $objectLookups = [];

        foreach ($imports as $import) {
            if (self::isCommonImport($import)) {
                continue;
            }

            $rootPackage = (string)($import['root_package'] ?? '');
            if ($rootPackage !== '') {
                $packageNames[] = $rootPackage;
            }

            $relativeObjectPath = (string)($import['relative_object_path'] ?? '');
            if ($relativeObjectPath !== '') {
                $fullPath = (string)($import['full_path'] ?? '');
                if ($fullPath !== '' && $rootPackage !== '') {
                    $objectLookups[$fullPath] = [
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
            array_values(array_unique($packageNames, SORT_STRING))
        );
        $exportMatches = self::loadExportMatches(
            $db,
            $gameId,
            $fileId,
            array_values($objectLookups)
        );

        $resolved = [];
        foreach ($imports as $import) {
            $importId = (int)$import['id'];
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
                $packageMatch = $packageMatches[$rootPackage] ?? null;
                if ($packageMatch !== null) {
                    $result = [
                        'status' => 'package_only',
                        'resolved_file_id' => $packageMatch['file_id'],
                        'resolved_export_id' => null,
                        'source' => $packageMatch['source'],
                        'confidence' => $packageMatch['confidence'],
                    ];
                }
            } else {
                $exportMatch = $exportMatches[$fullPath] ?? null;
                if ($exportMatch !== null) {
                    $result = [
                        'status' => 'resolved',
                        'resolved_file_id' => $exportMatch['file_id'],
                        'resolved_export_id' => $exportMatch['export_id'],
                        'source' => $exportMatch['source'],
                        'confidence' => $exportMatch['confidence'],
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
     * @return array<string, array{file_id:int, source:string, confidence:string}>
     */
    private static function loadPackageMatches(PDO $db, int $gameId, int $fileId, array $packageNames): array
    {
        $matches = [];
        foreach (array_chunk($packageNames, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            [$valuesSql, $valueArgs] = self::valuesTableSql($chunk);
            $rows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, f.id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_files f ON f.game_id=? AND f.package_name=requested.lookup_value AND f.scan_status="verified"'
                . ' ORDER BY requested.lookup_value, (f.id=?) DESC, f.uploaded_at DESC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($rows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = [
                        'file_id' => (int)$row['id'],
                        'source' => 'exact_package',
                        'confidence' => 'exact',
                    ];
                }
            }

            $aliasRows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, f.id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_file_package_aliases a ON a.game_id=? AND a.package_name=requested.lookup_value'
                . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id AND f.scan_status="verified"'
                . ' ORDER BY requested.lookup_value, (f.id=?) DESC, f.uploaded_at DESC, a.id ASC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($aliasRows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = [
                        'file_id' => (int)$row['id'],
                        'source' => 'exact_package_alias',
                        'confidence' => 'exact',
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<array{lookup_value:string, package_name:string, local_path:string}> $objectLookups
     * @return array<string, array{file_id:int, export_id:int, source:string, confidence:string}>
     */
    private static function loadExportMatches(PDO $db, int $gameId, int $fileId, array $objectLookups): array
    {
        $matches = [];
        foreach (array_chunk($objectLookups, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            [$valuesSql, $valueArgs] = self::objectValuesTableSql($chunk);
            $rows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, e.id export_id, f.id file_id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_exports e ON e.full_path=requested.lookup_value'
                . ' JOIN ue_files f ON f.id=e.file_id AND f.game_id=? AND f.scan_status="verified"'
                . ' ORDER BY requested.lookup_value, (f.id=?) DESC, f.uploaded_at DESC, e.export_index ASC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($rows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = [
                        'file_id' => (int)$row['file_id'],
                        'export_id' => (int)$row['export_id'],
                        'source' => 'exact_object',
                        'confidence' => 'exact',
                    ];
                }
            }

            $aliasRows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, e.id export_id, f.id file_id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_file_package_aliases a ON a.game_id=? AND a.package_name=requested.package_name'
                . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id AND f.scan_status="verified"'
                . ' JOIN ue_exports e ON e.file_id=f.id AND e.local_path=requested.local_path'
                . ' ORDER BY requested.lookup_value, (f.id=?) DESC, f.uploaded_at DESC, e.export_index ASC, a.id ASC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($aliasRows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = [
                        'file_id' => (int)$row['file_id'],
                        'export_id' => (int)$row['export_id'],
                        'source' => 'exact_object_alias',
                        'confidence' => 'exact',
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $values
     * @return array{0:string,1:list<string>}
     */
    private static function valuesTableSql(array $values): array
    {
        $parts = [];
        foreach ($values as $index => $value) {
            $parts[] = $index === 0 ? 'SELECT ? AS lookup_value' : 'SELECT ?';
        }

        return [implode(' UNION ALL ', $parts), $values];
    }

    /**
     * @param list<array{lookup_value:string, package_name:string, local_path:string}> $values
     * @return array{0:string,1:list<string>}
     */
    private static function objectValuesTableSql(array $values): array
    {
        $parts = [];
        $args = [];
        foreach ($values as $index => $value) {
            $parts[] = $index === 0
                ? 'SELECT ? AS lookup_value, ? AS package_name, ? AS local_path'
                : 'SELECT ?, ?, ?';
            $args[] = $value['lookup_value'];
            $args[] = $value['package_name'];
            $args[] = $value['local_path'];
        }

        return [implode(' UNION ALL ', $parts), $args];
    }
}
