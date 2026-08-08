<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides small indexed operational reads for durable background jobs.
 * Why: Worker/status HTTP endpoints should not own queue SQL or duplicate count semantics.
 * Role: Infrastructure query object for live worker/queue diagnostics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobOperationalQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{queued:int,ready:int,running:int,terminal:int,total:int} */
    public function queueCounts(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $counts = ['queued' => 0, 'ready' => 0, 'running' => 0, 'terminal' => 0, 'total' => 0];

        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) AS total FROM ue_background_jobs WHERE queue_name=? GROUP BY status'
        );
        $statement->execute([$queueName]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $count = (int)($row['total'] ?? 0);
            $counts['total'] += $count;
            if ($status === 'queued') {
                $counts['queued'] += $count;
            } elseif ($status === 'running') {
                $counts['running'] += $count;
            } elseif (in_array($status, ['completed', 'failed', 'dead_letter', 'cancelled'], true)) {
                $counts['terminal'] += $count;
            }
        }

        if ($counts['queued'] > 0) {
            $ready = $this->db->prepare(
                'SELECT COUNT(*) FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL AND available_at<=UTC_TIMESTAMP()'
            );
            $ready->execute([$queueName]);
            $counts['ready'] = (int)$ready->fetchColumn();
        }

        return $counts;
    }
}
