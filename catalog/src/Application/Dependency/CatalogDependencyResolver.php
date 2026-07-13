<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dependency;

use PDO;

require_once __DIR__ . '/../../../lib/CatalogPackageAliases.php';

/**
 * Resolves one file's imports against the catalog in batches.
 *
 * This is an application use case: it expresses dependency precedence while
 * delegating query execution to the current PDO persistence adapter.
 */
final class CatalogDependencyResolver
{
    private const MAX_VALUES_PER_QUERY = 500;

    /**
     * @param list<array<string, mixed>> $imports
     * @return array<int, array{status:string, resolved_file_id:?int, resolved_export_id:?int}>
     */
    public static function resolve(PDO $db, int $gameId, int $fileId, array $imports): array
    {
        \catalog_package_aliases_ensure($db);

        $packageNames = [];
        $objectPaths = [];

        foreach ($imports as $import) {
            if (self::isCommonImport($import)) {
                continue;
            }

            $rootPackage = (string)($import['root_package'] ?? '');
            if ($rootPackage !== '') {
                /* A present package is still useful when an imported object is not exported. */
                $packageNames[] = $rootPackage;
            }

            if ((string)($import['relative_object_path'] ?? '') !== '') {
                $objectPaths[] = (string)($import['full_path'] ?? '');
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
            array_values(array_unique($objectPaths, SORT_STRING))
        );

        $resolved = [];
        foreach ($imports as $import) {
            $importId = (int)$import['id'];
            $rootPackage = (string)($import['root_package'] ?? '');
            $status = 'missing';
            $resolvedFileId = null;
            $resolvedExportId = null;

            if (self::isCommonImport($import)) {
                $status = 'common';
            } elseif ((string)($import['relative_object_path'] ?? '') === '') {
                $match = $packageMatches[$rootPackage] ?? null;
                if ($match !== null) {
                    $status = 'package_only';
                    $resolvedFileId = $match;
                }
            } else {
                $match = $exportMatches[(string)($import['full_path'] ?? '')] ?? null;
                if ($match !== null) {
                    $status = 'resolved';
                    $resolvedFileId = $match['file_id'];
                    $resolvedExportId = $match['export_id'];
                } else {
                    $packageMatch = $packageMatches[$rootPackage] ?? null;
                    if ($packageMatch !== null) {
                        $status = 'package_only';
                        $resolvedFileId = $packageMatch;
                    }
                }
            }

            $resolved[$importId] = [
                'status' => $status,
                'resolved_file_id' => $resolvedFileId,
                'resolved_export_id' => $resolvedExportId,
            ];
        }

        return $resolved;
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
     * @return array<string, int>
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
                    $matches[$lookupValue] = (int)$row['id'];
                }
            }

