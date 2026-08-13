<?php
/**
 * General administrator program settings used by upload ingress policy.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608130001',
    'description' => 'Add administrator-configurable program upload size limits.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_program_settings',
            'CREATE TABLE ue_program_settings ('
            . 'setting_key VARCHAR(80) NOT NULL,'
            . 'setting_value VARCHAR(255) NOT NULL,'
            . 'updated_by BIGINT UNSIGNED NULL,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (setting_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
