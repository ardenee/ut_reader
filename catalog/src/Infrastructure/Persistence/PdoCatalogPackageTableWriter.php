<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists the legacy/staging Names, Imports and Exports projections for one package file.
 * Why: Verified import and unverified staging previously duplicated reference-path reconstruction and table writes.
 * Role: Shared persistence collaborator; transaction ownership remains with the caller.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;

final class PdoCatalogPackageTableWriter
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/Scanner/CatalogScannerPath.php';
        require_once $root . '/lib/Scanner/CatalogScannerSupport.php';
    }

    public function deleteForFile(int $fileId): void
    {
        foreach (['ue_dependencies', 'ue_exports', 'ue_imports', 'ue_names'] as $table) {
            $this->db->prepare('DELETE FROM ' . $table . ' WHERE file_id=?')->execute([$fileId]);
        }
    }

    /**
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @param list<string> $commonPackages
     * @param null|callable(string,int,int,int):void $progress section, done, total, rows-written
     */
    public function insert(
        int $fileId,
        string $packageName,
        array $names,
        array $imports,
        array $exports,
        array $commonPackages = [],
        ?callable $progress = null,
        int $batchSize = 250
    ): void {
        $batchSize = max(1, min(1000, $batchSize));
        $common = array_map('strtolower', $commonPackages);

        $nameCount = count($names);
        $batch = [];
        foreach ($names as $index => $name) {
            $batch[] = [
                $fileId,
                (int)$index,
                (string)($name['name'] ?? $name['text'] ?? ''),
                isset($name['flags']) ? (int)$name['flags'] : null,
            ];
            $done = (int)$index + 1;
            if (count($batch) >= $batchSize || $done === $nameCount) {
                $written = count($batch);
                $this->bulkInsert(
                    'ue_names',
                    ['file_id', 'name_index', 'name_text', 'flags'],
                    $batch
                );
                $batch = [];
                if ($progress !== null) {
                    $progress('names', $done, $nameCount, $written);
                }
            }
        }

        $cache = [];
        $importCount = count($imports);
        $batch = [];
        foreach ($imports as $index => $import) {
            $fullPath = \scanner_ref_path(-((int)$index + 1), $imports, $exports, $cache);
            $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
            $rootPackage = (string)($parts[0] ?? '');
            $relativeObjectPath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $batch[] = [
                $fileId,
                (int)$index,
                (string)($import['classPackageText'] ?? ($import['ClassPackage']['text'] ?? '')),
                (string)($import['classNameText'] ?? ($import['ClassName']['text'] ?? '')),
                (string)($import['objectNameText'] ?? ($import['ObjectName']['text'] ?? '')),
                (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0),
                $fullPath,
                $rootPackage,
                $relativeObjectPath,
                in_array(strtolower($rootPackage), $common, true) ? 1 : 0,
            ];
            $done = (int)$index + 1;
            if (count($batch) >= $batchSize || $done === $importCount) {
                $written = count($batch);
                $this->bulkInsert(
                    'ue_imports',
                    [
                        'file_id', 'import_index', 'class_package', 'class_name', 'object_name',
                        'outer_index', 'full_path', 'root_package', 'relative_object_path', 'is_common',
                    ],
                    $batch
                );
                $batch = [];
                if ($progress !== null) {
                    $progress('imports', $done, $importCount, $written);
                }
            }
        }

        $exportCount = count($exports);
        $batch = [];
        foreach ($exports as $index => $export) {
            $localPath = \scanner_ref_path((int)$index + 1, $imports, $exports, $cache);
            $classReference = (int)($export['classIndex'] ?? $export['class'] ?? 0);
            $className = $classReference !== 0
                ? \scanner_ref_path($classReference, $imports, $exports, $cache)
                : '';
            $batch[] = [
                $fileId,
                (int)$index,
                $className,
                (string)($export['objectNameText'] ?? ''),
                (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0),
                $localPath,
                \scanner_join_path_parts([$packageName, $localPath]),
                isset($export['objectFlags']) ? (int)$export['objectFlags'] : null,
                isset($export['serialSize']) ? (int)$export['serialSize'] : null,
                isset($export['serialOffset']) ? (int)$export['serialOffset'] : null,
            ];
            $done = (int)$index + 1;
            if (count($batch) >= $batchSize || $done === $exportCount) {
                $written = count($batch);
                $this->bulkInsert(
                    'ue_exports',
                    [
                        'file_id', 'export_index', 'class_name', 'object_name', 'outer_index',
                        'local_path', 'full_path', 'object_flags', 'serial_size', 'serial_offset',
                    ],
                    $batch
                );
                $batch = [];
                if ($progress !== null) {
                    $progress('exports', $done, $exportCount, $written);
                }
            }
        }
    }

    /** @param list<string> $columns @param list<list<mixed>> $rows */
    private function bulkInsert(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || $columns === []) {
            throw new InvalidArgumentException('Invalid bulk insert target.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                throw new InvalidArgumentException('Invalid bulk insert column.');
            }
        }

        $columnCount = count($columns);
        $tuple = '(' . implode(',', array_fill(0, $columnCount, '?')) . ')';
        $values = [];
        $args = [];
        foreach ($rows as $row) {
            if (count($row) !== $columnCount) {
                throw new InvalidArgumentException('Bulk insert row has the wrong column count.');
            }
            $values[] = $tuple;
            array_push($args, ...$row);
        }

        $statement = $this->db->prepare(
            'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ' . implode(',', $values)
        );
        $statement->execute($args);
    }
}
