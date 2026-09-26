<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609260001',
    'description' => 'Add game-scoped ClassRemap compatibility configuration.',
    'up' => static function (PDO $db, SchemaInspector $schema): void {
        $schema->ensureTable(
            'ue_class_remaps',
            'CREATE TABLE ue_class_remaps ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'mappings_text TEXT NOT NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_class_remaps_game (game_id),'
            . 'CONSTRAINT fk_ue_class_remaps_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },
];
