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

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $snapshot */
    public function write(array $snapshot, int &$sqlBatches): void
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

        $values = [];
        $importValues = [];
        foreach ($imports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['import_index'];
            $value = (string)$row['object_name'];
            $values[] = $value;
            $importValues[$index] = $value;
        }

        $exportValues = [];
        foreach ($exports as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)$row['export_index'];
            $path = (string)($paths['exports'][$index]['local'] ?? '');
            $values[] = $path;
            $exportValues[$index] = $path;
        }

        $termIds = $this->resolveTermIds($values, $sqlBatches);
        $this->assertProjectionCounts($fileId, count($imports), count($exports), $sqlBatches);

        $importTerms = [];
        foreach ($importValues as $index => $value) {
            $importTerms[(int)$index] = $termIds[$this->termKey($value)];
        }
        $this->updateTermColumn(
            'ue_dependency_links',
            'import_index',
            'import_object_term_id',
            $fileId,
            $importTerms,
            $sqlBatches
        );

        $exportTerms = [];
        foreach ($exportValues as $index => $value) {
            $exportTerms[(int)$index] = $termIds[$this->termKey($value)];
        }
        $this->updateTermColumn(
            'ue_export_lookup',
            'export_index',
            'local_path_term_id',
            $fileId,
            $exportTerms,
            $sqlBatches
        );

        $this->assertTermProjectionCounts(
            $fileId,
            count($imports),
            count($exports),
            $sqlBatches
        );
        (new CompactTermOverflowWriter($this->db))->write($snapshot, $sqlBatches);
    }

    private function assertSchema(): void
    {
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
    private function updateTermColumn(
        string $table,
        string $indexColumn,
        string $termColumn,
        int $fileId,
        array $termIdsByIndex,
        int &$sqlBatches
    ): void {
        if ($termIdsByIndex === []) {
            return;
        }
        foreach (array_chunk($termIdsByIndex, self::UPDATE_BATCH_SIZE, true) as $chunk) {
            $cases = [];
            $in = [];
            $arguments = [];
            foreach ($chunk as $index => $termId) {
                $cases[] = 'WHEN ? THEN ?';
                $arguments[] = (int)$index;
                $arguments[] = (int)$termId;
                $in[] = '?';
            }
            $arguments[] = $fileId;
            foreach (array_keys($chunk) as $index) {
                $arguments[] = (int)$index;
            }
            $sql = 'UPDATE ' . $table . ' SET ' . $termColumn . '=CASE ' . $indexColumn . ' '
                . implode(' ', $cases) . ' ELSE ' . $termColumn . ' END '
                . 'WHERE file_id=? AND ' . $indexColumn . ' IN (' . implode(',', $in) . ')';
            $statement = $this->db->prepare($sql);
            $statement->execute($arguments);
            $sqlBatches++;
        }
    }

    /** @param list<string> $values @return array<string,int> */
    private function resolveTermIds(array $values, int &$sqlBatches): array
    {
        $terms = [];
        foreach ($values as $value) {
            if (strlen($value) > 65535) {
                throw new RuntimeException('Compact search term exceeds 65,535 bytes.');
            }
            $key = $this->termKey($value);
            if (isset($terms[$key]) && !hash_equals((string)$terms[$key]['value'], $value)) {
                throw new RuntimeException('Compact search term hash collision detected inside conversion batch.');
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
                    throw new RuntimeException('Compact search term hash collision or stored-prefix mismatch.');
                }
                $resolved[$key] = (int)$row['id'];
            }
        }
        if (count($resolved) !== count($terms)) {
            throw new RuntimeException(
                'Could not resolve all compact search terms: expected ' . count($terms)
                . ', resolved ' . count($resolved) . '.'
            );
        }
        return $resolved;
    }

    private function termKey(string $value): string
    {
        return md5($value) . ':' . strlen($value);
    }
}
