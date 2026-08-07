<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CompressedMetadataLookupWriter` for compressed metadata lookup writer.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/** Writes compact global lookup projections using bounded multi-row SQL. */
final class CompressedMetadataLookupWriter
{
    private const TERM_BATCH_SIZE = 350;
    private const WRITE_BATCH_SIZE = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $snapshot */
    public function write(
        array $snapshot,
        string $compressed,
        string $json,
        int &$sqlBatches
    ): void {
        $this->writeVersioned(
            $snapshot,
            $compressed,
            strlen($json),
            BatchedCompressedFileMetadataConverter::FORMAT_VERSION,
            BatchedCompressedFileMetadataConverter::CODEC_GZIP,
            $sqlBatches
        );
    }

    /** @param array<string,mixed> $snapshot */
    public function writeVersioned(
        array $snapshot,
        string $storedBytes,
        int $uncompressedSize,
        int $formatVersion,
        int $codec,
        int &$sqlBatches
    ): void {
        $file = (array)$snapshot['file'];
        $imports = (array)$snapshot['imports'];
        $exports = (array)$snapshot['exports'];
        $dependencies = (array)$snapshot['dependencies'];
        $paths = (array)$snapshot['paths'];
        $fileId = (int)$file['id'];
        $resolutionLabels = $this->dependencyResolutionLabels($fileId, $dependencies, $sqlBatches);

        $importsByIndex = [];
        foreach ($imports as $row) {
            if (is_array($row)) {
                $importsByIndex[(int)$row['import_index']] = $row;
            }
        }

        $values = [];
        foreach ($exports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values[] = (string)$row['object_name'];
            $className = trim((string)($row['class_name'] ?? ''));
            if ($className !== '') {
                $values[] = $className;
            }
        }
        foreach ($dependencies as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['import_index'];
            $labels = $resolutionLabels[$index] ?? null;
            $import = $importsByIndex[$index] ?? null;
            if (!is_array($labels)) {
                throw new RuntimeException('Missing dependency resolution labels for import index ' . $index . '.');
            }
            if (!is_array($import)) {
                throw new RuntimeException('Missing import metadata for dependency import index ' . $index . '.');
            }
            $values[] = (string)$row['required_package'];
            $values[] = (string)$row['required_object_path'];
            $values[] = (string)$labels['source'];
            $values[] = (string)$labels['confidence'];
            $classPackage = trim((string)($import['class_package'] ?? ''));
            $className = trim((string)($import['class_name'] ?? ''));
            if ($classPackage !== '') {
                $values[] = $classPackage;
            }
            if ($className !== '') {
                $values[] = $className;
            }
        }
        $termIds = $this->resolveTermIds($values, $sqlBatches);

        $this->db->prepare('DELETE FROM ue_export_lookup WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_dependency_links WHERE file_id=?')->execute([$fileId]);
        $sqlBatches += 2;

        $exportRows = [];
        foreach ($exports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['export_index'];
            $object = (string)$row['object_name'];
            $class = trim((string)($row['class_name'] ?? ''));
            $exportRows[] = [
                $fileId,
                $index,
                $termIds[$this->termKey($object)],
                $class !== '' ? $termIds[$this->termKey($class)] : null,
                md5((string)$paths['exports'][$index]['local'], true),
            ];
        }
        $sqlBatches += $this->bulkInsert(
            'ue_export_lookup',
            ['file_id', 'export_index', 'object_term_id', 'class_term_id', 'path_hash'],
            $exportRows
        );

        $dependencyRows = [];
        foreach ($dependencies as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['import_index'];
            $labels = $resolutionLabels[$index] ?? null;
            $import = $importsByIndex[$index] ?? null;
            if (!is_array($labels)) {
                throw new RuntimeException('Missing dependency resolution labels for import index ' . $index . '.');
            }
            if (!is_array($import)) {
                throw new RuntimeException('Missing import metadata for dependency import index ' . $index . '.');
            }
            [$status, $sourceCode, $confidenceCode] = CompressedMetadataLegacySnapshot::dependencyCodes(
                strtolower(trim((string)$row['status']))
            );
            $source = (string)$labels['source'];
            $confidence = (string)$labels['confidence'];
            $requiredObject = (string)$row['required_object_path'];
            $classPackage = trim((string)($import['class_package'] ?? ''));
            $className = trim((string)($import['class_name'] ?? ''));
            $dependencyRows[] = [
                $fileId,
                $index,
                $termIds[$this->termKey((string)$row['required_package'])],
                md5((string)$paths['imports'][$index]['relative'], true),
                $termIds[$this->termKey($requiredObject)],
                $classPackage !== '' ? $termIds[$this->termKey($classPackage)] : null,
                $className !== '' ? $termIds[$this->termKey($className)] : null,
                $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                $status,
                $sourceCode,
                $confidenceCode,
                $termIds[$this->termKey($source)],
                $termIds[$this->termKey($confidence)],
            ];
        }
        $sqlBatches += $this->bulkInsert(
            'ue_dependency_links',
            [
                'file_id', 'import_index', 'required_package_term_id', 'required_path_hash',
                'required_object_term_id', 'import_class_package_term_id', 'import_class_name_term_id',
                'resolved_file_id', 'resolved_export_index', 'status', 'resolution_source',
                'resolution_confidence', 'resolution_source_term_id', 'resolution_confidence_term_id',
            ],
            $dependencyRows
        );

        $timestamp = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO ue_file_metadata('
            . 'file_id,format_version,codec,compressed_size,uncompressed_size,payload_sha256,'
            . 'name_count,import_count,export_count,created_at,updated_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'format_version=VALUES(format_version),codec=VALUES(codec),'
            . 'compressed_size=VALUES(compressed_size),uncompressed_size=VALUES(uncompressed_size),'
            . 'payload_sha256=VALUES(payload_sha256),name_count=VALUES(name_count),'
            . 'import_count=VALUES(import_count),export_count=VALUES(export_count),'
            . 'updated_at=VALUES(updated_at)'
        );
        $statement->execute([
            $fileId,
            $formatVersion,
            $codec,
            strlen($storedBytes),
            $uncompressedSize,
            hash('sha256', $storedBytes, true),
            count((array)$snapshot['names']),
            count((array)$snapshot['imports']),
            count((array)$snapshot['exports']),
            $timestamp,
            $timestamp,
        ]);
        $sqlBatches++;
    }

    /**
     * @param array<int,mixed> $dependencies
     * @return array<int,array{source:string,confidence:string}>
     */
    private function dependencyResolutionLabels(int $fileId, array $dependencies, int &$sqlBatches): array
    {
        if ($dependencies === []) {
            return [];
        }

        $inline = [];
        $inlineComplete = true;
        foreach ($dependencies as $row) {
            if (!is_array($row)) {
                $inlineComplete = false;
                break;
            }
            $index = (int)($row['import_index'] ?? -1);
            $source = trim((string)($row['resolution_source'] ?? ''));
            $confidence = trim((string)($row['resolution_confidence'] ?? ''));
            if ($index < 0 || $source === '' || $confidence === '') {
                $inlineComplete = false;
                break;
            }
            $inline[$index] = [
                'source' => $source,
                'confidence' => $confidence,
            ];
        }
        if ($inlineComplete && count($inline) === count($dependencies)) {
            return $inline;
        }

        $statement = $this->db->prepare(
            'SELECT i.import_index,d.resolution_source,d.resolution_confidence '
            . 'FROM ue_dependencies d JOIN ue_imports i ON i.id=d.import_id '
            . 'WHERE d.file_id=? ORDER BY i.import_index'
        );
        $statement->execute([$fileId]);
        $sqlBatches++;
        $labels = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $source = trim((string)($row['resolution_source'] ?? ''));
            $confidence = trim((string)($row['resolution_confidence'] ?? ''));
            $labels[(int)$row['import_index']] = [
                'source' => $source !== '' ? $source : 'unknown',
                'confidence' => $confidence !== '' ? $confidence : 'unknown',
            ];
        }
        if (count($labels) !== count($dependencies)) {
            throw new RuntimeException(
                'Dependency resolution label count mismatch for file #' . $fileId
                . ': expected ' . count($dependencies) . ', found ' . count($labels) . '.'
            );
        }
        return $labels;
    }

    /** @param list<string> $values @return array<string,int> */
    private function resolveTermIds(array $values, int &$sqlBatches): array
    {
        $terms = [];
        foreach ($values as $value) {
            if (strlen($value) > 65535) {
                throw new RuntimeException('Compact lookup term exceeds 65,535 bytes.');
            }
            $key = $this->termKey($value);
            if (isset($terms[$key]) && !hash_equals((string)$terms[$key]['value'], $value)) {
                throw new RuntimeException('Compact lookup term hash collision detected inside conversion batch.');
            }
            $terms[$key] = [
                'value' => $value,
                'hash' => md5($value, true),
                'length' => strlen($value),
                'prefix' => substr($value, 0, 200),
                'overflow' => strlen($value) > 200 ? 1 : 0,
            ];
        }
        if ($terms === []) {
            return [];
        }

        foreach (array_chunk(array_values($terms), self::TERM_BATCH_SIZE) as $chunk) {
            $placeholders = [];
            $arguments = [];
            foreach ($chunk as $term) {
                $placeholders[] = '(?,?,?,?)';
                array_push($arguments, $term['hash'], $term['length'], $term['prefix'], $term['overflow']);
            }
            $statement = $this->db->prepare(
                'INSERT IGNORE INTO ue_terms(value_hash,value_length,value_prefix,is_overflow) VALUES '
                . implode(',', $placeholders)
            );
            $statement->execute($arguments);
            $sqlBatches++;
        }

        $resolved = [];
        foreach (array_chunk(array_values($terms), self::TERM_BATCH_SIZE) as $chunk) {
            $predicates = [];
            $arguments = [];
            foreach ($chunk as $term) {
                $predicates[] = '(value_hash=? AND value_length=?)';
                $arguments[] = $term['hash'];
                $arguments[] = $term['length'];
            }
            $statement = $this->db->prepare(
                'SELECT id,value_hash,value_length,value_prefix,is_overflow FROM ue_terms WHERE '
                . implode(' OR ', $predicates)
            );
            $statement->execute($arguments);
            $sqlBatches++;
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = bin2hex((string)$row['value_hash']) . ':' . (int)$row['value_length'];
                $expected = $terms[$key] ?? null;
                if (!is_array($expected)) {
                    continue;
                }
                $stored = (string)$row['value_prefix'];
                $expectedPrefix = (string)$expected['prefix'];
                $matches = (int)$row['is_overflow'] === 1
                    ? str_starts_with($stored, $expectedPrefix)
                    : hash_equals($stored, $expectedPrefix);
                if (!$matches || (int)$row['is_overflow'] !== (int)$expected['overflow']) {
                    throw new RuntimeException('Compact lookup term hash collision or stored-prefix mismatch.');
                }
                $resolved[$key] = (int)$row['id'];
            }
        }

        if (count($resolved) !== count($terms)) {
            throw new RuntimeException(
                'Could not resolve all compact lookup terms: expected ' . count($terms)
                . ', resolved ' . count($resolved) . '.'
            );
        }
        return $resolved;
    }

    private function termKey(string $value): string
    {
        return md5($value) . ':' . strlen($value);
    }

    /** @param list<string> $columns @param list<list<mixed>> $rows */
    private function bulkInsert(string $table, array $columns, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('Invalid compact lookup table name.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                throw new RuntimeException('Invalid compact lookup column name.');
            }
        }

        $batches = 0;
        foreach (array_chunk($rows, self::WRITE_BATCH_SIZE) as $chunk) {
            $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            $statement = $this->db->prepare(
                'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES '
                . implode(',', array_fill(0, count($chunk), $rowPlaceholder))
            );
            $arguments = [];
            foreach ($chunk as $row) {
                foreach ($row as $value) {
                    $arguments[] = $value;
                }
            }
            $statement->execute($arguments);
            $batches++;
        }
        return $batches;
    }
}
