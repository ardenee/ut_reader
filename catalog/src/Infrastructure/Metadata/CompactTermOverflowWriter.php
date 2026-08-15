<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CompactTermOverflowWriter` for compact term overflow writer.
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

/** Stores complete values for compact terms longer than the historical 200-byte prefix. */
final class CompactTermOverflowWriter
{
    private const BATCH_SIZE = 200;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $snapshot */
    public function write(array $snapshot, int &$sqlBatches): int
    {
        $values = $this->collect($snapshot);
        if ($values === []) {
            return 0;
        }

        $written = 0;
        $chunk = [];
        foreach ($values as $key => $value) {
            $chunk[$key] = $value;
            if (count($chunk) >= self::BATCH_SIZE) {
                $written += $this->writeBatch($chunk, $sqlBatches);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $written += $this->writeBatch($chunk, $sqlBatches);
        }
        return $written;
    }

    /**
     * Validate the existing hash/length rows before publishing full values, then
     * write and verify the whole chunk in three SQL round trips. The historical
     * implementation used UPDATE+SELECT per term, which scaled as 2N statements.
     *
     * @param array<string,string> $values
     */
    private function writeBatch(array $values, int &$sqlBatches): int
    {
        $this->validateStoredPrefixes($values, $sqlBatches);

        $placeholders = [];
        $arguments = [];
        foreach ($values as $value) {
            $placeholders[] = '(?,?,?,1)';
            $arguments[] = md5($value, true);
            $arguments[] = strlen($value);
            $arguments[] = $value;
        }
        $statement = $this->db->prepare(
            'INSERT INTO ue_terms(value_hash,value_length,value_prefix,is_overflow) VALUES '
            . implode(',', $placeholders)
            . ' ON DUPLICATE KEY UPDATE value_prefix=VALUES(value_prefix),is_overflow=1'
        );
        $statement->execute($arguments);
        $sqlBatches++;

        $this->verifyStoredValues($values, $sqlBatches);
        return count($values);
    }

    /** @param array<string,string> $values */
    private function validateStoredPrefixes(array $values, int &$sqlBatches): void
    {
        $rows = $this->selectTerms($values, $sqlBatches);
        if (count($rows) !== count($values)) {
            throw new RuntimeException(
                'Compact overflow terms were not fully primed before overflow publication.'
            );
        }

        foreach ($rows as $key => $stored) {
            $expected = $values[$key] ?? null;
            if (!is_string($expected)) {
                throw new RuntimeException('Compact overflow term lookup returned an unexpected identity.');
            }
            $prefix = substr($expected, 0, 200);
            if (!hash_equals($stored, $expected) && !hash_equals($stored, $prefix)) {
                throw new RuntimeException('Compact overflow term hash collision or stored-prefix mismatch.');
            }
        }
    }

    /** @param array<string,string> $values */
    private function verifyStoredValues(array $values, int &$sqlBatches): void
    {
        $rows = $this->selectTerms($values, $sqlBatches);
        if (count($rows) !== count($values)) {
            throw new RuntimeException('Compact overflow term publication was incomplete.');
        }
        foreach ($values as $key => $expected) {
            $stored = $rows[$key] ?? null;
            if (!is_string($stored) || !hash_equals($stored, $expected)) {
                throw new RuntimeException(
                    'Compact overflow term could not be stored completely: length=' . strlen($expected) . '.'
                );
            }
        }
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function selectTerms(array $values, int &$sqlBatches): array
    {
        $predicates = [];
        $arguments = [];
        foreach ($values as $value) {
            $predicates[] = '(value_hash=? AND value_length=?)';
            $arguments[] = md5($value, true);
            $arguments[] = strlen($value);
        }
        $statement = $this->db->prepare(
            'SELECT value_hash,value_length,value_prefix FROM ue_terms WHERE is_overflow=1 AND ('
            . implode(' OR ', $predicates) . ')'
        );
        $statement->execute($arguments);
        $sqlBatches++;

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = bin2hex((string)$row['value_hash']) . ':' . (int)$row['value_length'];
            $rows[$key] = (string)$row['value_prefix'];
        }
        return $rows;
    }

    /** @param array<string,mixed> $snapshot @return array<string,string> */
    private function collect(array $snapshot): array
    {
        $values = [];
        $add = static function (mixed $value) use (&$values): void {
            $value = (string)$value;
            $length = strlen($value);
            if ($length <= 200) {
                return;
            }
            $key = md5($value) . ':' . $length;
            if (isset($values[$key]) && !hash_equals($values[$key], $value)) {
                throw new RuntimeException('Compact overflow term hash collision detected.');
            }
            $values[$key] = $value;
        };

        $paths = (array)($snapshot['paths'] ?? []);
        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row['export_index'] ?? -1);
            $add($row['object_name'] ?? '');
            $add($row['class_name'] ?? '');
            if ($index >= 0) {
                $add($paths['exports'][$index]['local'] ?? ($row['local_path'] ?? ''));
            }
        }

        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $add($row['object_name'] ?? '');
            $add($row['class_package'] ?? '');
            $add($row['class_name'] ?? '');
        }

        foreach ((array)($snapshot['dependencies'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $add($row['required_package'] ?? '');
            $add($row['required_object_path'] ?? '');
            $add($row['resolution_source'] ?? '');
            $add($row['resolution_confidence'] ?? '');
        }

        return $values;
    }
}
