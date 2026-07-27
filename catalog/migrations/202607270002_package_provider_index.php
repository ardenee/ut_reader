<?php
declare(strict_types=1);

return [
    'version' => '202607270002',
    'description' => 'Materialize primary and alias package providers for fast game-scoped dependency resolution.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_files');
        $schema->requireTable('ue_file_package_aliases');

        $schema->ensureTable(
            'ue_package_providers',
            'CREATE TABLE ue_package_providers ('
            . 'source_kind ENUM("primary","alias") NOT NULL,'
            . 'source_id BIGINT UNSIGNED NOT NULL,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'package_name VARCHAR(255) NOT NULL,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'provider_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (source_kind,source_id),'
            . 'KEY idx_ue_package_providers_lookup (game_id,package_name,file_id),'
            . 'KEY idx_ue_package_providers_file (file_id),'
            . 'CONSTRAINT fk_ue_package_providers_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $schema->ensureIndex(
            'ue_package_providers',
            'idx_ue_package_providers_lookup',
            'ALTER TABLE ue_package_providers ADD KEY idx_ue_package_providers_lookup (game_id,package_name,file_id)'
        );
        $schema->ensureIndex(
            'ue_package_providers',
            'idx_ue_package_providers_file',
            'ALTER TABLE ue_package_providers ADD KEY idx_ue_package_providers_file (file_id)'
        );

        $db->exec(
            'INSERT INTO ue_package_providers(source_kind,source_id,game_id,package_name,file_id,provider_created_at) '
            . 'SELECT "primary",f.id,f.game_id,f.package_name,f.id,f.uploaded_at '
            . 'FROM ue_files f WHERE f.game_id IS NOT NULL AND f.scan_status="verified" '
            . 'ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),package_name=VALUES(package_name),file_id=VALUES(file_id),provider_created_at=VALUES(provider_created_at)'
        );
        $db->exec(
            'INSERT INTO ue_package_providers(source_kind,source_id,game_id,package_name,file_id,provider_created_at) '
            . 'SELECT "alias",a.id,a.game_id,a.package_name,a.file_id,a.created_at '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id AND f.scan_status="verified" '
            . 'ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),package_name=VALUES(package_name),file_id=VALUES(file_id),provider_created_at=VALUES(provider_created_at)'
        );

        // Some installations may have partially executed the earlier trigger-based
        // form of this pending migration. Cleanup is optional because the original
        // 1419 failure occurs before the first trigger is created, and restricted
        // database accounts may also be unable to execute DROP TRIGGER.
        foreach ([
            'trg_ue_files_package_provider_ai',
            'trg_ue_files_package_provider_au',
            'trg_ue_alias_package_provider_ai',
            'trg_ue_alias_package_provider_au',
            'trg_ue_alias_package_provider_ad',
        ] as $trigger) {
            try {
                $db->exec('DROP TRIGGER IF EXISTS ' . $trigger);
            } catch (PDOException $error) {
                error_log('[UnrealDB migration 202607270002] optional trigger cleanup failed for ' . $trigger . ': ' . $error->getMessage());
            }
        }
    },
];
