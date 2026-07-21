<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202607210002',
    'description' => 'Remove orphan package-table rows and enforce game-reset delete cascades.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $quote = static fn(string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';

        $deleteOrphans = static function (
            string $table,
            string $column,
            string $parentTable,
            string $parentColumn = 'id'
        ) use ($db, $schema, $quote): void {
            if (!$schema->tableExists($table) || !$schema->tableExists($parentTable)) {
                return;
            }
            $db->exec(
                'DELETE child FROM ' . $quote($table) . ' child '
                . 'LEFT JOIN ' . $quote($parentTable) . ' parent '
                . 'ON parent.' . $quote($parentColumn) . '=child.' . $quote($column) . ' '
                . 'WHERE child.' . $quote($column) . ' IS NOT NULL '
                . 'AND parent.' . $quote($parentColumn) . ' IS NULL'
            );
        };

        $nullOrphans = static function (
            string $table,
            string $column,
            string $parentTable,
            string $parentColumn = 'id'
        ) use ($db, $schema, $quote): void {
            if (!$schema->tableExists($table) || !$schema->tableExists($parentTable)) {
                return;
            }
            $db->exec(
                'UPDATE ' . $quote($table) . ' child '
                . 'LEFT JOIN ' . $quote($parentTable) . ' parent '
                . 'ON parent.' . $quote($parentColumn) . '=child.' . $quote($column) . ' '
                . 'SET child.' . $quote($column) . '=NULL '
                . 'WHERE child.' . $quote($column) . ' IS NOT NULL '
                . 'AND parent.' . $quote($parentColumn) . ' IS NULL'
            );
        };

        $ensureForeignKey = static function (
            string $table,
            string $column,
            string $parentTable,
            string $parentColumn,
            string $deleteRule,
            string $constraintName
        ) use ($db, $schema, $quote): void {
            if (!$schema->tableExists($table) || !$schema->tableExists($parentTable)) {
                return;
            }

            $statement = $db->prepare(
                'SELECT k.CONSTRAINT_NAME,COALESCE(r.DELETE_RULE,"") delete_rule '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                . 'ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA '
                . 'AND r.TABLE_NAME=k.TABLE_NAME '
                . 'AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
                . 'WHERE k.CONSTRAINT_SCHEMA=DATABASE() '
                . 'AND k.TABLE_NAME=? AND k.COLUMN_NAME=? '
                . 'AND k.REFERENCED_TABLE_NAME=? AND k.REFERENCED_COLUMN_NAME=?'
            );
            $statement->execute([$table, $column, $parentTable, $parentColumn]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $correctExists = false;

            foreach ($rows as $row) {
                $existingName = (string)($row['CONSTRAINT_NAME'] ?? '');
                $existingRule = strtoupper((string)($row['delete_rule'] ?? ''));
                if ($existingName === '') {
                    continue;
                }
                if ($existingRule === strtoupper($deleteRule)) {
                    $correctExists = true;
                    continue;
                }
                $db->exec(
                    'ALTER TABLE ' . $quote($table)
                    . ' DROP FOREIGN KEY ' . $quote($existingName)
                );
            }

            if ($correctExists) {
                return;
            }

            $nameCheck = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?'
            );
            $nameCheck->execute([$table, $constraintName]);
            if ((int)$nameCheck->fetchColumn() > 0) {
                $constraintName .= '_reset';
            }

            $db->exec(
                'ALTER TABLE ' . $quote($table)
                . ' ADD CONSTRAINT ' . $quote($constraintName)
                . ' FOREIGN KEY (' . $quote($column) . ')'
                . ' REFERENCES ' . $quote($parentTable) . ' (' . $quote($parentColumn) . ')'
                . ' ON DELETE ' . strtoupper($deleteRule)
            );
        };

        /* Clear optional references before deleting their parent rows. */
        $nullOrphans('ue_dependencies', 'resolved_file_id', 'ue_files');
        $nullOrphans('ue_dependencies', 'resolved_export_id', 'ue_exports');
        $nullOrphans('ue_asset_registry_dependencies', 'source_asset_id', 'ue_asset_registry_assets');
        $nullOrphans('ue_pak_entries', 'file_id', 'ue_files');
        $nullOrphans('ue_base_game_files', 'source_file_id', 'ue_files');

        /* Delete dependency rows before imports/exports and asset rows. */
        $deleteOrphans('ue_dependencies', 'file_id', 'ue_files');
        $deleteOrphans('ue_dependencies', 'import_id', 'ue_imports');
        $deleteOrphans('ue_asset_registry_dependencies', 'file_id', 'ue_files');

        if (
            $schema->tableExists('ue_asset_registry_tags')
            && $schema->tableExists('ue_asset_registry_assets')
            && $schema->tableExists('ue_files')
        ) {
            $db->exec(
                'DELETE tags FROM ue_asset_registry_tags tags '
                . 'LEFT JOIN ue_asset_registry_assets assets ON assets.id=tags.asset_id '
                . 'LEFT JOIN ue_files files ON files.id=assets.file_id '
                . 'WHERE assets.id IS NULL OR files.id IS NULL'
            );
        }

        $deleteOrphans('ue_asset_registry_assets', 'file_id', 'ue_files');
        $deleteOrphans('ue_file_locations', 'file_id', 'ue_files');
        $deleteOrphans('ue_file_package_aliases', 'file_id', 'ue_files');
        $deleteOrphans('ue_names', 'file_id', 'ue_files');
        $deleteOrphans('ue_imports', 'file_id', 'ue_files');
        $deleteOrphans('ue_exports', 'file_id', 'ue_files');

        /* Existing databases may predate the baseline foreign keys. Repair them. */
        $ensureForeignKey('ue_names', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_names_file');
        $ensureForeignKey('ue_imports', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_imports_file');
        $ensureForeignKey('ue_exports', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_exports_file');
        $ensureForeignKey('ue_dependencies', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_deps_file');
        $ensureForeignKey('ue_dependencies', 'import_id', 'ue_imports', 'id', 'CASCADE', 'fk_ue_deps_import');
        $ensureForeignKey('ue_dependencies', 'resolved_file_id', 'ue_files', 'id', 'SET NULL', 'fk_ue_deps_resolved_file');
        $ensureForeignKey('ue_dependencies', 'resolved_export_id', 'ue_exports', 'id', 'SET NULL', 'fk_ue_deps_resolved_export');
        $ensureForeignKey('ue_file_locations', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_file_locations_file');
        $ensureForeignKey('ue_file_package_aliases', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_file_alias_file');
        $ensureForeignKey('ue_asset_registry_assets', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_ar_asset_file');
        $ensureForeignKey('ue_asset_registry_tags', 'asset_id', 'ue_asset_registry_assets', 'id', 'CASCADE', 'fk_ue_ar_tags_asset');
        $ensureForeignKey('ue_asset_registry_dependencies', 'file_id', 'ue_files', 'id', 'CASCADE', 'fk_ue_ar_deps_file');
        $ensureForeignKey('ue_asset_registry_dependencies', 'source_asset_id', 'ue_asset_registry_assets', 'id', 'SET NULL', 'fk_ue_ar_deps_asset');
        $ensureForeignKey('ue_pak_entries', 'file_id', 'ue_files', 'id', 'SET NULL', 'fk_ue_pak_entries_file');
    },
];
