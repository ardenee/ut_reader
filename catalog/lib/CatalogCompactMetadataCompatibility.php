<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;

/** @return list<array<string,mixed>> */
function catalog_metadata_compat_direct_rows(PDO $db, string $sql, array $args): array
{
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Read one file's metadata in the legacy row shape. Format-2 files are loaded
 * from the blocked container; unconverted/unverified files retain SQL fallback.
 *
 * @return array{names:list<array<string,mixed>>,imports:list<array<string,mixed>>,exports:list<array<string,mixed>>,dependencies:list<array<string,mixed>>,source:string}
 */
function catalog_metadata_compat_snapshot(PDO $db, array $config, int $fileId): array
{
    if ($fileId < 1) {
        throw new InvalidArgumentException('A positive file ID is required.');
    }

    static $cache = [];
    $cacheKey = spl_object_id($db) . ':' . $fileId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $registration = $db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
    $registration->execute([$fileId]);
    $formatVersion = (int)($registration->fetchColumn() ?: 0);

    if ($formatVersion >= 2) {
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata reading.');
        }
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($fileId);

        $names = [];
        foreach ((array)$snapshot['names'] as $row) {
            $index = (int)$row['name_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $names[] = $row;
        }

        $imports = [];
        foreach ((array)$snapshot['imports'] as $row) {
            $index = (int)$row['import_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $imports[] = $row;
        }

        $exports = [];
        foreach ((array)$snapshot['exports'] as $row) {
            $index = (int)$row['export_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $exports[] = $row;
        }

        $dependencies = [];
        foreach ((array)$snapshot['dependencies'] as $row) {
            $index = (int)$row['import_index'];
            $row['id'] = catalog_metadata_compat_id($fileId, $index);
            $row['file_id'] = $fileId;
            $row['import_id'] = catalog_metadata_compat_id($fileId, $index);
            $row['resolved_export_id'] = null;
            $dependencies[] = $row;
        }

        return $cache[$cacheKey] = [
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'dependencies' => $dependencies,
            'source' => 'compact',
        ];
    }

    return $cache[$cacheKey] = [
        'names' => catalog_metadata_compat_direct_rows($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$fileId]),
        'imports' => catalog_metadata_compat_direct_rows($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$fileId]),
        'exports' => catalog_metadata_compat_direct_rows($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$fileId]),
        'dependencies' => catalog_metadata_compat_direct_rows($db, 'SELECT * FROM ue_dependencies WHERE file_id=? ORDER BY id', [$fileId]),
        'source' => 'legacy',
    ];
}

function catalog_metadata_compat_id(int $fileId, int $index): int
{
    return ($fileId * 4294967296) + $index + 1;
}

function catalog_metadata_compat_normalize_sql(string $sql): string
{
    return trim((string)(preg_replace('/\s+/', ' ', $sql) ?? $sql));
}

/** @return list<array<string,mixed>> */
function catalog_metadata_compat_filter_exports(array $exports, string $sql, array $args): array
{
    $needle = '';
    if (stripos($sql, 'object_name LIKE ?') !== false && isset($args[1])) {
        $needle = trim((string)$args[1], '%');
    }

    $classFilter = null;
    $unknownClass = stripos($sql, 'class_name IS NULL OR e.class_name=""') !== false;
    if (!$unknownClass && preg_match('/\be\.class_name\s*=\s*\?/i', $sql) === 1) {
        $classFilter = (string)end($args);
    }

    $filtered = array_values(array_filter($exports, static function (array $row) use ($needle, $classFilter, $unknownClass): bool {
        $class = (string)($row['class_name'] ?? '');
        if ($unknownClass && $class !== '') {
            return false;
        }
        if ($classFilter !== null && $class !== $classFilter) {
            return false;
        }
        if ($needle === '') {
            return true;
        }
        foreach (['object_name', 'class_name', 'local_path', 'full_path'] as $field) {
            if (stripos((string)($row[$field] ?? ''), $needle) !== false) {
                return true;
            }
        }
        return false;
    }));

    usort($filtered, static fn(array $a, array $b): int => (int)$a['export_index'] <=> (int)$b['export_index']);
    return $filtered;
}

/**
 * Return a handled result for legacy metadata queries that can be served from a
 * format-2 container. Unverified rows continue to use the installed tables.
 *
 * @return array{handled:bool,value:mixed}
 */
