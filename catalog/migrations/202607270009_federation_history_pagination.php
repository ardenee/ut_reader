<?php
declare(strict_types=1);

return [
    'version' => '202607270009',
    'description' => 'Add stable cursor indexes for federation requests, request items, transfers and logs.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        foreach (['ue_federation_requests', 'ue_federation_request_items', 'ue_federation_transfer_jobs', 'ue_federation_transfer_logs'] as $table) {
            $schema->requireTable($table);
        }

        $schema->ensureIndex(
            'ue_federation_requests',
            'idx_ue_federation_requests_history',
            'ALTER TABLE ue_federation_requests ADD KEY idx_ue_federation_requests_history(direction,status,created_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_requests',
            'idx_ue_federation_requests_peer_history',
            'ALTER TABLE ue_federation_requests ADD KEY idx_ue_federation_requests_peer_history(peer_id,direction,status,created_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_request_items',
            'idx_ue_federation_request_items_history',
            'ALTER TABLE ue_federation_request_items ADD KEY idx_ue_federation_request_items_history(request_id,updated_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_transfer_jobs',
            'idx_ue_federation_transfer_history',
            'ALTER TABLE ue_federation_transfer_jobs ADD KEY idx_ue_federation_transfer_history(status,created_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_transfer_jobs',
            'idx_ue_federation_transfer_peer_history',
            'ALTER TABLE ue_federation_transfer_jobs ADD KEY idx_ue_federation_transfer_peer_history(peer_id,direction,created_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_transfer_logs',
            'idx_ue_federation_logs_history',
            'ALTER TABLE ue_federation_transfer_logs ADD KEY idx_ue_federation_logs_history(created_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_transfer_logs',
            'idx_ue_federation_logs_level_history',
            'ALTER TABLE ue_federation_transfer_logs ADD KEY idx_ue_federation_logs_level_history(level,created_at,id)'
        );
        $schema->ensureIndex(
            'ue_federation_transfer_logs',
            'idx_ue_federation_logs_peer_history',
            'ALTER TABLE ue_federation_transfer_logs ADD KEY idx_ue_federation_logs_peer_history(peer_id,created_at,id)'
        );
    },
];