            $aliasRows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, f.id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_file_package_aliases a ON a.game_id=? AND a.package_name=requested.lookup_value'
                . ' JOIN ue_files f ON f.id=a.file_id AND f.scan_status="verified"'
                . ' ORDER BY requested.lookup_value, (f.id=?) DESC, a.created_at DESC, f.uploaded_at DESC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($aliasRows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = (int)$row['id'];
                }
            }

            $assetLookups = self::ueAssetLookups($chunk);
            if ($assetLookups === []) {
                continue;
            }

            [$assetValuesSql, $assetValueArgs] = self::assetValuesTableSql($assetLookups);
            $assetRows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, f.id'
                . ' FROM (' . $assetValuesSql . ') requested'
                . ' JOIN ue_files f ON f.game_id=? AND f.scan_status="verified"'
                . ' JOIN ue_exports e ON e.file_id=f.id AND e.object_name=requested.object_name'
                . ' WHERE requested.parent_package<>"" AND ' . self::packageParentSql('f.package_name') . '=requested.parent_package'
                . ' ORDER BY requested.lookup_value, (f.package_name=requested.package_path) DESC, (f.id=?) DESC, f.uploaded_at DESC, e.export_index ASC',
                array_merge($assetValueArgs, [$gameId, $fileId])
            );

            foreach ($assetRows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = (int)$row['id'];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $objectPaths
     * @return array<string, array{file_id:int, export_id:int}>
     */
    private static function loadExportMatches(PDO $db, int $gameId, int $fileId, array $objectPaths): array
    {
        $matches = [];
        foreach (array_chunk($objectPaths, self::MAX_VALUES_PER_QUERY) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            [$valuesSql, $valueArgs] = self::valuesTableSql($chunk);
            $rows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, e.id export_id, f.id file_id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_exports e ON e.full_path=requested.lookup_value'
                . ' JOIN ue_files f ON f.id=e.file_id AND f.game_id=? AND f.scan_status="verified"'
                . ' ORDER BY requested.lookup_value, (f.id=?) DESC, f.uploaded_at DESC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($rows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = [
                        'file_id' => (int)$row['file_id'],
                        'export_id' => (int)$row['export_id'],
                    ];
                }
            }

            $aliasLookups = self::aliasExportLookups($chunk);
            if ($aliasLookups !== []) {
                [$aliasValuesSql, $aliasValueArgs] = self::pathValuesTableSql($aliasLookups);
                $aliasRows = \catalog_all(
                    $db,
                    'SELECT requested.lookup_value, e.id export_id, f.id file_id'
                    . ' FROM (' . $aliasValuesSql . ') requested'
                    . ' JOIN ue_file_package_aliases a ON a.game_id=? AND a.package_name=requested.root_package'
                    . ' JOIN ue_files f ON f.id=a.file_id AND f.scan_status="verified"'
                    . ' JOIN ue_exports e ON e.file_id=f.id AND e.local_path=requested.local_path'
                    . ' ORDER BY requested.lookup_value, (f.id=?) DESC, a.created_at DESC, f.uploaded_at DESC',
                    array_merge($aliasValueArgs, [$gameId, $fileId])
                );

                foreach ($aliasRows as $row) {
                    $lookupValue = (string)$row['lookup_value'];
                    if (!isset($matches[$lookupValue])) {
                        $matches[$lookupValue] = [
                            'file_id' => (int)$row['file_id'],
                            'export_id' => (int)$row['export_id'],
                        ];
                    }
                }
            }

            $assetLookups = self::ueAssetLookups($chunk);
            if ($assetLookups === []) {
                continue;
            }

            [$assetValuesSql, $assetValueArgs] = self::assetValuesTableSql($assetLookups);
            $assetRows = \catalog_all(
                $db,
                'SELECT requested.lookup_value, e.id export_id, f.id file_id'
                . ' FROM (' . $assetValuesSql . ') requested'
                . ' JOIN ue_files f ON f.game_id=? AND f.scan_status="verified"'
                . ' JOIN ue_exports e ON e.file_id=f.id'
                . '  AND (e.local_path=requested.local_path OR e.local_path=requested.object_name OR e.object_name=requested.object_name)'
                . ' WHERE requested.parent_package<>"" AND ' . self::packageParentSql('f.package_name') . '=requested.parent_package'
                . ' ORDER BY requested.lookup_value, (f.package_name=requested.package_path) DESC, (e.local_path=requested.local_path) DESC, (f.id=?) DESC, f.uploaded_at DESC, e.export_index ASC',
                array_merge($assetValueArgs, [$gameId, $fileId])
            );

            foreach ($assetRows as $row) {
                $lookupValue = (string)$row['lookup_value'];
                if (!isset($matches[$lookupValue])) {
                    $matches[$lookupValue] = [
                        'file_id' => (int)$row['file_id'],
                        'export_id' => (int)$row['export_id'],
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $objectPaths
     * @return list<array{lookup_value:string,root_package:string,local_path:string}>
     */
    private static function aliasExportLookups(array $objectPaths): array
    {
        $lookups = [];
        foreach ($objectPaths as $objectPath) {
            $objectPath = trim($objectPath);
            $dot = strpos($objectPath, '.');
            if ($objectPath === '' || $dot === false) {
                continue;
            }

            $rootPackage = substr($objectPath, 0, $dot);
            $localPath = substr($objectPath, $dot + 1);
            if ($rootPackage === '' || $localPath === '') {
                continue;
            }

            $lookups[] = [
                'lookup_value' => $objectPath,
                'root_package' => $rootPackage,
                'local_path' => $localPath,
            ];
        }

        return $lookups;
    }

    /**
     * UE4 assets often import an asset as /Game/Dir/Object or
     * /Game/Dir/Object.Object, while the export table can live in a package
     * named /Game/Dir/Container and export Object from that same directory.
     * These lookups let that asset export satisfy both forms after exact
     * package/full-path matching has had first chance.
     *
     * @param list<string> $paths
     * @return list<array{lookup_value:string,package_path:string,parent_package:string,local_path:string,object_name:string}>
     */
    private static function ueAssetLookups(array $paths): array
    {
        $lookups = [];
        foreach ($paths as $path) {
            $path = trim(str_replace('\\', '/', $path));
            if ($path === '' || !str_starts_with($path, '/')) {
                continue;
            }

            $dot = strpos($path, '.');
            $packagePath = $dot === false ? $path : substr($path, 0, $dot);
            $localPath = $dot === false ? self::pathLeaf($packagePath) : substr($path, $dot + 1);
            $objectName = self::pathLeaf(str_replace('.', '/', $localPath));
            $parent = self::pathParent($packagePath);
            if ($packagePath === '' || $parent === '' || $objectName === '') {
                continue;
            }

            $lookups[] = [
                'lookup_value' => $path,
                'package_path' => $packagePath,
                'parent_package' => $parent,
                'local_path' => $localPath,
                'object_name' => $objectName,
            ];
        }

        return $lookups;
    }

    private static function pathParent(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '') {
            return '';
        }
        $pos = strrpos($path, '/');
        return $pos === false || $pos === 0 ? '' : substr($path, 0, $pos);
    }

    private static function pathLeaf(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '') {
            return '';
        }
        $pos = strrpos($path, '/');
        return $pos === false ? $path : substr($path, $pos + 1);
    }

    private static function packageParentSql(string $column): string
    {
        return 'CASE WHEN LOCATE("/", REVERSE(' . $column . '))>0 THEN LEFT(' . $column . ', LENGTH(' . $column . ')-LOCATE("/", REVERSE(' . $column . '))) ELSE "" END';
    }

    /**
     * MySQL performs the comparison so the catalog's configured collation,
     * rather than PHP array-key behaviour, determines object equivalence.
     *
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
     * @param list<array{lookup_value:string,root_package:string,local_path:string}> $lookups
     * @return array{0:string,1:list<string>}
     */
    private static function pathValuesTableSql(array $lookups): array
    {
        $parts = [];
        $args = [];
        foreach ($lookups as $index => $lookup) {
            $parts[] = $index === 0 ? 'SELECT ? AS lookup_value, ? AS root_package, ? AS local_path' : 'SELECT ?, ?, ?';
            $args[] = $lookup['lookup_value'];
            $args[] = $lookup['root_package'];
            $args[] = $lookup['local_path'];
        }

        return [implode(' UNION ALL ', $parts), $args];
    }

    /**
     * @param list<array{lookup_value:string,package_path:string,parent_package:string,local_path:string,object_name:string}> $lookups
     * @return array{0:string,1:list<string>}
     */
    private static function assetValuesTableSql(array $lookups): array
    {
        $parts = [];
        $args = [];
        foreach ($lookups as $index => $lookup) {
            $parts[] = $index === 0
                ? 'SELECT ? AS lookup_value, ? AS package_path, ? AS parent_package, ? AS local_path, ? AS object_name'
                : 'SELECT ?, ?, ?, ?, ?';
            $args[] = $lookup['lookup_value'];
            $args[] = $lookup['package_path'];
            $args[] = $lookup['parent_package'];
            $args[] = $lookup['local_path'];
            $args[] = $lookup['object_name'];
        }

        return [implode(' UNION ALL ', $parts), $args];
    }
}
