<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides bounded Names/Imports/Exports paging and lookups from authoritative format-2 metadata.
 * Why: Verified package examination must never fall back to retired SQL metadata tables.
 * Role: Infrastructure compact metadata query used by file-examine pages.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;

final class PdoPackageTablePageQuery
{
    public const DEFAULT_PAGE_SIZE = 250;

    /** @var array<string,int> */
    private static array $formatCache = [];

    /** @var array<string,BlockedCompressedMetadataReader> */
    private static array $readerCache = [];

    /** @return array{index_column:string,count_column:string,columns:list<string>} */
    public static function definition(string $table): array
    {
        return match (strtolower(trim($table))) {
            'imports' => [
                'index_column' => 'import_index',
                'count_column' => 'import_count',
                'columns' => ['id','import_index','class_package','class_name','object_name','outer_index','full_path','root_package','relative_object_path','is_common'],
            ],
            'exports' => [
                'index_column' => 'export_index',
                'count_column' => 'export_count',
                'columns' => ['id','export_index','class_name','object_name','outer_index','local_path','full_path','object_flags','serial_size','serial_offset'],
            ],
            default => [
                'index_column' => 'name_index',
                'count_column' => 'name_count',
                'columns' => ['id','name_index','name_text','flags'],
            ],
        };
    }

    public static function normalizeTable(string $table): string
    {
        $table = strtolower(trim($table));
        return in_array($table, ['names', 'imports', 'exports'], true) ? $table : 'names';
    }

    public static function normalizePageSize(int $size): int
    {
        return in_array($size, [100, 250, 500, 1000], true) ? $size : self::DEFAULT_PAGE_SIZE;
    }

    public static function targetIndex(string $target, string $table): ?int
    {
        $table = self::normalizeTable($table);
        $prefix = match ($table) {
            'imports' => 'import-',
            'exports' => 'export-',
            default => 'name-',
        };
        if (!str_starts_with($target, $prefix)) {
            return null;
        }
        $value = substr($target, strlen($prefix));
        return preg_match('/^\d+$/', $value) === 1 ? (int)$value : null;
    }

    public static function pageForIndex(int $index, int $pageSize): int
    {
        return max(1, intdiv(max(0, $index), self::normalizePageSize($pageSize)) + 1);
    }

    /** @return array{rows:list<array<string,mixed>>,page:int,pages:int,total:int,page_size:int,start:int,end:int} */
    public static function fetchPage(PDO $db, array $file, string $table, int $page, int $pageSize): array
    {
        $table = self::normalizeTable($table);
        $definition = self::definition($table);
        $pageSize = self::normalizePageSize($pageSize);
        $total = max(0, (int)($file[$definition['count_column']] ?? 0));
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = max(1, min($page, $pages));
        $start = ($page - 1) * $pageSize;
        $rows = self::reader($db, (int)$file['id'])->page((int)$file['id'], $table, $start, $pageSize);

        return [
            'rows' => $rows,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'page_size' => $pageSize,
            'start' => $total > 0 ? $start + 1 : 0,
            'end' => min($total, $start + count($rows)),
        ];
    }

    /** @param list<string> $values @return array<string,int> */
    public static function nameLookup(PDO $db, int $fileId, array $values): array
    {
        $values = self::uniqueValues($values);
        return $values === [] ? [] : self::reader($db, $fileId)->findNameIndexes($fileId, $values);
    }

    /**
     * @param list<string> $names
     * @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}>
     */
    public static function nameUsage(PDO $db, int $fileId, array $names): array
    {
        $names = self::uniqueValues($names);
        return self::reader($db, $fileId)->nameUsage($fileId, $names);
    }

    /** @param list<array<string,mixed>> $imports @return array<int,array<string,mixed>> */
    public static function dependencyMap(PDO $db, int $fileId, array $imports): array
    {
        $indexes = array_values(array_map(
            static fn(array $row): int => (int)($row['import_index'] ?? -1),
            $imports
        ));
        $byIndex = self::reader($db, $fileId)->dependenciesForImportIndexes($fileId, $indexes);
        $map = [];
        foreach ($imports as $row) {
            $index = (int)($row['import_index'] ?? -1);
            if (isset($byIndex[$index])) {
                $map[(int)($row['id'] ?? ($index + 1))] = $byIndex[$index];
            }
        }
        return $map;
    }

    private static function reader(PDO $db, int $fileId): BlockedCompressedMetadataReader
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive verified file ID is required for compact metadata reads.');
        }
        $format = self::metadataFormat($db, $fileId);
        if ($format !== 2) {
            throw new RuntimeException(
                'Verified file #' . $fileId . ' is missing current format-2 metadata; runtime legacy reads are disabled.'
            );
        }
        $storageRoot = self::storageRoot();
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact package table reads.');
        }
        $key = spl_object_id($db) . ':' . $storageRoot;
        return self::$readerCache[$key] ??= new BlockedCompressedMetadataReader($db, $storageRoot);
    }

    private static function metadataFormat(PDO $db, int $fileId): int
    {
        $key = spl_object_id($db) . ':' . $fileId;
        if (array_key_exists($key, self::$formatCache)) {
            return self::$formatCache[$key];
        }
        $statement = $db->prepare(
            'SELECT m.format_version FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $statement->execute([$fileId]);
        return self::$formatCache[$key] = (int)($statement->fetchColumn() ?: 0);
    }

    private static function storageRoot(): string
    {
        if (!function_exists('catalog_config')) {
            return '';
        }
        try {
            $config = \catalog_config();
        } catch (\Throwable) {
            return '';
        }
        return is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
    }

    /** @param list<string> $values @return list<string> */
    private static function uniqueValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                // PHP converts numeric-string array keys (for example "123") to
                // integers. Preserve the original string as the value so a
                // numeric Unreal FName cannot become int 123 before trim()/lookup.
                $out['s:' . $value] = $value;
            }
        }
        return array_values($out);
    }
}
