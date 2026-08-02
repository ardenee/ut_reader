<?php
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
        $exports = (array)$snapshot['exports'];
        $dependencies = (array)$snapshot['dependencies'];
        $paths = (array)$snapshot['paths'];
        $fileId = (int)$file['id'];

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
            if (is_array($row)) {
                $values[] = (string)$row['required_package'];
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
            [$status, $source, $confidence] = CompressedMetadataLegacySnapshot::dependencyCodes(
                strtolower(trim((string)$row['status']))
            );
            $dependencyRows[] = [
                $fileId,
                $index,
                $termIds[$this->termKey((string)$row['required_package'])],
                md5((string)$paths['imports'][$index]['relative'], true),
                $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                $status,
                $source,
                $confidence,
            ];
        }
        $sqlBatches += $this->bulkInsert(
            'ue_dependency_links',
            [
                'file_id', 'import_index', 'required_package_term_id', 'required_path_hash',
                'resolved_file_id', 'resolved_export_index', 'status', 'resolution_source',
                'resolution_confidence',
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
                if (
                    !hash_equals((string)$row['value_prefix'], (string)$expected['prefix'])
                    || (int)$row['is_overflow'] !== (int)$expected['overflow']
                ) {
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
