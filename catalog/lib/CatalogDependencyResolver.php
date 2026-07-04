<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

/**
 * Resolves a file's import rows against the catalog without issuing one SQL
 * lookup per import. The returned result uses the same resolution precedence
 * as the original scanner: common package, package-only, export-path, missing.
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
        $packageNames = [];
        $objectPaths = [];

        foreach ($imports as $import) {
            if ((int)($import['is_common'] ?? 0) === 1) {
                continue;
            }

            if ((string)($import['relative_object_path'] ?? '') === '') {
                $packageNames[(string)($import['root_package'] ?? '')] = true;
                continue;
            }

            $objectPaths[(string)($import['full_path'] ?? '')] = true;
        }

        $packageMatches = self::loadPackageMatches($db, $gameId, $fileId, array_keys($packageNames));
        $exportMatches = self::loadExportMatches($db, $gameId, $fileId, array_keys($objectPaths));

        $resolved = [];
        foreach ($imports as $import) {
            $importId = (int)$import['id'];
            $status = 'missing';
            $resolvedFileId = null;
            $resolvedExportId = null;

            if ((int)($import['is_common'] ?? 0) === 1) {
                $status = 'common';
            } elseif ((string)($import['relative_object_path'] ?? '') === '') {
                $match = $packageMatches[(string)($import['root_package'] ?? '')] ?? null;
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
            $rows = catalog_all(
                $db,
                'SELECT requested.lookup_value, f.id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_files f ON f.game_id=? AND f.id<>? AND f.package_name=requested.lookup_value'
                . ' ORDER BY requested.lookup_value, f.uploaded_at DESC',
                array_merge($valueArgs, [$gameId, $fileId])
            );

            foreach ($rows as $row) {
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
            $rows = catalog_all(
                $db,
                'SELECT requested.lookup_value, e.id export_id, f.id file_id'
                . ' FROM (' . $valuesSql . ') requested'
                . ' JOIN ue_exports e ON e.full_path=requested.lookup_value'
                . ' JOIN ue_files f ON f.id=e.file_id AND f.game_id=? AND f.id<>?'
                . ' ORDER BY requested.lookup_value, f.uploaded_at DESC',
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
        }

        return $matches;
    }

    /**
     * Builds a parameterized derived table so MySQL performs each comparison.
     * This deliberately preserves the column collation used by the legacy
     * `package_name=?` and `full_path=?` queries; PHP array key comparison
     * must not decide whether two package/object names are equivalent.
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
}
