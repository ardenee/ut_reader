<?php
declare(strict_types=1);

return [
    'version' => '202607270004',
    'description' => 'Materialize one dependency summary row per requiring file and package.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_files');
        $schema->requireTable('ue_dependencies');

        $schema->ensureTable(
            'ue_dependency_package_summaries',
            'CREATE TABLE ue_dependency_package_summaries ('
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'required_package VARCHAR(255) NOT NULL,'
            . 'dependency_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'resolved_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'missing_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'package_only_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'common_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'summary_status VARCHAR(16) NOT NULL DEFAULT "mixed",'
            . 'provider_file_id BIGINT UNSIGNED NULL,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (file_id,required_package),'
            . 'KEY idx_ue_dep_summary_game_status (game_id,summary_status,required_package,file_id),'
            . 'KEY idx_ue_dep_summary_package_game (required_package,game_id,summary_status,file_id),'
            . 'KEY idx_ue_dep_summary_provider (provider_file_id,file_id),'
            . 'CONSTRAINT fk_ue_dep_summary_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_ue_dep_summary_provider FOREIGN KEY (provider_file_id) REFERENCES ue_files(id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach ([
            ['idx_ue_dep_summary_game_status', 'ALTER TABLE ue_dependency_package_summaries ADD KEY idx_ue_dep_summary_game_status (game_id,summary_status,required_package,file_id)'],
            ['idx_ue_dep_summary_package_game', 'ALTER TABLE ue_dependency_package_summaries ADD KEY idx_ue_dep_summary_package_game (required_package,game_id,summary_status,file_id)'],
            ['idx_ue_dep_summary_provider', 'ALTER TABLE ue_dependency_package_summaries ADD KEY idx_ue_dep_summary_provider (provider_file_id,file_id)'],
        ] as [$index, $sql]) {
            $schema->ensureIndex('ue_dependency_package_summaries', $index, $sql);
        }

        $db->exec('DELETE FROM ue_dependency_package_summaries');
        $db->exec(
            'INSERT INTO ue_dependency_package_summaries('
            . 'game_id,file_id,required_package,dependency_count,resolved_count,missing_count,'
            . 'package_only_count,common_count,summary_status,provider_file_id'
            . ') '
            . 'SELECT f.game_id,d.file_id,d.required_package,COUNT(*) dependency_count,'
            . 'SUM(d.status="resolved") resolved_count,SUM(d.status="missing") missing_count,'
            . 'SUM(d.status="package_only") package_only_count,SUM(d.status="common") common_count,'
            . 'CASE '
            . 'WHEN SUM(d.status="missing")>0 THEN "missing" '
            . 'WHEN SUM(d.status="common")=COUNT(*) THEN "common" '
            . 'WHEN SUM(d.status="resolved")=COUNT(*) THEN "resolved" '
            . 'WHEN SUM(d.status IN ("resolved","package_only"))=COUNT(*) THEN "package_only" '
            . 'ELSE "mixed" END summary_status,'
            . 'CASE WHEN COUNT(DISTINCT d.resolved_file_id)=1 THEN MAX(d.resolved_file_id) ELSE NULL END provider_file_id '
            . 'FROM ue_dependencies d JOIN ue_files f ON f.id=d.file_id '
            . 'WHERE f.scan_status="verified" AND d.required_package IS NOT NULL AND d.required_package<>"" '
            . 'GROUP BY f.game_id,d.file_id,d.required_package'
        );
    },
];
