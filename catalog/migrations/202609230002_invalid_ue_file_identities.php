<?php
/**
 * Durable fingerprints for Unreal package bytes that have been explicitly
 * confirmed invalid. The catalog identity row may remain for provenance, but
 * these bytes must never be accepted as a new upload or used as a dependency
 * provider.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609230002',
    'description' => 'Record durable invalid Unreal package identities.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        if ($schema->tableExists('ue_invalid_file_identities')) {
            return;
        }
        $db->exec(
            'CREATE TABLE ue_invalid_file_identities ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'file_size BIGINT UNSIGNED NOT NULL,'
            . 'md5 CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'sha1 CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'source_file_id BIGINT UNSIGNED NULL,'
            . 'reason VARCHAR(500) NOT NULL DEFAULT "",'
            . 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_invalid_file_identity (md5,sha1,file_size),'
            . 'KEY idx_invalid_source_file (source_file_id),'
            . 'CONSTRAINT fk_invalid_source_file FOREIGN KEY (source_file_id) REFERENCES ue_files(id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    },
];
