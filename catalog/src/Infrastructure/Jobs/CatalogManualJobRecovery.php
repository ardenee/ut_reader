<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Performs explicit administrator recovery for orphaned detached jobs plus expired leases.
 * Why: Manual recovery deliberately does not consume another attempt, unlike automatic crash recovery, and must not live as SQL inside an HTTP endpoint.
 * Role: Infrastructure orchestration preserving the established Background Jobs recover action semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogManualJobRecovery
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array{queue:string,orphaned_requeued:int,orphaned_cancelled:int,requeued:int,cancelled:int,dead_lettered:int} */
    public function recover(string $queueName): array
    {
        $launcher = new CatalogDetachedWorker($this->config);
        $worker = $launcher->status($queueName, false);
        $orphanedRequeued = 0;
        $orphanedCancelled = 0;

        if (empty($worker['active'])) {
            $now = gmdate('Y-m-d H:i:s');

            // Explicit administrator recovery is intentionally a free retry.
            // Automatic crash recovery counts the failed attempt; this path
            // decrements it because an administrator has chosen to recover a row
            // whose detached process is already proven absent.
            $cancel = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,completed_at=?,updated_at=? '
                . 'WHERE queue_name=? AND status="running" AND worker_id LIKE "detached:%" '
                . 'AND cancel_requested_at IS NOT NULL'
            );
            $cancel->execute([$now, $now, $queueName]);
            $orphanedCancelled = $cancel->rowCount();

            $requeue = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",attempts=GREATEST(attempts-1,0),available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'last_error="Detached worker process disappeared; orphaned job resumed without consuming an attempt.",'
                . 'updated_at=? WHERE queue_name=? AND status="running" AND worker_id LIKE "detached:%" '
                . 'AND cancel_requested_at IS NULL'
            );
            // Preserve progress_json/progress_updated_at. An orphaned process is
            // precisely the situation where the last durable checkpoint matters.
            $requeue->execute([$now, $now, $queueName]);
            $orphanedRequeued = $requeue->rowCount();

            if ($orphanedRequeued > 0 || $orphanedCancelled > 0) {
                $launcher->writeState($queueName, [
                    'status' => 'stopped',
                    'queue' => $queueName,
                    'ended_at' => gmdate('c'),
                    'exit_reason' => 'orphan_recovery',
                    'orphaned_requeued' => $orphanedRequeued,
                    'orphaned_cancelled' => $orphanedCancelled,
                ]);
                $launcher->clearStopRequest($queueName);
            }
        }

        $expired = (new PdoJobQueue($this->db))->recoverExpiredLeases($queueName);
        return [
            'queue' => $queueName,
            'orphaned_requeued' => $orphanedRequeued,
            'orphaned_cancelled' => $orphanedCancelled,
            'requeued' => $orphanedRequeued + (int)($expired['requeued'] ?? 0),
            'cancelled' => $orphanedCancelled + (int)($expired['cancelled'] ?? 0),
            'dead_lettered' => (int)($expired['dead_lettered'] ?? 0),
        ];
    }
}
