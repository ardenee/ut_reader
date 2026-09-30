<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609300002',
    'description' => 'Add durable per-file UEDB5 migration validation status.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_uedb5_migration_status',
            'CREATE TABLE ue_uedb5_migration_status ('
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'status ENUM("pending","staged","validated","failed") NOT NULL DEFAULT "pending",'
            . 'validator_policy VARCHAR(64) NULL,'
            . 'last_checked_payload_sha256 BINARY(32) NULL,'
            . 'validated_payload_sha256 BINARY(32) NULL,'
            . 'attempt_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_error_code VARCHAR(64) NULL,'
            . 'last_error_text TEXT NULL,'
            . 'last_result_json JSON NULL,'
            . 'staged_at DATETIME NULL,'
            . 'validated_at DATETIME NULL,'
            . 'failed_at DATETIME NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (file_id),'
            . 'KEY idx_ue_uedb5_migration_status_game (game_id,status,file_id),'
            . 'CONSTRAINT fk_ue_uedb5_migration_status_file '
            . 'FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
