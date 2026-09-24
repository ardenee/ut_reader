<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609240001',
    'description' => 'Add indexed UE1/UE2 VerifyImport export identity projection.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_legacy_export_identity_lookup')) {
            $db->exec(
                'CREATE TABLE ue_legacy_export_identity_lookup ('
                . 'file_id BIGINT UNSIGNED NOT NULL,'
                . 'export_index INT UNSIGNED NOT NULL,'
                . 'identity_hash BINARY(16) NOT NULL,'
                . 'object_term_id INT UNSIGNED NOT NULL,'
                . 'class_package_term_id INT UNSIGNED NOT NULL,'
                . 'class_name_term_id INT UNSIGNED NOT NULL,'
                . 'outer_index INT NOT NULL,'
                . 'object_flags BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (file_id,export_index),'
                . 'KEY idx_legacy_verify_identity (file_id,identity_hash,export_index)'
                . ') ENGINE=InnoDB'
            );
        }
    },
];
