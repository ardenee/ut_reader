<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Serves historical metadata SQL read shapes from current metadata snapshots.
 * Why: Older callers still express Names/Imports/Exports query shapes, while physical legacy metadata tables are being retired.
 * Role: Compatibility boundary routing verified files to format-2 and unverified files to compressed staging.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataStore;

final class CatalogCompactMetadataCompatibilityService
{
    /** @var array<int,array{file_id:int,snapshot:array<string,mixed>}> */
    private static array $snapshotCache = [];

    /**
     * @param list<mixed> $args
     * @return array{handled:bool,value:mixed}
     */
    public function query(PDO $db, string $mode, string $sql, array $args): array
    {
        if (stripos($sql, 'ue_names') === false
            && stripos($sql, 'ue_imports') === false
            && stripos($sql, 'ue_exports') === false) {
            return ['handled' => false, 'value' => null];
        }

        $normalized = $this->normalizeSql($sql);
        $config = function_exists('catalog_config') ? \catalog_config() : [];

        if ($mode === 'all'
            && stripos($normalized, 'serialized_export_bytes') !== false
            && stripos($normalized, 'FROM ue_files f') !== false) {
            $baseSql = preg_replace(
                '/,\s*\(SELECT\s+COALESCE\(SUM\(e\.serial_size\),0\)\s+FROM\s+ue_exports\s+e\s+WHERE\s+e\.file_id=f\.id\)\s+serialized_export_bytes\s+/i',
                ',0 serialized_export_bytes ',
                $sql
            );
            if (!is_string($baseSql) || $baseSql === $sql) {
                throw new RuntimeException('Unsupported verified Export aggregate compatibility query shape.');
            }
            $rows = $this->directRows($db, $baseSql, $args);
            foreach ($rows as &$row) {
                $snapshot = $this->snapshot($db, $config, (int)$row['id']);
                if (($snapshot['source'] ?? '') !== 'compact') {
                    throw new RuntimeException(
                        'Verified Export aggregate compatibility encountered a non-current metadata row.'
                    );
                }
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
            throw new RuntimeException(
                'Historical Names/Imports/Exports query has no file identity; physical legacy metadata reads are disabled.'
            );
        }
        $snapshot = $this->snapshot($db, $config, $fileId);

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
            $rows = $this->filterExports($snapshot['exports'], $normalized, $args);
            if (preg_match('/\bLIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $normalized, $match) === 1) {
                $rows = array_slice($rows, (int)$match[2], (int)$match[1]);
            }
            return ['handled' => true, 'value' => $mode === 'one' ? ($rows[0] ?? null) : $rows];
        }
        if (preg_match('/^SELECT id,local_path FROM ue_exports WHERE file_id=\?/i', $normalized) === 1) {
            $rows = array_map(
                static fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'local_path' => (string)($row['local_path'] ?? ''),
                ],
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
            $rows = $this->filterExports($snapshot['exports'], $normalized, $args);
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
            usort(
                $rows,
                static fn(array $a, array $b): int => ((int)$b['c'] <=> (int)$a['c'])
                    ?: strcasecmp((string)$a['class_name'], (string)$b['class_name'])
            );
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

        if (in_array((string)($snapshot['source'] ?? ''), ['compact', 'unverified-staging'], true)) {
            throw new RuntimeException(
                'Unsupported historical metadata query shape for file #' . $fileId
                . '; physical legacy metadata reads are disabled.'
            );
        }

        return ['handled' => false, 'value' => null];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{names:list<array<string,mixed>>,imports:list<array<string,mixed>>,exports:list<array<string,mixed>>,dependencies:list<array<string,mixed>>,source:string}
     */
    private function snapshot(PDO $db, array $config, int $fileId): array
    {
        if ($fileId < 1) {
            throw new InvalidArgumentException('A positive file ID is required.');
        }

        $connectionId = spl_object_id($db);
        if ((int)(self::$snapshotCache[$connectionId]['file_id'] ?? 0) === $fileId
            && is_array(self::$snapshotCache[$connectionId]['snapshot'] ?? null)) {
            return self::$snapshotCache[$connectionId]['snapshot'];
        }
        unset(self::$snapshotCache[$connectionId]);

        $registration = $db->prepare(
            'SELECT f.scan_status,m.format_version FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id WHERE f.id=?'
        );
        $registration->execute([$fileId]);
        $state = $registration->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            throw new RuntimeException('Catalog file #' . $fileId . ' was not found.');
        }
        $formatVersion = (int)($state['format_version'] ?? 0);
        $scanStatus = strtolower(trim((string)($state['scan_status'] ?? '')));

        if ($formatVersion >= BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            $storageRoot = trim((string)($config['storage_path'] ?? ''));
            if ($storageRoot === '') {
                throw new RuntimeException('Catalog storage_path is required for compact metadata reading.');
            }
            $snapshot = (new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot))->load($fileId);

            $names = [];
            foreach ((array)$snapshot['names'] as $row) {
                $index = (int)$row['name_index'];
                $row['id'] = $this->virtualId($fileId, $index);
                $row['file_id'] = $fileId;
                $names[] = $row;
            }
            $imports = [];
            foreach ((array)$snapshot['imports'] as $row) {
                $index = (int)$row['import_index'];
                $row['id'] = $this->virtualId($fileId, $index);
                $row['file_id'] = $fileId;
                $imports[] = $row;
            }
            $exports = [];
            foreach ((array)$snapshot['exports'] as $row) {
                $index = (int)$row['export_index'];
                $row['id'] = $this->virtualId($fileId, $index);
                $row['file_id'] = $fileId;
                $exports[] = $row;
            }
            $dependencies = [];
            foreach ((array)$snapshot['dependencies'] as $row) {
                $index = (int)$row['import_index'];
                $row['id'] = $this->virtualId($fileId, $index);
                $row['file_id'] = $fileId;
                $row['import_id'] = $this->virtualId($fileId, $index);
                $row['resolved_export_id'] = null;
                $dependencies[] = $row;
            }
            $result = [
                'names' => $names,
                'imports' => $imports,
                'exports' => $exports,
                'dependencies' => $dependencies,
                'source' => 'compact',
            ];
            self::$snapshotCache[$connectionId] = ['file_id' => $fileId, 'snapshot' => $result];
            return $result;
        }

        if ($scanStatus === 'verified') {
            throw new RuntimeException(
                'Verified file #' . $fileId . ' is missing current format-2 metadata; fallback reads are disabled.'
            );
        }
        if ($scanStatus !== 'unverified') {
            throw new RuntimeException(
                'File #' . $fileId . ' has no current metadata snapshot for status ' . $scanStatus . '.'
            );
        }

        $staging = (new CatalogUnverifiedMetadataStore($db))->load($fileId);
        $result = [
            'names' => array_values((array)($staging['names'] ?? [])),
            'imports' => array_values((array)($staging['imports'] ?? [])),
            'exports' => array_values((array)($staging['exports'] ?? [])),
            'dependencies' => [],
            'source' => 'unverified-staging',
        ];
        self::$snapshotCache[$connectionId] = ['file_id' => $fileId, 'snapshot' => $result];
        return $result;
    }

    /** @param list<mixed> $args @return list<array<string,mixed>> */
    private function directRows(PDO $db, string $sql, array $args): array
    {
        $statement = $db->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function virtualId(int $fileId, int $index): int
    {
        return ($fileId * 4294967296) + $index + 1;
    }

    private function normalizeSql(string $sql): string
    {
        return trim((string)(preg_replace('/\s+/', ' ', $sql) ?? $sql));
    }

    /** @param list<array<string,mixed>> $exports @param list<mixed> $args @return list<array<string,mixed>> */
    private function filterExports(array $exports, string $sql, array $args): array
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

        $filtered = array_values(array_filter(
            $exports,
            static function (array $row) use ($needle, $classFilter, $unknownClass): bool {
                $class = (string)($row['class_name'] ?? '');
                if ($unknownClass && $class !== '') return false;
                if ($classFilter !== null && $class !== $classFilter) return false;
                if ($needle === '') return true;
                foreach (['object_name', 'class_name', 'local_path', 'full_path'] as $field) {
                    if (stripos((string)($row[$field] ?? ''), $needle) !== false) return true;
                }
                return false;
            }
        ));
        usort($filtered, static fn(array $a, array $b): int =>
            (int)$a['export_index'] <=> (int)$b['export_index']
        );
        return $filtered;
    }
}
