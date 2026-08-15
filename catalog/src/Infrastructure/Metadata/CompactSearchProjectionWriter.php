<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CompactSearchProjectionWriter` for compact search projection writer.
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

/** Populates the small term references required for search after legacy rows are removed. */
final class CompactSearchProjectionWriter
{
    private const TERM_BATCH_SIZE = 350;
    private const UPDATE_BATCH_SIZE = 500;

    /** @var array<int,bool> */
    private static array $schemaAvailable = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,int>|null $resolvedTermIds
     */
    public function write(array $snapshot, int &$sqlBatches, ?array $resolvedTermIds = null): void
    {
        $this->assertSchema();
        $file = (array)($snapshot['file'] ?? []);
        $imports = (array)($snapshot['imports'] ?? []);
        $exports = (array)($snapshot['exports'] ?? []);
        $paths = (array)($snapshot['paths'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('Compact search projection requires a positive file ID.');
        }

        // Normal compact publication already resolved every import object and
        // export local path before entering the transaction. Standalone callers
        // retain a compatibility resolution path without forcing the hot path to
        // build a second unique-term map and issue duplicate SELECT batches.
        $termIds = $resolvedTermIds
            ?? $this->resolveTermIds($this->snapshotSearchTermValues($snapshot), $sqlBatches);

        $this->assertProjectionCounts($fileId, count($imports), count($exports), $sqlBatches);

        $importBatch = [];
        foreach ($imports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['import_index'];
            $value = (string)$row['object_name'];
            $importBatch[$index] = $this->requiredTermId($termIds, $value);
            if (count($importBatch) >= self::UPDATE_BATCH_SIZE) {
                $this->updateTermBatch(
                    'ue_dependency_links',
                    'import_index',
                    'import_object_term_id',
                    $fileId,
                    $importBatch
                );
                $sqlBatches++;
                $importBatch = [];
            }
        }
        if ($importBatch !== []) {
            $this->updateTermBatch(
                'ue_dependency_links',
                'import_index',
                'import_object_term_id',
                $fileId,
                $importBatch
            );
            $sqlBatches++;
        }

        $exportBatch = [];
        foreach ($exports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['export_index'];
            $value = (string)($paths['exports'][$index]['local'] ?? '');
            $exportBatch[$index] = $this->requiredTermId($termIds, $value);
            if (count($exportBatch) >= self::UPDATE_BATCH_SIZE) {
                $this->updateTermBatch(
                    'ue_export_lookup',
                    'export_index',
                    'local_path_term_id',
                    $fileId,
                    $exportBatch
                );
                $sqlBatches++;
                $exportBatch = [];
            }
        }
        if ($exportBatch !== []) {
            $this->updateTermBatch(
                'ue_export_lookup',
                'export_index',
                'local_path_term_id',
                $fileId,
                $exportBatch
            );
            $sqlBatches++;
        }

        $this->assertTermProjectionCounts(
            $fileId,
            count($imports),
            count($exports),
            $sqlBatches
        );
    }

    private function assertSchema(): void
    {
        $key = spl_object_id($this->db);
        if (!empty(self::$schemaAvailable[$key])) {
            return;
        }

        $columns = [
            ['ue_export_lookup', 'local_path_term_id'],
            ['ue_dependency_links', 'import_object_term_id'],
        ];
        foreach ($columns as [$table, $column]) {
            $statement = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
            );
            $statement->execute([$table, $column]);
            if ($statement->fetchColumn() === false) {
                throw new RuntimeException(
                    'Compact search projection schema is incomplete: missing ' . $table . '.' . $column . '.'
                );
            }
        }
        self::$schemaAvailable[$key] = true;
    }

    private function assertProjectionCounts(
        int $fileId,
        int $expectedImports,
        int $expectedExports,
        int &$sqlBatches
    ): void {
        $statement = $this->db->prepare(
            'SELECT '
            . '(SELECT COUNT(*) FROM ue_dependency_links WHERE file_id=?) dependency_rows,'
            . '(SELECT COUNT(*) FROM ue_export_lookup WHERE file_id=?) export_rows'
        );
        $statement->execute([$fileId, $fileId]);
        $sqlBatches++;
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($row['dependency_rows'] ?? -1) !== $expectedImports) {
            throw new RuntimeException(
                'Compact dependency row count mismatch for file #' . $fileId
                . ': expected ' . $expectedImports . ', found ' . (int)($row['dependency_rows'] ?? -1) . '.'
            );
        }
        if ((int)($row['export_rows'] ?? -1) !== $expectedExports) {
            throw new RuntimeException(
                'Compact Export row count mismatch for file #' . $fileId
                . ': expected ' . $expectedExports . ', found ' . (int)($row['export_rows'] ?? -1) . '.'
            );
        }
    }

    private function assertTermProjectionCounts(
        int $fileId,
        int $expectedImports,
        int $expectedExports,
        int &$sqlBatches
    ): void {
        $statement = $this->db->prepare(
            'SELECT '
            . '(SELECT COUNT(*) FROM ue_dependency_links '
            . 'WHERE file_id=? AND import_object_term_id IS NOT NULL) import_term_rows,'
            . '(SELECT COUNT(*) FROM ue_export_lookup '
            . 'WHERE file_id=? AND local_path_term_id IS NOT NULL) export_term_rows'
        );
        $statement->execute([$fileId, $fileId]);
        $sqlBatches++;
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $actualImports = (int)($row['import_term_rows'] ?? -1);
        $actualExports = (int)($row['export_term_rows'] ?? -1);
        if ($actualImports !== $expectedImports) {
            throw new RuntimeException(
                'Compact Import search-term count mismatch for file #' . $fileId
                . ': expected ' . $expectedImports . ', found ' . $actualImports . '.'
            );
        }
        if ($actualExports !== $expectedExports) {
            throw new RuntimeException(
                'Compact Export search-term count mismatch for file #' . $fileId
                . ': expected ' . $expectedExports . ', found ' . $actualExports . '.'
            );
        }
    }

    /** @param array<int,int> $termIdsByIndex */
    private function updateTermBatch(
        string $table,
        string $indexColumn,
        string $termColumn,
        int $fileId,
        array $termIdsByIndex
    ): void {
        if ($termIdsByIndex === []) {
            return;
        }
        if (count($termIdsByIndex) > self::UPDATE_BATCH_SIZE) {
            throw new RuntimeException('Compact search update exceeded the bounded row limit.');
        }

        $cases = [];
        $in = [];
        $arguments = [];
        foreach ($termIdsByIndex as $index => $termId) {
            $cases[] = 'WHEN ? THEN ?';
            $arguments[] = (int)$index;
            $arguments[] = (int)$termId;
            $in[] = '?';
        }
        $arguments[] = $fileId;
        foreach (array_keys($termIdsByIndex) as $index) {
            $arguments[] = (int)$index;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . $termColumn . '=CASE ' . $indexColumn . ' '
            . implode(' ', $cases) . ' ELSE ' . $termColumn . ' END '
            . 'WHERE file_id=? AND ' . $indexColumn . ' IN (' . implode(',', $in) . ')';
        $statement = $this->db->prepare($sql);
        $statement->execute($arguments);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return \Generator<int,string>
     */
    private function snapshotSearchTermValues(array $snapshot): \Generator
    {
        $paths = (array)($snapshot['paths'] ?? []);
        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            if (is_array($row)) {
                yield (string)($row['object_name'] ?? '');
            }
        }
        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row['export_index'] ?? -1);
            yield (string)($index >= 0 ? ($paths['exports'][$index]['local'] ?? '') : '');
        }
    }

    /** @param iterable<string> $values @return array<string,int> */
    private function resolveTermIds(iterable $values, int &$sqlBatches): array
    {
        $terms = [];
        foreach ($values as $value) {
            $length = strlen($value);
            if ($length > 65535) {
                throw new RuntimeException('Compact search term exceeds 65,535 bytes.');
            }
            $key = $this->termKey($value);
            if (isset($terms[$key]) && !hash_equals((string)$terms[$key]['value'], $value)) {
                throw new RuntimeException('Compact search term hash collision detected inside conversion batch.');
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

        // Retain compatibility for standalone callers. Snapshot publication is in
        // a file-owned transaction and therefore performs read-only resolution.
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
                'Could not resolve all compact search terms: expected ' . count($terms)
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
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO ue_terms(value_hash,value_length,value_prefix,is_overflow) VALUES '
            . implode(',', $placeholders)
        );
        $statement->execute($arguments);
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
                throw new RuntimeException('Compact search term hash collision or stored-prefix mismatch.');
            }
            $resolved[$key] = (int)$row['id'];
        }
    }

    /** @param array<string,int> $termIds */
    private function requiredTermId(array $termIds, string $value): int
    {
        $key = $this->termKey($value);
        if (!isset($termIds[$key])) {
            throw new RuntimeException('Compact search term was not resolved before projection publication.');
        }
        return (int)$termIds[$key];
    }

    private function termKey(string $value): string
    {
        return md5($value) . ':' . strlen($value);
    }
}
