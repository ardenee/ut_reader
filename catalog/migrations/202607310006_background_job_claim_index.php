<?php
declare(strict_types=1);

return [
    'version' => '202607310006',
    'description' => 'Prevent broad background-job claim range locks by matching the claim filters and ordering.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        $indexColumns = static function (string $name) use ($db): array {
            $statement = $db->prepare(
                'SELECT column_name FROM information_schema.statistics '
                . 'WHERE table_schema=DATABASE() AND table_name="ue_background_jobs" AND index_name=? '
                . 'ORDER BY seq_in_index'
            );
            $statement->execute([$name]);
            $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
            $statement->closeCursor();
            return array_map('strval', is_array($columns) ? $columns : []);
        };

        $wanted = ['queue_name', 'status', 'cancel_requested_at', 'priority', 'available_at', 'id'];
        $current = $indexColumns('idx_ue_background_jobs_claim');
        if ($current !== $wanted) {
            if ($current !== []) {
                $db->exec('ALTER TABLE ue_background_jobs DROP INDEX idx_ue_background_jobs_claim');
            }

            $db->exec(
                'ALTER TABLE ue_background_jobs ADD INDEX idx_ue_background_jobs_claim '
                . '(queue_name,status,cancel_requested_at,priority,available_at,id)'
            );
        }

        $analyze = $db->query('ANALYZE TABLE ue_background_jobs');
        if ($analyze !== false) {
            $analyze->fetchAll(PDO::FETCH_ASSOC);
            $analyze->closeCursor();
        }
    },
    'down' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        $statement = $db->prepare(
            'SELECT 1 FROM information_schema.statistics '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_background_jobs" '
            . 'AND index_name="idx_ue_background_jobs_claim" LIMIT 1'
        );
        $statement->execute();
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        if ($exists) {
            $db->exec('ALTER TABLE ue_background_jobs DROP INDEX idx_ue_background_jobs_claim');
        }

        $db->exec(
            'ALTER TABLE ue_background_jobs ADD INDEX idx_ue_background_jobs_claim '
            . '(queue_name,status,available_at,priority,id)'
        );

        $analyze = $db->query('ANALYZE TABLE ue_background_jobs');
        if ($analyze !== false) {
            $analyze->fetchAll(PDO::FETCH_ASSOC);
            $analyze->closeCursor();
        }
    },
];
