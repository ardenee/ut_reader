<?php
/**
 * Bounded operational read model for the single-host admin console.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoSystemOperationsQuery
{
    private readonly PdoBackgroundJobOperatorSnapshotQuery $jobs;

    public function __construct(private readonly PDO $db)
    {
        $this->jobs = new PdoBackgroundJobOperatorSnapshotQuery($db);
    }

    /** @return array{database:string,version:string,size_bytes:int,files:int,verified_files:int} */
    public function database(): array
    {
        $identity = $this->db->query('SELECT DATABASE() database_name, VERSION() version')->fetch(PDO::FETCH_ASSOC) ?: [];
        $database = (string)($identity['database_name'] ?? '');

        $sizeStatement = $this->db->prepare(
            'SELECT COALESCE(SUM(data_length + index_length),0) bytes '
            . 'FROM information_schema.tables WHERE table_schema=?'
        );
        $sizeStatement->execute([$database]);
        $size = (int)($sizeStatement->fetchColumn() ?: 0);

        $files = $this->db->query(
            'SELECT COUNT(*) files,SUM(scan_status="verified") verified_files FROM ue_files'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'database' => $database,
            'version' => (string)($identity['version'] ?? ''),
            'size_bytes' => max(0, $size),
            'files' => max(0, (int)($files['files'] ?? 0)),
            'verified_files' => max(0, (int)($files['verified_files'] ?? 0)),
        ];
    }

    /**
     * Operator-facing queue pressure uses the same rolled-up job scope as the
     * Background Jobs browser. Routine workflow children therefore cannot inflate
     * queued/running headline numbers. Durable execution-row counts remain in
     * PdoBackgroundJobOperationalQuery for worker-health/admission decisions.
     *
     * Completed/cancelled history is deliberately excluded so opening production
     * diagnostics never scans the retained terminal archive.
     *
     * @return list<array{queue:string,total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int,oldest_queued_seconds:int,longest_running_seconds:int,concurrency_blocked:int}>
     */
    public function queues(): array
    {
        $statement = $this->db->query(
            'SELECT DISTINCT queue_name FROM ue_background_jobs '
            . 'WHERE status IN ("queued","running","failed","dead_letter") '
            . 'ORDER BY queue_name'
        );
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $queueName) {
            $queueName = trim((string)$queueName);
            if ($queueName === '') {
                continue;
            }
            $result[] = $this->jobs->current($queueName);
        }
        return $result;
    }
}
