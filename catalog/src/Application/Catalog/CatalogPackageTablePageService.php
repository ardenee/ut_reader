<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Catalog;

use PDO;

/** Loads bounded Names/Imports/Exports pages for one catalog file. */
final class CatalogPackageTablePageService
{
    public const DEFAULT_PAGE_SIZE = 250;

    /** @return array{table:string,index_column:string,count_column:string,columns:list<string>} */
    public static function definition(string $table): array
    {
        return match (strtolower(trim($table))) {
            'imports' => [
                'table' => 'ue_imports',
                'index_column' => 'import_index',
                'count_column' => 'import_count',
                'columns' => ['id','import_index','class_package','class_name','object_name','outer_index','full_path','root_package','relative_object_path','is_common'],
            ],
            'exports' => [
                'table' => 'ue_exports',
                'index_column' => 'export_index',
                'count_column' => 'export_count',
                'columns' => ['id','export_index','class_name','object_name','outer_index','local_path','full_path','object_flags','serial_size','serial_offset'],
            ],
            default => [
                'table' => 'ue_names',
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
        $through = $start + $pageSize;

        $statement = $db->prepare(
            'SELECT ' . implode(',', $definition['columns'])
            . ' FROM ' . $definition['table']
            . ' WHERE file_id=? AND ' . $definition['index_column'] . '>=? AND ' . $definition['index_column'] . '<?'
            . ' ORDER BY ' . $definition['index_column']
        );
        $statement->execute([(int)$file['id'], $start, $through]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'rows' => is_array($rows) ? $rows : [],
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
        if ($values === []) {
            return [];
        }
        $lookup = [];
        foreach (array_chunk($values, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $db->prepare(
                'SELECT name_index,name_text FROM ue_names WHERE file_id=? AND name_text IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$fileId], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $lookup[self::key((string)$row['name_text'])] = (int)$row['name_index'];
            }
        }
        return $lookup;
    }

    /**
     * @param list<string> $names
     * @return array<string,array{imports_count:int,imports_target:string,exports_count:int,exports_target:string}>
     */
    public static function nameUsage(PDO $db, int $fileId, array $names): array
    {
        $names = self::uniqueValues($names);
        $usage = [];
        foreach ($names as $name) {
            $usage[self::key($name)] = [
                'imports_count' => 0,
                'imports_target' => '',
                'exports_count' => 0,
                'exports_target' => '',
            ];
        }
        if ($names === []) {
            return $usage;
        }

        foreach (array_chunk($names, 150) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $importSql = 'SELECT value,COUNT(DISTINCT row_index) row_count,MIN(row_index) first_index FROM ('
                . 'SELECT class_package value,import_index row_index FROM ue_imports WHERE file_id=? AND class_package IN (' . $placeholders . ') '
                . 'UNION ALL SELECT class_name value,import_index row_index FROM ue_imports WHERE file_id=? AND class_name IN (' . $placeholders . ') '
                . 'UNION ALL SELECT object_name value,import_index row_index FROM ue_imports WHERE file_id=? AND object_name IN (' . $placeholders . ')'
                . ') x GROUP BY value';
            $args = array_merge([$fileId], $chunk, [$fileId], $chunk, [$fileId], $chunk);
            $statement = $db->prepare($importSql);
            $statement->execute($args);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = self::key((string)$row['value']);
                if (isset($usage[$key])) {
                    $usage[$key]['imports_count'] += (int)$row['row_count'];
                    if ($usage[$key]['imports_target'] === '') {
                        $usage[$key]['imports_target'] = 'import-' . (int)$row['first_index'];
                    }
                }
            }

            $exportSql = 'SELECT value,COUNT(DISTINCT row_index) row_count,MIN(row_index) first_index FROM ('
                . 'SELECT class_name value,export_index row_index FROM ue_exports WHERE file_id=? AND class_name IN (' . $placeholders . ') '
                . 'UNION ALL SELECT object_name value,export_index row_index FROM ue_exports WHERE file_id=? AND object_name IN (' . $placeholders . ')'
                . ') x GROUP BY value';
            $args = array_merge([$fileId], $chunk, [$fileId], $chunk);
            $statement = $db->prepare($exportSql);
            $statement->execute($args);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = self::key((string)$row['value']);
                if (isset($usage[$key])) {
                    $usage[$key]['exports_count'] += (int)$row['row_count'];
                    if ($usage[$key]['exports_target'] === '') {
                        $usage[$key]['exports_target'] = 'export-' . (int)$row['first_index'];
                    }
                }
            }
        }
        return $usage;
    }

    /** @param list<array<string,mixed>> $imports @return array<int,array<string,mixed>> */
    public static function dependencyMap(PDO $db, int $fileId, array $imports): array
    {
        $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $imports)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $db->prepare(
            'SELECT import_id,status,resolution_source,resolution_confidence,required_package,required_object_path '
            . 'FROM ue_dependencies WHERE file_id=? AND import_id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$fileId], $ids));
        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['import_id']] = $row;
        }
        return $map;
    }

    /** @param list<string> $values @return list<string> */
    private static function uniqueValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $out[$value] = true;
            }
        }
        return array_keys($out);
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
