<?php
/**
 * Remove runtime string normalization and close the remaining targeted dependency
 * discovery/index gaps in the high-volume dependency refresh path.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202608190001',
    'description' => 'Add indexed dependency package identities and targeted dependency refresh indexes.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $packageKeyExpression = 'LOWER(TRIM(COALESCE(package_name,"")))';
        $originalStemExpression = 'LOWER(TRIM(CASE WHEN LOCATE(".",COALESCE(original_name,""))>0 '
            . 'THEN LEFT(original_name,CHAR_LENGTH(original_name)-CHAR_LENGTH(SUBSTRING_INDEX(original_name,".",-1))-1) '
            . 'ELSE COALESCE(original_name,"") END))';

        $schema->ensureColumn(
            'ue_files',
            'dependency_package_key',
            'ALTER TABLE ue_files ADD COLUMN dependency_package_key VARCHAR(255) '
                . 'GENERATED ALWAYS AS (' . $packageKeyExpression . ') STORED'
        );
        $schema->ensureColumn(
            'ue_files',
            'dependency_original_stem_key',
            'ALTER TABLE ue_files ADD COLUMN dependency_original_stem_key VARCHAR(255) '
                . 'GENERATED ALWAYS AS (' . $originalStemExpression . ') STORED'
        );
        $schema->ensureIndex(
            'ue_files',
            'idx_ue_files_game_dependency_package_key',
            'CREATE INDEX idx_ue_files_game_dependency_package_key '
                . 'ON ue_files(game_id,dependency_package_key,id)'
        );
        $schema->ensureIndex(
            'ue_files',
            'idx_ue_files_game_dependency_stem_key',
            'CREATE INDEX idx_ue_files_game_dependency_stem_key '
                . 'ON ue_files(game_id,dependency_original_stem_key,id)'
        );

        $schema->ensureColumn(
            'ue_base_game_files',
            'dependency_package_key',
            'ALTER TABLE ue_base_game_files ADD COLUMN dependency_package_key VARCHAR(255) '
                . 'GENERATED ALWAYS AS (' . $packageKeyExpression . ') STORED'
        );
        $schema->ensureColumn(
            'ue_base_game_files',
            'dependency_original_stem_key',
            'ALTER TABLE ue_base_game_files ADD COLUMN dependency_original_stem_key VARCHAR(255) '
                . 'GENERATED ALWAYS AS (' . $originalStemExpression . ') STORED'
        );
        $schema->ensureIndex(
            'ue_base_game_files',
            'idx_ue_base_game_dependency_package_key',
            'CREATE INDEX idx_ue_base_game_dependency_package_key '
                . 'ON ue_base_game_files(game_id,dependency_package_key,id)'
        );
        $schema->ensureIndex(
            'ue_base_game_files',
            'idx_ue_base_game_dependency_stem_key',
            'CREATE INDEX idx_ue_base_game_dependency_stem_key '
                . 'ON ue_base_game_files(game_id,dependency_original_stem_key,id)'
        );

        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_required_file',
            'CREATE INDEX idx_ue_dependency_required_file '
                . 'ON ue_dependency_links(required_package_term_id,file_id)'
        );
        $schema->ensureIndex(
            'ue_dependency_links',
            'idx_ue_dependency_resolved_file',
            'CREATE INDEX idx_ue_dependency_resolved_file '
                . 'ON ue_dependency_links(resolved_file_id,file_id)'
        );
        $schema->ensureIndex(
            'ue_dependency_package_summaries',
            'idx_ue_dep_summary_game_missing_package',
            'CREATE INDEX idx_ue_dep_summary_game_missing_package '
                . 'ON ue_dependency_package_summaries(game_id,missing_count,required_package(191),file_id)'
        );
    },
];
