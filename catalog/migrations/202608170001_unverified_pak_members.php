<?php
/**
 * Track neutral Upload Bucket PAK containers and their extracted package members.
 */
declare(strict_types=1);

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608170001',
    'description' => 'Track Upload Bucket PAK container membership and owned extracted package rows.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_unverified_pak_members',
            'CREATE TABLE ue_unverified_pak_members ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'parent_file_id BIGINT UNSIGNED NOT NULL,'
            . 'entry_index INT UNSIGNED NOT NULL,'
            . 'entry_path VARCHAR(1000) NOT NULL,'
            . 'entry_name VARCHAR(255) NOT NULL,'
            . 'extension VARCHAR(32) NOT NULL DEFAULT "",'
            . 'child_file_id BIGINT UNSIGNED NULL,'
            . 'owns_child_file TINYINT(1) NOT NULL DEFAULT 0,'
            . 'status VARCHAR(24) NOT NULL DEFAULT "pending",'
            . 'message VARCHAR(1000) NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_unverified_pak_member (parent_file_id,entry_index),'
            . 'KEY idx_ue_unverified_pak_parent_status (parent_file_id,status),'
            . 'KEY idx_ue_unverified_pak_child (child_file_id),'
            . 'CONSTRAINT fk_ue_unverified_pak_parent FOREIGN KEY (parent_file_id) REFERENCES ue_files(id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_ue_unverified_pak_child FOREIGN KEY (child_file_id) REFERENCES ue_files(id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
