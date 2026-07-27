<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class SchemaInspector
{
    /** @var array<string,bool>|null */
    private static ?array $deferredIndexes = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }

    /** @return array<string,mixed>|null */
    public function column(string $table, string $column): ?array
    {
        $statement = $this->db->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute([$table, $column]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->column($table, $column) !== null;
    }

    public function indexExists(string $table, string $index): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
        );
        $statement->execute([$table, $index]);
        return (int)$statement->fetchColumn() > 0;
    }

    public function requireTable(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException('Required table is missing: ' . $table);
        }
    }

    public function ensureTable(string $table, string $createSql): void
    {
        if (!$this->tableExists($table)) {
            $this->db->exec($createSql);
        }
    }

    public function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $this->requireTable($table);
        if (!$this->columnExists($table, $column)) {
            $this->db->exec($alterSql);
        }
    }

    public function ensureIndex(string $table, string $index, string $createSql): void
    {
        $this->requireTable($table);
        if ($this->indexExists($table, $index) || $this->indexDeferred($table, $index)) {
            return;
        }
        $this->db->exec($createSql);
    }

    public function indexDeferred(string $table, string $index): bool
    {
        $deferred = self::deferredIndexes();
        return isset($deferred[strtolower($index)])
            || isset($deferred[strtolower($table . '.' . $index)]);
    }

    /** @return array<string,bool> */
    private static function deferredIndexes(): array
    {
        if (self::$deferredIndexes !== null) {
            return self::$deferredIndexes;
        }

        self::$deferredIndexes = [];
        $raw = trim((string)(getenv('UNREALDB_DEFER_INDEXES') ?: ''));
        if ($raw === '') {
            return self::$deferredIndexes;
        }

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $value) {
            $value = strtolower(trim($value));
            if ($value !== '') {
                self::$deferredIndexes[$value] = true;
            }
        }
        return self::$deferredIndexes;
    }
}
