<?php
/**
 * Make compact-metadata publication state explicit for verified package rows.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608140001',
    'description' => 'Track verified compact-metadata publication state and failures explicitly.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->ensureColumn(
            'ue_files',
            'metadata_status',
            'ALTER TABLE ue_files ADD COLUMN metadata_status VARCHAR(16) NOT NULL DEFAULT "pending" AFTER scan_status'
        );
        $schema->ensureColumn(
            'ue_files',
            'metadata_error',
            'ALTER TABLE ue_files ADD COLUMN metadata_error TEXT NULL AFTER metadata_status'
        );
        $schema->ensureColumn(
            'ue_files',
            'metadata_updated_at',
            'ALTER TABLE ue_files ADD COLUMN metadata_updated_at DATETIME NULL AFTER metadata_error'
        );

        // Existing format-2 rows are already published. Existing verified rows
        // without a current registration are left pending so maintenance can
        // identify and repair them rather than silently treating them as healthy.
        $db->exec(
            'UPDATE ue_files f LEFT JOIN ue_file_metadata m '
            . 'ON m.file_id=f.id AND m.format_version=2 '
            . 'SET f.metadata_status=CASE '
            . 'WHEN f.scan_status="verified" AND m.file_id IS NOT NULL THEN "ready" '
            . 'WHEN f.scan_status="verified" THEN "pending" '
            . 'ELSE "ready" END,'
            . 'f.metadata_error=NULL,f.metadata_updated_at=UTC_TIMESTAMP()'
        );
    },
];
