<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202607230001',
    'description' => 'Add parent-controlled federation base-game policy and peer inventory classification.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_federation_settings') || !$schema->tableExists('ue_federation_peer_files')) {
            throw new RuntimeException('Federation base-game policy migration requires federation settings and peer files tables.');
        }

        $db->prepare(
            'INSERT INTO ue_federation_settings(setting_name,setting_value) VALUES("ignore_base_game_files","1") '
            . 'ON DUPLICATE KEY UPDATE setting_value=setting_value'
        )->execute();

        $column = $db->query(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files" AND column_name="is_base_game"'
        )->fetchColumn();
        if ((int)$column === 0) {
            $db->exec(
                'ALTER TABLE ue_federation_peer_files '
                . 'ADD COLUMN is_base_game TINYINT(1) NOT NULL DEFAULT 0 AFTER package_guid'
            );
        }

        $index = $db->query(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_federation_peer_files" '
            . 'AND index_name="idx_ue_federation_peer_files_base_game"'
        )->fetchColumn();
        if ((int)$index === 0) {
            $db->exec(
                'ALTER TABLE ue_federation_peer_files '
                . 'ADD KEY idx_ue_federation_peer_files_base_game(peer_id,is_base_game)'
            );
        }

        if ($schema->tableExists('ue_base_game_files')) {
            $db->exec(
                'UPDATE ue_federation_peer_files pf '
                . 'JOIN ue_base_game_files bg ON bg.game_id=pf.game_id AND bg.package_guid=pf.package_guid '
                . 'SET pf.is_base_game=1'
            );
        }
    },
];
