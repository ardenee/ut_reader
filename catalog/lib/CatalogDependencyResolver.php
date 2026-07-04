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

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = catalog_all(
                $db,
                'SELECT id, package_name FROM ue_files WHERE game_id=? AND id<>? AND package_name IN (' . $placeholders . ') ORDER BY uploaded_at DESC',
                array_merge([$gameId, $fileId], $chunk)
            );

            foreach ($rows as $row) {
                $packageName = (string)$row['package_name'];
                if (!isset($matches[$packageName])) {
                    $matches[$packageName] = (int)$row['id'];
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

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = catalog_all(
                $db,
                'SELECT e.id export_id, e.full_path, f.id file_id FROM ue_exports e JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=? AND f.id<>? AND e.full_path IN (' . $placeholders . ') ORDER BY f.uploaded_at DESC',
                array_merge([$gameId, $fileId], $chunk)
            );

            foreach ($rows as $row) {
                $fullPath = (string)$row['full_path'];
                if (!isset($matches[$fullPath])) {
                    $matches[$fullPath] = [
                        'file_id' => (int)$row['file_id'],
                        'export_id' => (int)$row['export_id'],
                    ];
                }
            }
        }

        return $matches;
    }
}
