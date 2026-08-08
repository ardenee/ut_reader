<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Makes the federation base-game policy schema migration-owned on upgraded installations.
 * Why: HTTP/API/worker runtime must verify schema only; adding peer-file columns/indexes and seeding settings belongs to migrations.
 * Role: Migration-only ownership for ignore_base_game_files and ue_federation_peer_files.is_base_game support.
 */
declare(strict_types=1);

return [
    'version' => '202608080003',
    'description' => 'Ensure federation base-game policy schema is migration-owned.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        $schema->requireTable('ue_federation_settings');
        $schema->requireTable('ue_federation_peer_files');

        $db->prepare(
            'INSERT INTO ue_federation_settings(setting_name,setting_value) '
            . 'VALUES("ignore_base_game_files","1") '
            . 'ON DUPLICATE KEY UPDATE setting_value=setting_value'
        )->execute();

        $schema->ensureColumn(
            'ue_federation_peer_files',
            'is_base_game',
            'ALTER TABLE ue_federation_peer_files '
                . 'ADD COLUMN is_base_game TINYINT(1) NOT NULL DEFAULT 0 AFTER package_guid'
        );
        $schema->ensureIndex(
            'ue_federation_peer_files',
            'idx_ue_federation_peer_files_base_game',
            'ALTER TABLE ue_federation_peer_files '
                . 'ADD KEY idx_ue_federation_peer_files_base_game(peer_id,is_base_game)'
        );

        if ($schema->tableExists('ue_base_game_files')) {
            $db->exec(
                'UPDATE ue_federation_peer_files pf '
                . 'JOIN ue_base_game_files bg '
                . 'ON bg.game_id=pf.game_id AND bg.package_guid=pf.package_guid '
                . 'SET pf.is_base_game=1 WHERE COALESCE(pf.is_base_game,0)=0'
            );
        }
    },
];
