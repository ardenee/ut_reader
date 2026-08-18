<?php
/**
 * Removes queued legacy whole-game dependency children created by PAK imports.
 *
 * PAK workflow v3 replaces the old nested REBUILD_GAME_DEPENDENCIES child with
 * exact targeted file units. On worker restart, stale running rows are first
 * requeued by the stale-code restart path; this synchronizer can then cancel the
 * obsolete coordinator and any queued dependency-file descendants before claims.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogPakDependencyPolicySynchronizer
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $queueName
    ) {
    }

    /** @return array{coordinators:int,file_units:int} */
    public function retireQueuedLegacyChildren(): array
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        $fileUnits = $this->db->prepare(
            'UPDATE ue_background_jobs unit '
            . 'JOIN ue_background_jobs legacy ON legacy.id=unit.parent_job_id '
            . 'JOIN ue_background_jobs pak ON pak.id=legacy.parent_job_id '
            . 'SET unit.status="cancelled",unit.dedupe_key=NULL,unit.worker_id=NULL,unit.lease_token=NULL,'
            . 'unit.leased_at=NULL,unit.lease_expires_at=NULL,unit.last_heartbeat_at=NULL,'
            . 'unit.cancel_requested_at=COALESCE(unit.cancel_requested_at,?),' 
            . 'unit.cancel_reason=CASE WHEN unit.cancel_reason IS NULL OR unit.cancel_reason="" '
            . 'THEN "Obsolete PAK whole-game dependency unit replaced by targeted PAK dependency refresh." '
            . 'ELSE unit.cancel_reason END,unit.completed_at=?,unit.updated_at=? '
            . 'WHERE unit.queue_name=? AND unit.status="queued" '
            . 'AND unit.job_type=? AND unit.workflow_unit_key LIKE "dependency:%" '
            . 'AND legacy.job_type=? AND legacy.workflow_unit_key="dependencies" '
            . 'AND pak.job_type=? AND pak.status IN ("queued","running")'
        );
        $fileUnits->execute([
            $timestamp,
            $timestamp,
            $timestamp,
            $this->queueName,
            JobType::REBUILD_FILE_DEPENDENCIES,
            JobType::REBUILD_GAME_DEPENDENCIES,
            JobType::IMPORT_STAGED_PAK,
        ]);

        $coordinators = $this->db->prepare(
            'UPDATE ue_background_jobs legacy '
            . 'JOIN ue_background_jobs pak ON pak.id=legacy.parent_job_id '
            . 'SET legacy.status="cancelled",legacy.dedupe_key=NULL,legacy.worker_id=NULL,legacy.lease_token=NULL,'
            . 'legacy.leased_at=NULL,legacy.lease_expires_at=NULL,legacy.last_heartbeat_at=NULL,'
            . 'legacy.cancel_requested_at=COALESCE(legacy.cancel_requested_at,?),' 
            . 'legacy.cancel_reason=CASE WHEN legacy.cancel_reason IS NULL OR legacy.cancel_reason="" '
            . 'THEN "Obsolete PAK whole-game dependency workflow replaced by targeted PAK dependency refresh." '
            . 'ELSE legacy.cancel_reason END,legacy.completed_at=?,legacy.updated_at=? '
            . 'WHERE legacy.queue_name=? AND legacy.status="queued" '
            . 'AND legacy.job_type=? AND legacy.workflow_unit_key="dependencies" '
            . 'AND pak.job_type=? AND pak.status IN ("queued","running")'
        );
        $coordinators->execute([
            $timestamp,
            $timestamp,
            $timestamp,
            $this->queueName,
            JobType::REBUILD_GAME_DEPENDENCIES,
            JobType::IMPORT_STAGED_PAK,
        ]);

        return [
            'coordinators' => max(0, $coordinators->rowCount()),
            'file_units' => max(0, $fileUnits->rowCount()),
        ];
    }
}
