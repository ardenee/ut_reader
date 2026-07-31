<?php
declare(strict_types=1);

return [
    'version' => '202607310006',
    'description' => 'Prevent broad background-job claim range locks by matching the claim filters and ordering.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        if (!$schema->tableExists('ue_background_jobs')) {
            return;
        }

        $indexExists = static function (string $name) use ($db): bool {
            $statement = $db->prepare(
                'SELECT 1 FROM information_schema.statistics '
                . 'WHERE table_schema=DATABASE() AND table_name="ue_background_jobs" AND index_name=? LIMIT 1'
            );
            $statement->execute([$name]);
            return $statement->fetchColumn() !== false;
        };

        if ($indexExists('idx_ue_background_jobs_claim')) {
            $db->exec('ALTER TABLE ue_background_jobs DROP INDEX idx_ue_background_jobs_claim');
        }

        $db->exec(
            'ALTER TABLE ue_background_jobs ADD INDEX idx_ue_background_jobs_claim '
            . '(queue_name,status,cancel_requested_at,priority,available_at,id)'
        );
        $db->exec('ANALYZE TABLE ue_background_jobs');
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
        if ($statement->fetchColumn() !== false) {
            $db->exec('ALTER TABLE ue_background_jobs DROP INDEX idx_ue_background_jobs_claim');
        }

        $db->exec(
            'ALTER TABLE ue_background_jobs ADD INDEX idx_ue_background_jobs_claim '
            . '(queue_name,status,available_at,priority,id)'
        );
        $db->exec('ANALYZE TABLE ue_background_jobs');
    },
];
