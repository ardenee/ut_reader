<?php
/**
 * Purpose: Verifies the current compact dependency/projection schema during normal application execution.
 * Why: Runtime schema validation must follow the authoritative format-2 metadata model and must not require retired SQL metadata tables.
 * Role: Infrastructure schema guard used by dependency and asset-metadata entry points.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

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
        $requiredTables = [
            'ue_file_metadata',
            'ue_terms',
            'ue_export_lookup',
            'ue_dependency_links',
            'ue_package_providers',
            'ue_asset_registry_assets',
            'ue_asset_registry_tags',
            'ue_asset_registry_dependencies',
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'The current dependency schema is not migrated. Missing: ' . implode(', ', $missing)
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
            throw new RuntimeException(
                'The asset registry schema is not migrated. Missing: ' . implode(', ', $missing)
                . '. Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }
    }
}
