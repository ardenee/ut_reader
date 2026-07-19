<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/** Verifies the migrated dependency schema during normal application execution. */
final class PdoDependencySchemaManager
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function columnExists(string $table, string $column): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute([$table, $column]);
        return (int)$statement->fetchColumn() > 0;
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

    public function indexExists(string $table, string $index): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
        );
        $statement->execute([$table, $index]);
        return (int)$statement->fetchColumn() > 0;
    }

    public function ensure(): void
    {
        $missing = [];
        foreach (['resolution_source', 'resolution_confidence'] as $column) {
            if (!$this->columnExists('ue_dependencies', $column)) {
                $missing[] = 'ue_dependencies.' . $column;
            }
        }
        foreach (['idx_ue_deps_resolution_source', 'idx_ue_deps_resolution_confidence'] as $index) {
            if (!$this->indexExists('ue_dependencies', $index)) {
                $missing[] = 'index ' . $index;
            }
        }
        foreach (['ue_asset_registry_assets', 'ue_asset_registry_tags', 'ue_asset_registry_dependencies'] as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                'The database schema is not migrated. Missing: ' . implode(', ', $missing)
                . '. Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }
    }

    public function ensureAssetRegistryTables(): void
    {
        $missing = [];
        foreach (['ue_asset_registry_assets', 'ue_asset_registry_tags', 'ue_asset_registry_dependencies'] as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                'The asset registry schema is not migrated. Missing: ' . implode(', ', $missing)
                . '. Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }
    }
}