function catalog_metadata_compat_query(PDO $db, string $mode, string $sql, array $args): array
{
    if (stripos($sql, 'ue_names') === false
        && stripos($sql, 'ue_imports') === false
        && stripos($sql, 'ue_exports') === false) {
        return ['handled' => false, 'value' => null];
    }

    $normalized = catalog_metadata_compat_normalize_sql($sql);
    $config = function_exists('catalog_config') ? catalog_config() : [];

    if ($mode === 'all'
        && stripos($normalized, 'serialized_export_bytes') !== false
        && stripos($normalized, 'FROM ue_files f') !== false) {
        $baseSql = preg_replace(
            '/,\s*\(SELECT\s+COALESCE\(SUM\(e\.serial_size\),0\)\s+FROM\s+ue_exports\s+e\s+WHERE\s+e\.file_id=f\.id\)\s+serialized_export_bytes\s+/i',
            ',0 serialized_export_bytes ',
            $sql
        );
        if (!is_string($baseSql) || $baseSql === $sql) {
            return ['handled' => false, 'value' => null];
        }
        $rows = catalog_metadata_compat_direct_rows($db, $baseSql, $args);
        foreach ($rows as &$row) {
            $snapshot = catalog_metadata_compat_snapshot($db, $config, (int)$row['id']);
            $row['serialized_export_bytes'] = array_sum(array_map(
                static fn(array $export): int => max(0, (int)($export['serial_size'] ?? 0)),
                $snapshot['exports']
            ));
        }
        unset($row);
        return ['handled' => true, 'value' => $rows];
    }

    $fileId = isset($args[0]) ? (int)$args[0] : 0;
    if ($fileId < 1) {
        return ['handled' => false, 'value' => null];
    }
    $snapshot = catalog_metadata_compat_snapshot($db, $config, $fileId);

    if (preg_match('/^SELECT \* FROM ue_names WHERE file_id=\?/i', $normalized) === 1) {
        return ['handled' => true, 'value' => $mode === 'one' ? ($snapshot['names'][0] ?? null) : $snapshot['names']];
    }
    if (preg_match('/^SELECT \* FROM ue_imports WHERE file_id=\?/i', $normalized) === 1) {
        return ['handled' => true, 'value' => $mode === 'one' ? ($snapshot['imports'][0] ?? null) : $snapshot['imports']];
    }
    if (preg_match('/^SELECT \* FROM ue_exports WHERE file_id=\?/i', $normalized) === 1) {
        return ['handled' => true, 'value' => $mode === 'one' ? ($snapshot['exports'][0] ?? null) : $snapshot['exports']];
    }
    if (preg_match('/^SELECT e\.\* FROM ue_exports e WHERE e\.file_id=\?/i', $normalized) === 1) {
        $rows = catalog_metadata_compat_filter_exports($snapshot['exports'], $normalized, $args);
        if (preg_match('/\bLIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $normalized, $match) === 1) {
            $rows = array_slice($rows, (int)$match[2], (int)$match[1]);
        }
        return ['handled' => true, 'value' => $mode === 'one' ? ($rows[0] ?? null) : $rows];
    }
    if (preg_match('/^SELECT id,local_path FROM ue_exports WHERE file_id=\?/i', $normalized) === 1) {
        $rows = array_map(
            static fn(array $row): array => ['id' => (int)$row['id'], 'local_path' => (string)($row['local_path'] ?? '')],
            $snapshot['exports']
        );
        return ['handled' => true, 'value' => $mode === 'one' ? ($rows[0] ?? null) : $rows];
    }
    if (preg_match('/^SELECT id FROM ue_exports WHERE file_id=\? AND full_path=\? LIMIT 1/i', $normalized) === 1) {
        $wanted = (string)($args[1] ?? '');
        foreach ($snapshot['exports'] as $row) {
            if ((string)($row['full_path'] ?? '') === $wanted) {
                return ['handled' => true, 'value' => ['id' => (int)$row['id']]];
            }
        }
        return ['handled' => true, 'value' => null];
    }

    if (stripos($normalized, 'SELECT COUNT(*) c FROM ue_exports WHERE file_id=? AND full_path<>CASE') === 0) {
        $package = (string)($args[1] ?? '');
        $count = 0;
        foreach ($snapshot['exports'] as $row) {
            $local = (string)($row['local_path'] ?? '');
            $expected = $local !== '' ? $package . '.' . $local : $package;
            if ((string)($row['full_path'] ?? '') !== $expected) {
                $count++;
            }
        }
        return ['handled' => true, 'value' => ['c' => $count]];
    }

    if (preg_match('/^SELECT COUNT\(\*\) c FROM ue_exports e WHERE e\.file_id=\?/i', $normalized) === 1) {
        $rows = catalog_metadata_compat_filter_exports($snapshot['exports'], $normalized, $args);
        return ['handled' => true, 'value' => ['c' => count($rows)]];
    }

    if (stripos($normalized, 'SELECT COALESCE(NULLIF(class_name,""),"unknown") class_name,COUNT(*) c FROM ue_exports WHERE file_id=? GROUP BY') === 0) {
        $counts = [];
        foreach ($snapshot['exports'] as $row) {
            $class = trim((string)($row['class_name'] ?? ''));
            $class = $class !== '' ? $class : 'unknown';
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        $rows = [];
        foreach ($counts as $class => $count) {
            $rows[] = ['class_name' => $class, 'c' => $count];
        }
        usort($rows, static fn(array $a, array $b): int => ((int)$b['c'] <=> (int)$a['c']) ?: strcasecmp((string)$a['class_name'], (string)$b['class_name']));
        return ['handled' => true, 'value' => array_slice($rows, 0, 500)];
    }

    if (stripos($normalized, 'SELECT COUNT(*) export_count,COALESCE(SUM(serial_size),0) serial_bytes') === 0
        && stripos($normalized, 'FROM ue_exports WHERE file_id=?') !== false) {
        $serialBytes = 0;
        $firstOffset = null;
        $lastEnd = 0;
        foreach ($snapshot['exports'] as $row) {
            $offset = max(0, (int)($row['serial_offset'] ?? 0));
            $size = max(0, (int)($row['serial_size'] ?? 0));
            $serialBytes += $size;
            $firstOffset = $firstOffset === null ? $offset : min($firstOffset, $offset);
            $lastEnd = max($lastEnd, $offset + $size);
        }
        return ['handled' => true, 'value' => [
            'export_count' => count($snapshot['exports']),
            'serial_bytes' => $serialBytes,
            'first_offset' => $firstOffset ?? 0,
            'last_end' => $lastEnd,
        ]];
    }

    return ['handled' => false, 'value' => null];
}
