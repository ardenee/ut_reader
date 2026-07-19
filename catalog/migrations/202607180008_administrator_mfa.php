<?php
declare(strict_types=1);

return [
    'version' => '202607180008',
    'description' => 'Add encrypted administrator TOTP MFA and recovery codes.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_users');
        $schema->ensureColumn(
            'ue_users',
            'mfa_totp_secret',
            'ALTER TABLE ue_users ADD COLUMN mfa_totp_secret VARCHAR(512) NULL AFTER password_hash'
        );
        $schema->ensureColumn(
            'ue_users',
            'mfa_recovery_codes_json',
            'ALTER TABLE ue_users ADD COLUMN mfa_recovery_codes_json JSON NULL AFTER mfa_totp_secret'
        );
        $schema->ensureColumn(
            'ue_users',
            'mfa_enabled_at',
            'ALTER TABLE ue_users ADD COLUMN mfa_enabled_at TIMESTAMP NULL DEFAULT NULL AFTER mfa_recovery_codes_json'
        );
        $schema->ensureColumn(
            'ue_users',
            'mfa_last_used_step',
            'ALTER TABLE ue_users ADD COLUMN mfa_last_used_step BIGINT NULL AFTER mfa_enabled_at'
        );
        $schema->ensureIndex(
            'ue_users',
            'idx_ue_users_mfa_enabled',
            'ALTER TABLE ue_users ADD KEY idx_ue_users_mfa_enabled (mfa_enabled_at)'
        );
    },
];
