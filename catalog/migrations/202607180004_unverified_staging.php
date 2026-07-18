<?php
declare(strict_types=1);

return [
    'version' => '202607180004',
    'description' => 'Add database-backed unverified staging metadata.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_files');

        $gameId = $schema->column('ue_files', 'game_id');
        if (is_array($gameId) && strtoupper((string)$gameId['IS_NULLABLE']) !== 'YES') {
            $db->exec('ALTER TABLE ue_files MODIFY game_id INT UNSIGNED NULL');
        }

        $status = $schema->column('ue_files', 'scan_status');
        if (is_array($status) && !str_contains(strtolower((string)$status['COLUMN_TYPE']), "'unverified'")) {
            $db->exec("ALTER TABLE ue_files MODIFY scan_status ENUM('verified','unverified','duplicate','failed') NOT NULL DEFAULT 'verified'");
        }

        $schema->ensureColumn(
            'ue_files',
            'source_relative_path',
            'ALTER TABLE ue_files ADD COLUMN source_relative_path VARCHAR(1024) NULL DEFAULT NULL AFTER original_name'
        );
        $schema->ensureColumn(
            'ue_files',
            'unverified_queue_key',
            'ALTER TABLE ue_files ADD COLUMN unverified_queue_key CHAR(64) NULL AFTER scan_status'
        );
        $schema->ensureColumn(
            'ue_files',
            'unverified_queue_game_id',
            'ALTER TABLE ue_files ADD COLUMN unverified_queue_game_id INT UNSIGNED NULL AFTER unverified_queue_key'
        );
        $schema->ensureColumn(
            'ue_files',
            'unverified_queue_name',
            'ALTER TABLE ue_files ADD COLUMN unverified_queue_name VARCHAR(255) NULL AFTER unverified_queue_game_id'
        );
        $schema->ensureColumn(
            'ue_files',
            'unverified_reason',
            'ALTER TABLE ue_files ADD COLUMN unverified_reason TEXT NULL AFTER unverified_queue_name'
        );
        $schema->ensureIndex(
            'ue_files',
            'uq_ue_files_unverified_queue_key',
            'ALTER TABLE ue_files ADD UNIQUE KEY uq_ue_files_unverified_queue_key (unverified_queue_key)'
        );
        $schema->ensureIndex(
            'ue_files',
            'idx_ue_files_scan_status',
            'ALTER TABLE ue_files ADD KEY idx_ue_files_scan_status (scan_status)'
        );
        $schema->ensureIndex(
            'ue_files',
            'idx_ue_files_unverified_queue',
            'ALTER TABLE ue_files ADD KEY idx_ue_files_unverified_queue (unverified_queue_game_id, unverified_queue_name)'
        );
    },
];
