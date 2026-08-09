<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists federation transfer/audit events.
 * Why: Federation audit persistence is infrastructure behavior and should not live in the legacy auth facade.
 * Role: Focused federation logging service preserving the historical ue_federation_transfer_logs contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationLogService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function write(
        ?int $peerId,
        ?int $jobId,
        string $level,
        string $event,
        string $details = ''
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO ue_federation_transfer_logs(peer_id, transfer_job_id, level, event, details) VALUES(?,?,?,?,?)'
        );
        $statement->execute([$peerId, $jobId, $level, $event, $details]);
    }
}
