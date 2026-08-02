<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/** Loads and validates one file's legacy SQL metadata. */
final class CompressedMetadataLegacySnapshot
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function capture(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive file ID is required.');
        }
        $file = $this->one(
            'SELECT id,game_id,package_name,original_name,name_count,import_count,export_count,scan_status '
            . 'FROM ue_files WHERE id=?',
            $fileId
        );
        if ($file === null) {
            throw new RuntimeException('File #' . $fileId . ' was not found.');
        }
        if ((string)$file['scan_status'] !== 'verified') {
            throw new RuntimeException('File #' . $fileId . ' is not verified.');
        }

        $names = $this->rows(
            'SELECT name_index,name_text,flags FROM ue_names WHERE file_id=? ORDER BY name_index',
            $fileId
        );
        $imports = $this->rows(
            'SELECT id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common '
            . 'FROM ue_imports WHERE file_id=? ORDER BY import_index',
            $fileId
        );
        $exports = $this->rows(
            'SELECT id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset '
            . 'FROM ue_exports WHERE file_id=? ORDER BY export_index',
            $fileId
        );
        $dependencies = $this->rows(
            'SELECT i.import_index,d.required_package,d.required_object_path,d.resolved_file_id,'
            . 're.export_index resolved_export_index,d.status '
            . 'FROM ue_dependencies d '
            . 'JOIN ue_imports i ON i.id=d.import_id '
            . 'LEFT JOIN ue_exports re ON re.id=d.resolved_export_id '
            . 'WHERE d.file_id=? ORDER BY i.import_index',
            $fileId
        );

        $this->assertCounts($file, $names, $imports, $exports);
        $paths = $this->validatePaths((string)$file['package_name'], $imports, $exports);
        $this->validateDependencies($imports, $dependencies);

        return [
            'file' => $file,
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'dependencies' => $dependencies,
            'paths' => $paths,
            'payload' => $this->buildPayload($file, $names, $imports, $exports, $dependencies),
        ];
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, int $fileId): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, int $fileId): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute([$fileId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string,mixed>> $names @param list<array<string,mixed>> $imports @param list<array<string,mixed>> $exports */
    private function assertCounts(array $file, array $names, array $imports, array $exports): void
    {
        $expected = [
            'names' => (int)$file['name_count'],
            'imports' => (int)$file['import_count'],
            'exports' => (int)$file['export_count'],
        ];
        $actual = [
            'names' => count($names),
            'imports' => count($imports),
            'exports' => count($exports),
        ];
        foreach ($expected as $type => $count) {
            if ($count !== $actual[$type]) {
                throw new RuntimeException(
                    'File #' . (int)$file['id'] . ' ' . $type . ' count mismatch: ue_files=' . $count
                    . ', legacy rows=' . $actual[$type] . '.'
                );
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @return array{imports:array<int,array{full:string,root:string,relative:string}>,exports:array<int,array{local:string,full:string}>}
     */
    private function validatePaths(string $packageName, array $imports, array $exports): array
    {
        $importMap = [];
        foreach ($imports as $row) {
            $importMap[(int)$row['import_index']] = $row;
        }
        $exportMap = [];
        foreach ($exports as $row) {
            $exportMap[(int)$row['export_index']] = $row;
        }
        $cache = [];
        $resolve = function (int $reference, array $seen = []) use (&$resolve, &$cache, $importMap, $exportMap): string {
            if ($reference === 0) {
                return '';
            }
            if (isset($cache[$reference])) {
                return $cache[$reference];
            }
            if (isset($seen[$reference])) {
                throw new RuntimeException('Cycle detected while reconstructing package path reference ' . $reference . '.');
            }
            $seen[$reference] = true;
            if ($reference < 0) {
                $index = -$reference - 1;
                $row = $importMap[$index] ?? null;
            } else {
                $index = $reference - 1;
                $row = $exportMap[$index] ?? null;
            }
            if (!is_array($row)) {
                throw new RuntimeException('Package reference ' . $reference . ' points to a missing row.');
            }
            $parent = $resolve((int)$row['outer_index'], $seen);
            return $cache[$reference] = $this->joinPath([$parent, (string)$row['object_name']]);
        };

        $result = ['imports' => [], 'exports' => []];
        foreach ($imports as $row) {
            $index = (int)$row['import_index'];
            $full = $resolve(-($index + 1));
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = (string)($parts[0] ?? '');
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $this->assertSame('import #' . $index . ' full_path', (string)$row['full_path'], $full);
            $this->assertSame('import #' . $index . ' root_package', (string)$row['root_package'], $root);
            $this->assertSame('import #' . $index . ' relative_object_path', (string)$row['relative_object_path'], $relative);
            $result['imports'][$index] = ['full' => $full, 'root' => $root, 'relative' => $relative];
        }
        foreach ($exports as $row) {
            $index = (int)$row['export_index'];
            $local = $resolve($index + 1);
            $full = $this->joinPath([$packageName, $local]);
            $this->assertSame('export #' . $index . ' local_path', (string)$row['local_path'], $local);
            $this->assertSame('export #' . $index . ' full_path', (string)$row['full_path'], $full);
            $result['exports'][$index] = ['local' => $local, 'full' => $full];
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $imports @param list<array<string,mixed>> $dependencies */
    private function validateDependencies(array $imports, array $dependencies): void
    {
        if (count($imports) !== count($dependencies)) {
            throw new RuntimeException(
                'Dependency row count mismatch: imports=' . count($imports) . ', dependencies=' . count($dependencies) . '.'
            );
        }
        $importMap = [];
        foreach ($imports as $row) {
            $importMap[(int)$row['import_index']] = $row;
        }
        foreach ($dependencies as $row) {
            $index = (int)$row['import_index'];
            $import = $importMap[$index] ?? null;
            if (!is_array($import)) {
                throw new RuntimeException('Dependency references missing import index ' . $index . '.');
            }
            $this->assertSame(
                'dependency import #' . $index . ' required_package',
                (string)$import['root_package'],
                (string)$row['required_package']
            );
            $this->assertSame(
                'dependency import #' . $index . ' required_object_path',
                (string)$import['full_path'],
                (string)$row['required_object_path']
            );
        }
    }

    /** @return array<string,mixed> */
    private function buildPayload(array $file, array $names, array $imports, array $exports, array $dependencies): array
    {
        $strings = [];
        $ids = [];
        $intern = static function (?string $value, bool $emptyAsNull = false) use (&$strings, &$ids): ?int {
            if ($value === null || ($emptyAsNull && $value === '')) {
                return null;
            }
            $key = 's:' . $value;
            if (array_key_exists($key, $ids)) {
                return $ids[$key];
            }
            $id = count($strings);
            $strings[] = $value;
            $ids[$key] = $id;
            return $id;
        };

        $nameRows = [];
        foreach ($names as $row) {
            $nameRows[] = [(int)$row['name_index'], $intern((string)$row['name_text']), $row['flags'] !== null ? (string)$row['flags'] : null];
        }
        $importRows = [];
        foreach ($imports as $row) {
            $importRows[] = [
                (int)$row['import_index'],
                $intern((string)($row['class_package'] ?? ''), true),
                $intern((string)($row['class_name'] ?? ''), true),
                $intern((string)$row['object_name']),
                (int)$row['outer_index'],
                (int)$row['is_common'],
            ];
        }
        $exportRows = [];
        foreach ($exports as $row) {
            $exportRows[] = [
                (int)$row['export_index'],
                $intern((string)($row['class_name'] ?? ''), true),
                $intern((string)$row['object_name']),
                (int)$row['outer_index'],
                $row['object_flags'] !== null ? (string)$row['object_flags'] : null,
                $row['serial_size'] !== null ? (string)$row['serial_size'] : null,
                $row['serial_offset'] !== null ? (string)$row['serial_offset'] : null,
            ];
        }
        $dependencyRows = [];
        foreach ($dependencies as $row) {
            [$status, $source, $confidence] = self::dependencyCodes(strtolower(trim((string)$row['status'])));
            $dependencyRows[] = [
                (int)$row['import_index'],
                $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                $status,
                $source,
                $confidence,
            ];
        }

        return [
            'format' => 'unrealdb.file-metadata',
            'format_version' => 1,
            'source_format' => 'legacy-sql-v1',
            'file' => [
                'id' => (int)$file['id'],
                'game_id' => (int)$file['game_id'],
                'package_name' => (string)$file['package_name'],
                'original_name' => (string)$file['original_name'],
            ],
            'strings' => $strings,
            'names' => $nameRows,
            'imports' => $importRows,
            'exports' => $exportRows,
            'dependencies' => $dependencyRows,
        ];
    }

    /** @return array{0:int,1:int,2:int} */
    public static function dependencyCodes(string $status): array
    {
        return match ($status) {
            'resolved' => [1, 1, 100],
            'package_only' => [2, 2, 75],
            'common' => [3, 3, 100],
            default => [0, 0, 0],
        };
    }

    /** @param list<string> $parts */
    private function joinPath(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = trim(str_replace(["\0", '/', '\\'], ['', '.', '.'], $part));
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode('.', $clean);
    }

    private function assertSame(string $label, string $legacy, string $reconstructed): void
    {
        if (!hash_equals($legacy, $reconstructed)) {
            throw new RuntimeException(
                $label . ' mismatch: legacy="' . $legacy . '", reconstructed="' . $reconstructed . '".'
            );
        }
    }
}
