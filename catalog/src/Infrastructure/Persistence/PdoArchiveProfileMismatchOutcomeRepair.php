<?php
/**
 * Compatibility repair for historical archive children that were valid Unreal
 * packages but were marked as generic "unverified" because they did not match
 * the selected game profile.
 *
 * The repair changes only durable job outcome metadata. It then requeues the
 * affected archive/content-routing coordinators in their wait stage so existing
 * child rows are re-aggregated without re-reading or re-extracting source bytes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportOutcome;

final class PdoArchiveProfileMismatchOutcomeRepair
{
    private const BATCH_LIMIT = 1000;
    private const MAX_BATCHES = 20;
    private const MAX_ANCESTOR_DEPTH = 24;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{reclassified:int,requeued:int} */
    public function repair(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $parentIds = [];
        $reclassified = 0;

        $select = $this->db->prepare(
            'SELECT id,parent_job_id FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="completed" AND job_type=? AND display_status="unverified" '
            . 'AND JSON_VALID(result_json) '
            . 'AND LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.status")),"")))="unverified" '
            . 'AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.message")),"") LIKE "Game/profile mismatch.%" '
            . 'ORDER BY id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $updateChild = $this->db->prepare(
            'UPDATE ue_background_jobs SET '
            . 'result_json=JSON_SET(result_json,"$.status",?,"$.outcome_class","profile_mismatch"),'
            . 'progress_json=CASE WHEN JSON_VALID(progress_json) '
            . 'THEN JSON_SET(progress_json,"$.status",?,"$.outcome_class","profile_mismatch") ELSE progress_json END,'
            . 'last_error=NULL,updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status="completed" AND display_status="unverified"'
        );

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $select->execute([$queueName, JobType::IMPORT_STAGED_PACKAGE]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                break;
            }

            $now = gmdate('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $id = max(0, (int)($row['id'] ?? 0));
                if ($id < 1) {
                    continue;
                }
                $updateChild->execute([
                    CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH,
                    CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH,
                    $now,
                    $id,
                    $queueName,
                ]);
                if ($updateChild->rowCount() < 1) {
                    continue;
                }
                $reclassified++;
                $parentId = max(0, (int)($row['parent_job_id'] ?? 0));
                if ($parentId > 0) {
                    $parentIds[$parentId] = true;
                }
            }

            if (count($rows) < self::BATCH_LIMIT) {
                break;
            }
        }

        if ($parentIds === []) {
            return ['reclassified' => $reclassified, 'requeued' => 0];
        }

        $ancestors = $this->affectedAncestors($queueName, array_keys($parentIds));
        if ($ancestors === []) {
            return ['reclassified' => $reclassified, 'requeued' => 0];
        }

        $requeued = 0;
        // Every affected ancestor is changed to queued before this method returns.
        // Therefore an outer parent cannot race ahead of a nested coordinator and
        // finalize against its stale historical child outcome.
        foreach ($ancestors as $row) {
            if ($this->requeueCoordinator($queueName, $row)) {
                $requeued++;
            }
        }

        return ['reclassified' => $reclassified, 'requeued' => $requeued];
    }

    /**
     * @param list<int> $initialIds
     * @return list<array<string,mixed>>
     */
    private function affectedAncestors(string $queueName, array $initialIds): array
    {
        $frontier = array_values(array_unique(array_filter(array_map('intval', $initialIds), static fn(int $id): bool => $id > 0)));
        $seen = [];
        $rowsById = [];

        for ($depth = 0; $frontier !== [] && $depth < self::MAX_ANCESTOR_DEPTH; $depth++) {
            $frontier = array_values(array_filter(
                $frontier,
                static fn(int $id): bool => !isset($seen[$id])
            ));
            if ($frontier === []) {
                break;
            }
            foreach ($frontier as $id) {
                $seen[$id] = true;
            }

            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $statement = $this->db->prepare(
                'SELECT id,parent_job_id,job_type,status,result_json,progress_json '
                . 'FROM ue_background_jobs WHERE queue_name=? AND id IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$queueName], $frontier));
            $next = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = max(0, (int)($row['id'] ?? 0));
                if ($id < 1) {
                    continue;
                }
                $jobType = (string)($row['job_type'] ?? '');
                if ($this->isCoordinator($jobType, $row)) {
                    $rowsById[$id] = $row;
                }
                $parentId = max(0, (int)($row['parent_job_id'] ?? 0));
                if ($parentId > 0 && !isset($seen[$parentId])) {
                    $next[] = $parentId;
                }
            }
            $frontier = $next;
        }

        // Descendants first is useful for diagnostics; all rows are persisted as
        // queued before any worker can claim them because this repair runs during
        // worker construction.
        krsort($rowsById, SORT_NUMERIC);
        return array_values($rowsById);
    }

    /** @param array<string,mixed> $row */
    private function isCoordinator(string $jobType, array $row): bool
    {
        if (in_array($jobType, [JobType::IMPORT_STAGED_ARCHIVE, JobType::PROCESS_BUCKET_ARCHIVE], true)) {
            return (string)($row['status'] ?? '') === 'completed';
        }

        if (!in_array($jobType, [JobType::IMPORT_STAGED_PACKAGE, JobType::PROCESS_BUCKET_STAGED_PACKAGE], true)
            || (string)($row['status'] ?? '') !== 'completed') {
            return false;
        }

        $result = $this->jsonObject((string)($row['result_json'] ?? ''));
        return (string)($result['operation'] ?? '') === 'archive_member_content_route'
            && (string)($result['status'] ?? '') === 'partial';
    }

    /** @param array<string,mixed> $row */
    private function requeueCoordinator(string $queueName, array $row): bool
    {
        $id = max(0, (int)($row['id'] ?? 0));
        $jobType = (string)($row['job_type'] ?? '');
        $result = $this->jsonObject((string)($row['result_json'] ?? ''));
        $progress = $this->jsonObject((string)($row['progress_json'] ?? ''));
        if ($id < 1 || $result === []) {
            return false;
        }

        if (in_array($jobType, [JobType::IMPORT_STAGED_ARCHIVE, JobType::PROCESS_BUCKET_ARCHIVE], true)) {
            $progress['archive_workflow_version'] = 2;
            $progress['stage'] = 'archive_wait_children';
            $progress['status'] = 'running';
            $progress['message'] = 'Recalculating archive child outcomes after profile-mismatch reclassification; source bytes are not being re-extracted.';
            $progress['archive_result'] = $result;
        } else {
            $progress['archive_member_router_version'] = 2;
            $progress['stage'] = 'archive_member_content_wait_child';
            $progress['status'] = 'running';
            $progress['message'] = 'Recalculating nested archive outcome after profile-mismatch reclassification; source bytes are not being re-read.';
            foreach (['nested_archive_job_id', 'detected_format', 'original_name', 'source_relative_path'] as $field) {
                if (!array_key_exists($field, $progress) && array_key_exists($field, $result)) {
                    $progress[$field] = $result[$field];
                }
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=GREATEST(attempts-1,0),available_at=?,'
            . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
            . 'last_error=NULL,result_json=NULL,progress_json=?,progress_updated_at=?,completed_at=NULL,'
            . 'dead_lettered_at=NULL,updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status="completed"'
        );
        $statement->execute([
            $now,
            PdoJobQueueSupport::encodeJson($progress),
            $now,
            $now,
            $id,
            $queueName,
        ]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
