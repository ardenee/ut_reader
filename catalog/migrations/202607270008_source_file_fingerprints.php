<?php
declare(strict_types=1);

return [
    'version' => '202607270008',
    'description' => 'Cache local source file fingerprints and verified catalogue matches.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_sources');
        $schema->requireTable('ue_files');

        $schema->ensureTable(
            'ue_source_file_fingerprints',
            'CREATE TABLE ue_source_file_fingerprints ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'source_id INT UNSIGNED NOT NULL,'
            . 'source_relative_path VARCHAR(1000) NOT NULL,'
            . 'path_hash CHAR(64) NOT NULL,'
            . 'file_size BIGINT UNSIGNED NOT NULL,'
            . 'modified_at BIGINT NOT NULL DEFAULT 0,'
            . 'quick_fingerprint CHAR(64) NOT NULL,'
            . 'work_name VARCHAR(255) NOT NULL,'
            . 'is_redirect TINYINT(1) NOT NULL DEFAULT 0,'
            . 'content_md5 CHAR(32) NULL,'
            . 'content_sha1 CHAR(40) NULL,'
            . 'package_guid VARCHAR(80) NULL,'
            . 'matched_file_id BIGINT UNSIGNED NULL,'
            . 'match_method VARCHAR(16) NULL,'
            . 'last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'verified_at TIMESTAMP NULL DEFAULT NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_source_fingerprint_path (source_id,path_hash),'
            . 'KEY idx_ue_source_fingerprint_match (matched_file_id),'
            . 'KEY idx_ue_source_fingerprint_seen (source_id,last_seen_at),'
            . 'CONSTRAINT fk_ue_source_fingerprint_source FOREIGN KEY (source_id) REFERENCES ue_sources(id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_ue_source_fingerprint_file FOREIGN KEY (matched_file_id) REFERENCES ue_files(id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
