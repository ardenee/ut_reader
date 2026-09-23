<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609230003',
    'description' => 'Cache catalog-wide package object coverage.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_package_coverage_cache')) {
            $db->exec('CREATE TABLE ue_package_coverage_cache (game_id INT UNSIGNED NOT NULL,package_name VARCHAR(255) NOT NULL,consumer_count INT UNSIGNED NOT NULL DEFAULT 0,required_object_count INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (game_id,package_name),CONSTRAINT fk_package_coverage_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        if (!$schema->tableExists('ue_package_provider_coverage_cache')) {
            $db->exec('CREATE TABLE ue_package_provider_coverage_cache (game_id INT UNSIGNED NOT NULL,package_name VARCHAR(255) NOT NULL,file_id BIGINT UNSIGNED NOT NULL,matched_count INT UNSIGNED NOT NULL DEFAULT 0,missing_count INT UNSIGNED NOT NULL DEFAULT 0,fully_satisfies TINYINT(1) NOT NULL DEFAULT 0,missing_paths_json MEDIUMTEXT NULL,updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (game_id,package_name,file_id),KEY idx_provider_coverage_file (file_id),CONSTRAINT fk_provider_coverage_game FOREIGN KEY (game_id) REFERENCES ue_games(id) ON DELETE CASCADE,CONSTRAINT fk_provider_coverage_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
    },
];
