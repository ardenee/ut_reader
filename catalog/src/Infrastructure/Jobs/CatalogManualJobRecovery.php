<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Performs explicit administrator recovery for jobs whose detached worker is proven absent.
 * Why: Recovery must follow worker liveness, never elapsed time, and must not live as SQL inside an HTTP endpoint.
 * Role: Infrastructure orchestration preserving the established Background Jobs recover action semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;

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
        $recovery = (new CatalogOrphanedJobRecovery($this->db, $this->config))
            ->recoverInactiveQueue($queueName);

        return [
            'queue' => $queueName,
            'orphaned_requeued' => (int)($recovery['requeued'] ?? 0),
            'orphaned_cancelled' => (int)($recovery['cancelled'] ?? 0),
            'requeued' => (int)($recovery['requeued'] ?? 0),
            'cancelled' => (int)($recovery['cancelled'] ?? 0),
            'dead_lettered' => (int)($recovery['dead_lettered'] ?? 0),
        ];
    }
}
