<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Writes current compact global lookup projections using bounded multi-row SQL.
 * Why: Format-1 metadata publication is retired; callers must explicitly publish the authoritative current container version and codec.
 * Role: Infrastructure current metadata projection writer.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

final class CompressedMetadataLookupWriter
{
    private const TERM_BATCH_SIZE = 350;
    private const WRITE_BATCH_SIZE = 500;
    private const TERM_CONTENTION_ATTEMPTS = 8;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $snapshot @return array<string,int> */
    public function primeSnapshotTerms(array $snapshot, int &$sqlBatches): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Compact term dictionary priming must run outside a snapshot transaction.');
        }
        return $this->resolveTermIds($this->snapshotTermValues($snapshot), $sqlBatches);
    }

    /**
     * Compatibility entry point for callers that already hold container bytes.
     * Production publication uses writeVersionedMetadata() so it never needs a
     * full .uedb2 PHP string merely to register size and SHA-256.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,int>|null $resolvedTermIds
     */
    public function writeVersioned(
        array $snapshot,
        string $storedBytes,
        int $uncompressedSize,
        int $formatVersion,
        int $codec,
        int &$sqlBatches,
        ?array $resolvedTermIds = null
    ): void {
        $this->writeVersionedMetadata(
            $snapshot,
            strlen($storedBytes),
            hash('sha256', $storedBytes, true),
            $uncompressedSize,
            $formatVersion,
            $codec,
            $sqlBatches,
            $resolvedTermIds
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,int>|null $resolvedTermIds
     */
    public function writeVersionedMetadata(
        array $snapshot,
        int $compressedSize,
        string $payloadSha256,
        int $uncompressedSize,
        int $formatVersion,
        int $codec,
        int &$sqlBatches,
        ?array $resolvedTermIds = null
    ): void {
        if ($compressedSize < 1 || strlen($payloadSha256) !== 32) {
            throw new RuntimeException('Compact metadata registration requires a valid size and binary SHA-256.');
        }

        $file = (array)$snapshot['file'];
        $imports = (array)$snapshot['imports'];
        $exports = (array)$snapshot['exports'];
        $dependencies = (array)$snapshot['dependencies'];
        $paths = (array)$snapshot['paths'];
        $fileId = (int)$file['id'];
        $resolutionLabels = $this->dependencyResolutionLabels($dependencies);

        $importsByIndex = [];
        foreach ($imports as $row) {
            if (is_array($row)) {
                $importsByIndex[(int)$row['import_index']] = $row;
            }
        }

        $termIds = $resolvedTermIds
            ?? $this->resolveTermIds($this->snapshotTermValues($snapshot), $sqlBatches);

        $this->db->prepare('DELETE FROM ue_export_lookup WHERE file_id=?')->execute([$fileId]);
        $this->db->prepare('DELETE FROM ue_dependency_links WHERE file_id=?')->execute([$fileId]);
        $sqlBatches += 2;

        $exportColumns = [
            'file_id', 'export_index', 'object_term_id', 'class_term_id', 'path_hash', 'local_path_term_id',
        ];
        $exportRows = [];
        foreach ($exports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['export_index'];
            $object = (string)$row['object_name'];
            $class = trim((string)($row['class_name'] ?? ''));
            $localPath = (string)($paths['exports'][$index]['local'] ?? '');
            $exportRows[] = [
                $fileId,
                $index,
                $this->requiredTermId($termIds, $object),
                $class !== '' ? $this->requiredTermId($termIds, $class) : null,
                md5($localPath, true),
                $this->requiredTermId($termIds, $localPath),
            ];
            if (count($exportRows) >= self::WRITE_BATCH_SIZE) {
                $this->insertBatch('ue_export_lookup', $exportColumns, $exportRows);
                $sqlBatches++;
                $exportRows = [];
            }
        }
        if ($exportRows !== []) {
            $this->insertBatch('ue_export_lookup', $exportColumns, $exportRows);
            $sqlBatches++;
        }

        $dependencyColumns = [
            'file_id', 'import_index', 'required_package_term_id', 'required_path_hash',
            'required_object_term_id', 'import_class_package_term_id', 'import_class_name_term_id',
            'resolved_file_id', 'resolved_export_index', 'status', 'resolution_source',
            'resolution_confidence', 'resolution_source_term_id', 'resolution_confidence_term_id',
        ];
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
                $this->requiredTermId($termIds, (string)$row['required_package']),
                md5((string)$paths['imports'][$index]['relative'], true),
                $this->requiredTermId($termIds, $requiredObject),
                $classPackage !== '' ? $this->requiredTermId($termIds, $classPackage) : null,
                $className !== '' ? $this->requiredTermId($termIds, $className) : null,
                $row['resolved_file_id'] !== null ? (int)$row['resolved_file_id'] : null,
                $row['resolved_export_index'] !== null ? (int)$row['resolved_export_index'] : null,
                $status,
                $sourceCode,
                $confidenceCode,
                $this->requiredTermId($termIds, $source),
                $this->requiredTermId($termIds, $confidence),
            ];
            if (count($dependencyRows) >= self::WRITE_BATCH_SIZE) {
                $this->insertBatch('ue_dependency_links', $dependencyColumns, $dependencyRows);
                $sqlBatches++;
                $dependencyRows = [];
            }
        }
        if ($dependencyRows !== []) {
            $this->insertBatch('ue_dependency_links', $dependencyColumns, $dependencyRows);
            $sqlBatches++;
        }

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
            $compressedSize,
            $uncompressedSize,
            $payloadSha256,
            count((array)$snapshot['names']),
            count((array)$snapshot['imports']),
            count((array)$snapshot['exports']),
            $timestamp,
            $timestamp,
        ]);
        $sqlBatches++;
    }

    /** @param array<int,mixed> $dependencies @return array<int,array{source:string,confidence:string}> */
    private function dependencyResolutionLabels(array $dependencies): array
    {
        $labels = [];
        foreach ($dependencies as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Dependency snapshot contains a non-row value.');
            }
            $index = (int)($row['import_index'] ?? -1);
            $source = trim((string)($row['resolution_source'] ?? ''));
            $confidence = trim((string)($row['resolution_confidence'] ?? ''));
            if ($index < 0 || $source === '' || $confidence === '') {
                throw new RuntimeException(
                    'Current compact dependency snapshot is missing resolution labels for import index ' . $index . '.'
                );
            }
            $labels[$index] = ['source' => $source, 'confidence' => $confidence];
        }
        if (count($labels) !== count($dependencies)) {
            throw new RuntimeException('Current compact dependency snapshot contains duplicate import indexes.');
        }
        return $labels;
    }

    /** @param array<string,mixed> $snapshot @return \Generator<int,string> */
    private function snapshotTermValues(array $snapshot): \Generator
    {
        $paths = (array)($snapshot['paths'] ?? []);

        foreach ((array)($snapshot['names'] ?? []) as $row) {
            if (is_array($row)) {
                yield (string)($row['name_text'] ?? '');
            }
        }

        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row['export_index'] ?? -1);
            yield (string)($row['object_name'] ?? '');
            yield (string)($index >= 0 ? ($paths['exports'][$index]['local'] ?? '') : '');
            $className = trim((string)($row['class_name'] ?? ''));
            if ($className !== '') {
                yield $className;
            }
        }

        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            yield (string)($row['object_name'] ?? '');
            $classPackage = trim((string)($row['class_package'] ?? ''));
            $className = trim((string)($row['class_name'] ?? ''));
            if ($classPackage !== '') {
                yield $classPackage;
            }
            if ($className !== '') {
                yield $className;
            }
        }

        foreach ((array)($snapshot['dependencies'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            yield (string)($row['required_package'] ?? '');
            yield (string)($row['required_object_path'] ?? '');
            yield (string)($row['resolution_source'] ?? '');
            yield (string)($row['resolution_confidence'] ?? '');
        }
    }

    /** @param iterable<string> $values @return array<string,int> */
    private function resolveTermIds(iterable $values, int &$sqlBatches): array
    {
        $terms = [];
        foreach ($values as $value) {
            $length = strlen($value);
            if ($length > 65535) {
                throw new RuntimeException('Compact lookup term exceeds 65,535 bytes.');
            }
            $key = $this->termKey($value);
            if (isset($terms[$key]) && !hash_equals((string)$terms[$key]['value'], $value)) {
                throw new RuntimeException('Compact lookup term hash collision detected inside conversion batch.');
            }
            if (!isset($terms[$key])) {
                $terms[$key] = [
                    'value' => $value,
                    'hash' => md5($value, true),
                    'length' => $length,
                    'prefix' => substr($value, 0, 200),
                    'overflow' => $length > 200 ? 1 : 0,
                ];
            }
        }
        if ($terms === []) {
            return [];
        }

        ksort($terms, SORT_STRING);
        if (!$this->db->inTransaction()) {
            $chunk = [];
            foreach ($terms as $term) {
                $chunk[] = $term;
                if (count($chunk) >= self::TERM_BATCH_SIZE) {
                    $this->insertTermBatch($chunk);
                    $sqlBatches++;
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->insertTermBatch($chunk);
                $sqlBatches++;
            }
        }

        $resolved = [];
        $chunk = [];
        foreach ($terms as $term) {
            $chunk[] = $term;
            if (count($chunk) >= self::TERM_BATCH_SIZE) {
                $this->resolveTermBatch($chunk, $terms, $resolved);
                $sqlBatches++;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->resolveTermBatch($chunk, $terms, $resolved);
            $sqlBatches++;
        }

        if (count($resolved) !== count($terms)) {
            throw new RuntimeException(
                'Could not resolve all compact lookup terms: expected ' . count($terms)
                . ', resolved ' . count($resolved) . '.'
            );
        }
        return $resolved;
    }

    /** @param list<array{value:string,hash:string,length:int,prefix:string,overflow:int}> $chunk */
    private function insertTermBatch(array $chunk): void
    {
        $placeholders = [];
        $arguments = [];
        foreach ($chunk as $term) {
            $placeholders[] = '(?,?,?,?)';
            array_push($arguments, $term['hash'], $term['length'], $term['prefix'], $term['overflow']);
        }
        $this->executeWithContentionRetry(
            'INSERT IGNORE INTO ue_terms(value_hash,value_length,value_prefix,is_overflow) VALUES '
                . implode(',', $placeholders),
            $arguments
        );
    }

    /**
     * @param list<array{value:string,hash:string,length:int,prefix:string,overflow:int}> $chunk
     * @param array<string,array{value:string,hash:string,length:int,prefix:string,overflow:int}> $terms
     * @param array<string,int> $resolved
     */
    private function resolveTermBatch(array $chunk, array $terms, array &$resolved): void
    {
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
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
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

    /** @param list<mixed> $arguments */
    private function executeWithContentionRetry(string $sql, array $arguments): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $statement = $this->db->prepare($sql);
                $statement->execute($arguments);
                return;
            } catch (Throwable $error) {
                if (!PdoContention::retryable($error) || $attempt >= self::TERM_CONTENTION_ATTEMPTS) {
                    throw $error;
                }
                usleep(PdoContention::backoffMicros($attempt, 10000));
            }
        }
    }

    /** @param array<string,int> $termIds */
    private function requiredTermId(array $termIds, string $value): int
    {
        $key = $this->termKey($value);
        if (!isset($termIds[$key])) {
            throw new RuntimeException('Compact lookup term was not resolved before projection publication.');
        }
        return (int)$termIds[$key];
    }

    private function termKey(string $value): string
    {
        return md5($value) . ':' . strlen($value);
    }

    /** @param list<string> $columns @param list<list<mixed>> $rows */
    private function insertBatch(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (count($rows) > self::WRITE_BATCH_SIZE) {
            throw new RuntimeException('Compact lookup insert batch exceeded the bounded row limit.');
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('Invalid compact lookup table name.');
        }
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                throw new RuntimeException('Invalid compact lookup column name.');
            }
        }

        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $statement = $this->db->prepare(
            'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($rows), $rowPlaceholder))
        );
        $arguments = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $arguments[] = $value;
            }
        }
        $statement->execute($arguments);
    }
}
