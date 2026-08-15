<?php
/**
 * Bounded operational read model for the single-host admin console.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoSystemOperationsQuery
{
    public function __construct(private readonly PDO $db)
    {
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
     * Operational queue pressure deliberately excludes completed/cancelled
     * history so opening diagnostics does not scan the entire retained archive.
     *
     * @return list<array{queue:string,total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int,oldest_queued_seconds:int,longest_running_seconds:int,concurrency_blocked:int}>
     */
    public function queues(): array
    {
        $rows = $this->db->query(
            'SELECT queue_name,'
            . 'COUNT(*) total,'
            . 'SUM(status="queued") queued_total,'
            . 'SUM(status="running") running_total,'
            . 'SUM(status="failed") failed_total,'
            . 'SUM(status="dead_letter") dead_letter_total,'
            . 'COALESCE(MAX(CASE WHEN status="queued" THEN TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP()) ELSE 0 END),0) oldest_queued_seconds,'
            . 'COALESCE(MAX(CASE WHEN status="running" THEN TIMESTAMPDIFF(SECOND,COALESCE(leased_at,updated_at,created_at),UTC_TIMESTAMP()) ELSE 0 END),0) longest_running_seconds '
            . 'FROM ue_background_jobs '
            . 'WHERE status IN ("queued","running","failed","dead_letter") '
            . 'GROUP BY queue_name ORDER BY queue_name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $blocked = [];
        $statement = $this->db->query(
            'SELECT q.queue_name,COUNT(*) blocked '
            . 'FROM ue_background_jobs q '
            . 'JOIN (SELECT queue_name,concurrency_key FROM ue_background_jobs '
            . 'WHERE status="running" AND concurrency_key IS NOT NULL AND concurrency_key<>"" '
            . 'GROUP BY queue_name,concurrency_key) r '
            . 'ON r.queue_name=q.queue_name AND r.concurrency_key=q.concurrency_key '
            . 'WHERE q.status="queued" GROUP BY q.queue_name'
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $blocked[(string)$row['queue_name']] = max(0, (int)$row['blocked']);
        }

        $result = [];
        foreach ($rows as $row) {
            $queue = (string)($row['queue_name'] ?? '');
            $result[] = [
                'queue' => $queue,
                'total' => max(0, (int)($row['total'] ?? 0)),
                'queued' => max(0, (int)($row['queued_total'] ?? 0)),
                'running' => max(0, (int)($row['running_total'] ?? 0)),
                'completed' => 0,
                'failed' => max(0, (int)($row['failed_total'] ?? 0)),
                'dead_letter' => max(0, (int)($row['dead_letter_total'] ?? 0)),
                'cancelled' => 0,
                'oldest_queued_seconds' => max(0, (int)($row['oldest_queued_seconds'] ?? 0)),
                'longest_running_seconds' => max(0, (int)($row['longest_running_seconds'] ?? 0)),
                'concurrency_blocked' => $blocked[$queue] ?? 0,
            ];
        }
        return $result;
    }
}
