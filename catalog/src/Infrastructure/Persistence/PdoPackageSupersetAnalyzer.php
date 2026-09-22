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
            'SELECT f.id file_id,l.import_index'
            . ' FROM ue_dependency_links l'
            . ' JOIN ue_files f ON f.id=l.file_id'
            . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
            . ' JOIN ue_terms p ON p.id=l.required_package_term_id'
            . ' WHERE f.game_id=? AND f.scan_status="verified"'
            . ' AND LOWER(p.term_text)=LOWER(?)'
            . ' ORDER BY f.id,l.import_index',
            [$gameId, $packageName]
        );
        if ($rows !== []) {
            $reader = self::metadataReader($db);
            $byFile = [];
            foreach ($rows as $row) {
                $byFile[(int)$row['file_id']][] = (int)$row['import_index'];
            }
            foreach ($byFile as $consumerFileId => $importIndexes) {
                sort($importIndexes, SORT_NUMERIC);
                foreach (self::contiguousRanges(array_values(array_unique($importIndexes))) as [$start, $length]) {
                    $importRows = $reader->page($consumerFileId, 'imports', $start, $length);
                    foreach ($importRows as $import) {
                    if (!is_array($import)) {
                        continue;
                    }
                    $fullPath = trim((string)($import['full_path'] ?? ''));
                    $relativePath = trim((string)($import['relative_object_path'] ?? ''));
                    if ($relativePath === '') {
                        $relativePath = self::relativePath($packageName, $fullPath);
                    }
                    if ($relativePath === '') {
                        continue;
                    }
                    $key = self::key($relativePath);
                    $requirements[$key] ??= $relativePath;
                    $consumers[$consumerFileId] = true;
                    }
                }
            }
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

    /** @param list<int> $indexes @return list<array{0:int,1:int}> */
    private static function contiguousRanges(array $indexes): array
    {
        if ($indexes === []) {
            return [];
        }
        $ranges = [];
        $start = $indexes[0];
        $previous = $start;
        foreach (array_slice($indexes, 1) as $index) {
            if ($index === $previous + 1) {
                $previous = $index;
                continue;
            }
            $ranges[] = [$start, $previous - $start + 1];
            $start = $previous = $index;
        }
        $ranges[] = [$start, $previous - $start + 1];
        return $ranges;
    }

    private static function metadataReader(PDO $db): \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php';
        $config = require $root . '/config.php';
        $storageRoot = (string)($config['storage_path'] ?? ($root . '/storage'));
        return new \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader($db, $storageRoot);
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
