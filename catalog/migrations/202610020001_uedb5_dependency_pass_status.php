<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202610020001',
    'description' => 'Track exact UEDB5 dependency-pass completion payloads.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $schema->ensureColumn(
            'ue_uedb5_migration_status',
            'dependency_policy',
            'ALTER TABLE ue_uedb5_migration_status ADD COLUMN dependency_policy VARCHAR(64) NULL AFTER validated_payload_sha256'
        );
        $schema->ensureColumn(
            'ue_uedb5_migration_status',
            'dependency_payload_sha256',
            'ALTER TABLE ue_uedb5_migration_status ADD COLUMN dependency_payload_sha256 BINARY(32) NULL AFTER dependency_policy'
        );
        $schema->ensureColumn(
            'ue_uedb5_migration_status',
            'dependency_completed_at',
            'ALTER TABLE ue_uedb5_migration_status ADD COLUMN dependency_completed_at DATETIME NULL AFTER dependency_payload_sha256'
        );
    },
];
