<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Restarts an explicit bounded set of cancelled, failed or dead-letter jobs in one queue.
 * Why: The compatibility job-retry endpoint has narrower semantics than Background Jobs bulk restart and must not own queue SQL.
 * Role: Infrastructure persistence action preserving the established exact retry contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobRetryAction
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param list<int> $jobIds */
    public function restart(string $queueName, array $jobIds): int
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $ids = [];
        foreach ($jobIds as $jobId) {
            $jobId = (int)$jobId;
            if ($jobId > 0) {
                $ids[$jobId] = $jobId;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return 0;
        }
        if (count($ids) > 1000) {
            throw new \InvalidArgumentException('Restart no more than 1,000 jobs at a time.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,'
            . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
            . 'last_error=NULL,result_json=NULL,cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
            . 'progress_json=NULL,progress_updated_at=NULL,dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE queue_name=? AND id IN (' . $placeholders . ') '
            . 'AND status IN ("cancelled","failed","dead_letter")'
        );
        $statement->execute(array_merge([$now, $now, $queueName], $ids));
        return $statement->rowCount();
    }
}
