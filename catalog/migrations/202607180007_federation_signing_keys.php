<?php
declare(strict_types=1);

return [
    'version' => '202607180007',
    'description' => 'Add Ed25519 federation signing keys and revocation metadata.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_federation_peers');
        $schema->ensureColumn(
            'ue_federation_peers',
            'signature_algorithm',
            'ALTER TABLE ue_federation_peers ADD COLUMN signature_algorithm VARCHAR(32) NOT NULL DEFAULT "hmac-sha256" AFTER shared_secret_plain'
        );
        $schema->ensureColumn(
            'ue_federation_peers',
            'signing_public_key',
            'ALTER TABLE ue_federation_peers ADD COLUMN signing_public_key VARCHAR(128) NULL AFTER signature_algorithm'
        );
        $schema->ensureColumn(
            'ue_federation_peers',
            'signing_key_id',
            'ALTER TABLE ue_federation_peers ADD COLUMN signing_key_id VARCHAR(64) NULL AFTER signing_public_key'
        );
        $schema->ensureColumn(
            'ue_federation_peers',
            'signing_rotated_at',
            'ALTER TABLE ue_federation_peers ADD COLUMN signing_rotated_at TIMESTAMP NULL DEFAULT NULL AFTER signing_key_id'
        );
        $schema->ensureColumn(
            'ue_federation_peers',
            'signing_revoked_at',
            'ALTER TABLE ue_federation_peers ADD COLUMN signing_revoked_at TIMESTAMP NULL DEFAULT NULL AFTER signing_rotated_at'
        );
        $schema->ensureIndex(
            'ue_federation_peers',
            'idx_ue_federation_peers_signing_key',
            'ALTER TABLE ue_federation_peers ADD KEY idx_ue_federation_peers_signing_key (signing_key_id, signing_revoked_at)'
        );
        $db->exec('UPDATE ue_federation_peers SET signature_algorithm="hmac-sha256" WHERE signature_algorithm IS NULL OR signature_algorithm=""');
    },
];
