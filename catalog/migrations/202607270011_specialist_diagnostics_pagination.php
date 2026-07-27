<?php
declare(strict_types=1);

return [
    'version' => '202607270011',
    'description' => 'Add stable cursor indexes for Background Jobs and federation identity conflicts.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_background_jobs');
        $schema->requireTable('ue_federation_peer_files');

        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_queue_id',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_queue_id (queue_name,id)'
        );
        $schema->ensureIndex(
            'ue_background_jobs',
            'idx_ue_background_jobs_queue_status_id',
            'ALTER TABLE ue_background_jobs ADD KEY idx_ue_background_jobs_queue_status_id (queue_name,status,id)'
        );
        $schema->ensureIndex(
            'ue_federation_peer_files',
            'idx_ue_peer_files_conflict_cursor',
            'ALTER TABLE ue_federation_peer_files ADD KEY idx_ue_peer_files_conflict_cursor '
                . '(peer_id,is_base_game,package_name(120),original_name(120),id)'
        );
    },
];
