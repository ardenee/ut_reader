<?php
/**
 * Compatibility guard for public-upload active identity reservation locking.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608280002',
    'description' => 'Ensure public upload active identity reservation key and unique index.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $schema->requireTable('ue_public_uploads');
        $schema->ensureColumn(
            'ue_public_uploads',
            'active_identity_key',
            'ALTER TABLE ue_public_uploads ADD COLUMN active_identity_key CHAR(64) NULL AFTER client_guid'
        );
        $schema->ensureIndex(
            'ue_public_uploads',
            'uq_ue_public_uploads_active_identity',
            'CREATE UNIQUE INDEX uq_ue_public_uploads_active_identity ON ue_public_uploads(active_identity_key)'
        );
    },
];
