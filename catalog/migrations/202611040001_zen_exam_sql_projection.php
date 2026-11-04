<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202611040001',
    'description' => 'Source-bound SQL examination projections for UE5 Zen import/export/name rows and cooked ScriptObjects',
    'up' => static function (PDO $db, SchemaInspector $inspector): void {
        $inspector->requireTable('ue_uedb5_files');
        $inspector->requireTable('ue_iostore_containers');
        $inspector->ensureTable('ue_uedb5_zen_exam_files', <<<'SQL'
CREATE TABLE ue_uedb5_zen_exam_files (
    file_id BIGINT UNSIGNED NOT NULL,
    source_sha256 BINARY(32) NOT NULL,
    name_count INT UNSIGNED NOT NULL,
    import_count INT UNSIGNED NOT NULL,
    export_count INT UNSIGNED NOT NULL,
    indexed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (file_id),
    CONSTRAINT fk_zen_exam_file FOREIGN KEY (file_id)
      REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $inspector->ensureTable('ue_uedb5_zen_exam_rows', <<<'SQL'
CREATE TABLE ue_uedb5_zen_exam_rows (
    file_id BIGINT UNSIGNED NOT NULL,
    section VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    row_index INT UNSIGNED NOT NULL,
    reference_index BINARY(8) NULL,
    row_json MEDIUMTEXT NOT NULL,
    search_text TEXT NOT NULL,
    PRIMARY KEY (file_id, section, row_index),
    KEY idx_zen_exam_reference (file_id, section, reference_index),
    CONSTRAINT fk_zen_exam_row_file FOREIGN KEY (file_id)
      REFERENCES ue_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $inspector->ensureTable('ue_iostore_zen_script_objects', <<<'SQL'
CREATE TABLE ue_iostore_zen_script_objects (
    container_id BIGINT UNSIGNED NOT NULL,
    object_index BINARY(8) NOT NULL,
    toc_sha256 BINARY(32) NOT NULL,
    object_name VARCHAR(512) NOT NULL,
    object_path VARCHAR(2048) NOT NULL,
    outer_index BINARY(8) NOT NULL,
    cdo_class_index BINARY(8) NOT NULL,
    PRIMARY KEY (container_id, object_index),
    CONSTRAINT fk_zen_script_container FOREIGN KEY (container_id)
      REFERENCES ue_iostore_containers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    },
];
