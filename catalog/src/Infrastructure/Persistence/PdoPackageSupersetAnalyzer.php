<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/**
 * Builds the catalog-wide union of object paths required from one logical
 * package and evaluates every verified v3 provider against that union.
 *
 * This is reporting/analysis state. It does not change the administrator's
 * primary provider selection and is not persisted into individual .uedb3 files.
 */
final class PdoPackageSupersetAnalyzer
{
    /**
     * @return array{
     *   game_id:int,
     *   package_name:string,
     *   consumer_count:int,
     *   required_object_count:int,
     *   required_object_paths:list<string>,
     *   providers:list<array<string,mixed>>
     * }
     */
    public static function analyze(PDO $db, int $gameId, string $packageName): array
    {
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '') {
            return [
                'game_id' => $gameId,
                'package_name' => $packageName,
                'consumer_count' => 0,
                'required_object_count' => 0,
                'required_object_paths' => [],
                'providers' => [],
            ];
        }

        $requirements = [];
        $consumers = [];
        $rows = \catalog_all(
            $db,
            'SELECT d.file_id,d.required_object_path'
            . ' FROM ue_dependencies d'
            . ' JOIN ue_files f ON f.id=d.file_id'
            . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
            . ' WHERE f.game_id=? AND f.scan_status="verified"'
            . ' AND d.required_package=? AND d.required_object_path<>""'
            . ' ORDER BY d.file_id,d.import_index',
            [$gameId, $packageName]
        );
        foreach ($rows as $row) {
            $fullPath = trim((string)($row['required_object_path'] ?? ''));
            $relativePath = self::relativePath($packageName, $fullPath);
            if ($relativePath === '') {
                continue;
            }
            $key = self::key($relativePath);
            $requirements[$key] ??= $relativePath;
            $consumers[(int)$row['file_id']] = true;
        }

        $paths = array_values($requirements);
        $providers = $paths === []
            ? []
            : PdoPackageObjectCoverageResolver::evaluate($db, $gameId, $packageName, $paths);

        return [
            'game_id' => $gameId,
            'package_name' => $packageName,
            'consumer_count' => count($consumers),
            'required_object_count' => count($paths),
            'required_object_paths' => $paths,
            'providers' => $providers,
        ];
    }

    private static function relativePath(string $packageName, string $fullPath): string
    {
        $fullPath = trim($fullPath, '. ');
        if ($fullPath === '') {
            return '';
        }
        $prefix = $packageName . '.';
        if (str_starts_with(self::key($fullPath), self::key($prefix))) {
            return trim(substr($fullPath, strlen($prefix)), '.');
        }
        // Dependency rows written by older/current builders may already contain
        // a package-relative path. Preserve it rather than discarding useful data.
        return $fullPath;
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));
    }
}
