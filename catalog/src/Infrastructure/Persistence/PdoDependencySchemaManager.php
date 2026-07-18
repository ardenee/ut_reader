<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/** Owns the legacy dependency-schema upgrade operations during migration. */
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
        if (!$this->columnExists('ue_dependencies', 'resolution_source')) {
            $this->db->exec(
                "ALTER TABLE ue_dependencies ADD COLUMN resolution_source "
                . "VARCHAR(64) NOT NULL DEFAULT 'unknown' AFTER status"
            );
        }

        if (!$this->columnExists('ue_dependencies', 'resolution_confidence')) {
            $this->db->exec(
                "ALTER TABLE ue_dependencies ADD COLUMN resolution_confidence "
                . "VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER resolution_source"
            );
        }

        if (!$this->indexExists('ue_dependencies', 'idx_ue_deps_resolution_source')) {
            $this->db->exec(
                'CREATE INDEX idx_ue_deps_resolution_source '
                . 'ON ue_dependencies (resolution_source)'
            );
        }

        if (!$this->indexExists('ue_dependencies', 'idx_ue_deps_resolution_confidence')) {
            $this->db->exec(
                'CREATE INDEX idx_ue_deps_resolution_confidence '
                . 'ON ue_dependencies (resolution_confidence)'
            );
        }

        $this->ensureAssetRegistryTables();
    }

    public function ensureAssetRegistryTables(): void
    {
        if (!$this->tableExists('ue_asset_registry_assets')) {
            $this->db->exec(
                "CREATE TABLE ue_asset_registry_assets ("
                . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
                . "file_id BIGINT UNSIGNED NOT NULL,"
                . "object_path VARCHAR(1000) NOT NULL,"
                . "package_name VARCHAR(255) NOT NULL,"
                . "package_path VARCHAR(1000) NOT NULL,"
                . "asset_name VARCHAR(255) NOT NULL,"
                . "asset_class VARCHAR(255) NOT NULL DEFAULT '',"
                . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
                . "PRIMARY KEY (id),"
                . "UNIQUE KEY uq_ue_ar_asset_file_object (file_id, object_path(191)),"
                . "KEY idx_ue_ar_asset_package_name (package_name),"
                . "KEY idx_ue_ar_asset_object_path (object_path(191)),"
                . "KEY idx_ue_ar_asset_asset_name (asset_name),"
                . "CONSTRAINT fk_ue_ar_asset_file FOREIGN KEY (file_id) "
                . "REFERENCES ue_files(id) ON DELETE CASCADE"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$this->tableExists('ue_asset_registry_tags')) {
            $this->db->exec(
                "CREATE TABLE ue_asset_registry_tags ("
                . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
                . "asset_id BIGINT UNSIGNED NOT NULL,"
                . "tag_name VARCHAR(255) NOT NULL,"
                . "tag_value TEXT NULL,"
                . "PRIMARY KEY (id),"
                . "KEY idx_ue_ar_tags_asset (asset_id),"
                . "KEY idx_ue_ar_tags_name (tag_name),"
                . "CONSTRAINT fk_ue_ar_tags_asset FOREIGN KEY (asset_id) "
                . "REFERENCES ue_asset_registry_assets(id) ON DELETE CASCADE"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$this->tableExists('ue_asset_registry_dependencies')) {
            $this->db->exec(
                "CREATE TABLE ue_asset_registry_dependencies ("
                . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
                . "file_id BIGINT UNSIGNED NOT NULL,"
                . "source_asset_id BIGINT UNSIGNED NULL,"
                . "dependency_object_path VARCHAR(1000) NOT NULL,"
                . "dependency_type VARCHAR(64) NOT NULL DEFAULT 'unknown',"
                . "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
                . "PRIMARY KEY (id),"
                . "KEY idx_ue_ar_deps_file (file_id),"
                . "KEY idx_ue_ar_deps_asset (source_asset_id),"
                . "KEY idx_ue_ar_deps_object (dependency_object_path(191)),"
                . "CONSTRAINT fk_ue_ar_deps_file FOREIGN KEY (file_id) "
                . "REFERENCES ue_files(id) ON DELETE CASCADE,"
                . "CONSTRAINT fk_ue_ar_deps_asset FOREIGN KEY (source_asset_id) "
                . "REFERENCES ue_asset_registry_assets(id) ON DELETE SET NULL"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }
}
