<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/** Stores complete values for compact terms longer than the historical 200-byte prefix. */
final class CompactTermOverflowWriter
{
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

        $statement = $this->db->prepare(
            'UPDATE ue_terms SET value_prefix=? '
            . 'WHERE value_hash=? AND value_length=? AND is_overflow=1'
        );
        $verify = $this->db->prepare(
            'SELECT id,value_prefix FROM ue_terms '
            . 'WHERE value_hash=? AND value_length=? AND is_overflow=1 LIMIT 1'
        );

        $written = 0;
        foreach ($values as $value) {
            $hash = md5($value, true);
            $length = strlen($value);
            $statement->execute([$value, $hash, $length]);
            $sqlBatches++;

            $verify->execute([$hash, $length]);
            $sqlBatches++;
            $row = $verify->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !hash_equals((string)$row['value_prefix'], $value)) {
                throw new RuntimeException(
                    'Compact overflow term could not be stored completely: length=' . $length . '.'
                );
            }
            $written++;
        }
        return $written;
    }

    /** @param array<string,mixed> $snapshot @return list<string> */
    private function collect(array $snapshot): array
    {
        $values = [];
        $add = static function (mixed $value) use (&$values): void {
            $value = (string)$value;
            if (strlen($value) <= 200) {
                return;
            }
            $key = md5($value) . ':' . strlen($value);
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

        return array_values($values);
    }
}
