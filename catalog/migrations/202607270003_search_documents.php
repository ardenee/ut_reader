<?php
declare(strict_types=1);

return [
    'version' => '202607270003',
    'description' => 'Add compact game-scoped search documents for files, aliases, imports and exports.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_files');
        $schema->requireTable('ue_file_package_aliases');
        $schema->requireTable('ue_imports');
        $schema->requireTable('ue_exports');

        $schema->ensureTable(
            'ue_search_documents',
            'CREATE TABLE ue_search_documents ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'game_id INT UNSIGNED NOT NULL,'
            . 'file_id BIGINT UNSIGNED NOT NULL,'
            . 'document_type VARCHAR(16) NOT NULL,'
            . 'source_id BIGINT UNSIGNED NOT NULL,'
            . 'primary_value VARCHAR(1000) NOT NULL,'
            . 'secondary_value VARCHAR(1000) NOT NULL DEFAULT "",'
            . 'indexed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY uq_ue_search_document_source (file_id,document_type,source_id),'
            . 'KEY idx_ue_search_game_primary (game_id,primary_value(191),file_id),'
            . 'KEY idx_ue_search_game_secondary (game_id,secondary_value(191),file_id),'
            . 'KEY idx_ue_search_file (file_id),'
            . 'FULLTEXT KEY ft_ue_search_values (primary_value,secondary_value),'
            . 'CONSTRAINT fk_ue_search_file FOREIGN KEY (file_id) REFERENCES ue_files(id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $schema->ensureIndex(
            'ue_search_documents',
            'idx_ue_search_game_primary',
            'ALTER TABLE ue_search_documents ADD KEY idx_ue_search_game_primary (game_id,primary_value(191),file_id)'
        );
        $schema->ensureIndex(
            'ue_search_documents',
            'idx_ue_search_game_secondary',
            'ALTER TABLE ue_search_documents ADD KEY idx_ue_search_game_secondary (game_id,secondary_value(191),file_id)'
        );
        $schema->ensureIndex(
            'ue_search_documents',
            'idx_ue_search_file',
            'ALTER TABLE ue_search_documents ADD KEY idx_ue_search_file (file_id)'
        );
        $schema->ensureIndex(
            'ue_search_documents',
            'ft_ue_search_values',
            'ALTER TABLE ue_search_documents ADD FULLTEXT KEY ft_ue_search_values (primary_value,secondary_value)'
        );

        $upsert = ' ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),primary_value=VALUES(primary_value),'
            . 'secondary_value=VALUES(secondary_value),indexed_at=CURRENT_TIMESTAMP';

        $db->exec(
            'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
            . 'SELECT f.game_id,f.id,"file",f.id,f.package_name,f.original_name '
            . 'FROM ue_files f WHERE f.game_id IS NOT NULL AND f.scan_status="verified"' . $upsert
        );
        $db->exec(
            'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
            . 'SELECT a.game_id,a.file_id,"alias",a.id,a.package_name,a.original_name '
            . 'FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id AND f.scan_status="verified"' . $upsert
        );
        $db->exec(
            'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
            . 'SELECT f.game_id,i.file_id,"import",i.id,i.object_name,i.full_path '
            . 'FROM ue_imports i JOIN ue_files f ON f.id=i.file_id AND f.scan_status="verified"' . $upsert
        );
        $db->exec(
            'INSERT INTO ue_search_documents(game_id,file_id,document_type,source_id,primary_value,secondary_value) '
            . 'SELECT f.game_id,e.file_id,"export",e.id,e.object_name,e.full_path '
            . 'FROM ue_exports e JOIN ue_files f ON f.id=e.file_id AND f.scan_status="verified"' . $upsert
        );
    },
];
