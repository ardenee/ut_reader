<?php
/**
 * Compatibility repair for historical archive-member outcomes whose extracted
 * bytes were classified before package/profile outcomes were separated from
 * archive extraction failures.
 *
 * The repair changes durable job metadata only, then requeues affected archive
 * coordinators in their wait stage so existing child rows are re-aggregated.
 * It never re-reads or re-extracts archive/package source bytes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportOutcome;

final class PdoArchiveProfileMismatchOutcomeRepair
{
    private const BATCH_LIMIT = 1000;
    private const MAX_BATCHES = 100;
    private const MAX_ANCESTOR_DEPTH = 24;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   reclassified:int,
     *   profile_mismatch_reclassified:int,
     *   invalid_ue_reclassified:int,
     *   requeued:int
     * }
     */
    public function repair(string $queueName): array
    {
        $queueName = PdoJobQueueSupport::requiredIdentifier($queueName, 'queue');
        $parentIds = [];
        $profileMismatchReclassified = 0;
        $invalidUeReclassified = 0;

        $select = $this->db->prepare(
            'SELECT id,parent_job_id,job_type,result_json FROM ue_background_jobs '
            . 'WHERE queue_name=? AND id>? AND status="completed" '
            . 'AND job_type IN (?,?) '
            . 'AND workflow_unit_key LIKE "archive:%" '
            . 'AND display_status IN ("unverified","rejected") '
            . 'AND JSON_VALID(result_json) '
            . 'ORDER BY id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $updateChild = $this->db->prepare(
            'UPDATE ue_background_jobs SET '
            . 'result_json=JSON_SET(result_json,"$.status",?,"$.outcome_class",?),'
            . 'progress_json=CASE WHEN JSON_VALID(progress_json) '
            . 'THEN JSON_SET(progress_json,"$.status",?,"$.outcome_class",?) ELSE progress_json END,'
            . 'last_error=NULL,updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status="completed" '
            . 'AND display_status IN ("unverified","rejected")'
        );

        $afterId = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $select->execute([
                $queueName,
                $afterId,
                JobType::IMPORT_STAGED_PACKAGE,
                JobType::PROCESS_BUCKET_STAGED_PACKAGE,
            ]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                break;
            }

            $now = gmdate('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $afterId = max($afterId, (int)($row['id'] ?? 0));
                $id = max(0, (int)($row['id'] ?? 0));
                $jobType = trim((string)($row['job_type'] ?? ''));
                $result = $this->jsonObject((string)($row['result_json'] ?? ''));
                $message = trim((string)($result['message'] ?? ''));
                $currentStatus = strtolower(trim((string)($result['status'] ?? '')));
                if ($id < 1 || $result === []) {
                    continue;
                }

                $nextStatus = '';
                $outcomeClass = '';
                if ($currentStatus === 'unverified'
                    && CatalogImportOutcome::isProfileMismatchMessage($message)
                    && !JobFailureRetryPolicy::isInvalidPackageContentText($jobType, $message)) {
                    $nextStatus = CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH;
                    $outcomeClass = 'profile_mismatch';
                } elseif (JobFailureRetryPolicy::isInvalidPackageContentText($jobType, $message)) {
                    $nextStatus = CatalogImportOutcome::INVALID_UE_PACKAGE;
                    $outcomeClass = 'invalid_ue_package';
                }

                if ($nextStatus === '') {
                    continue;
                }

                $updateChild->execute([
                    $nextStatus,
                    $outcomeClass,
                    $nextStatus,
                    $outcomeClass,
                    $now,
                    $id,
                    $queueName,
                ]);
                if ($updateChild->rowCount() < 1) {
                    continue;
                }

                if ($nextStatus === CatalogImportOutcome::UNVERIFIED_PROFILE_MISMATCH) {
                    $profileMismatchReclassified++;
                } else {
                    $invalidUeReclassified++;
                }

                $parentId = max(0, (int)($row['parent_job_id'] ?? 0));
                if ($parentId > 0) {
                    $parentIds[$parentId] = true;
                }
            }

            // Advance by id even when this page contains unrelated historical
            // unverified rows; later ids may still contain package-validation
            // outcomes that need reclassification.
            if (count($rows) < self::BATCH_LIMIT) {
                break;
            }
        }

        // Older workers allowed deterministic Unreal parser failures to exhaust
        // retries and become failed/dead-letter jobs. Those bytes do not become
        // valid by retrying. Convert the terminal ledger row directly to the
        // modern invalid_ue_package outcome so System Error backfill can record
        // the data-quality problem without reopening or reparsing the file.
        $failedSelect = $this->db->prepare(
            'SELECT id,parent_job_id,job_type,payload_json,progress_json,last_error FROM ue_background_jobs '
            . 'WHERE queue_name=? AND id>? AND status IN ("failed","dead_letter") '
            . 'AND job_type IN (?,?,?) AND COALESCE(last_error,"")<>"" '
            . 'ORDER BY id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $failedUpdate = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="completed",result_json=?,progress_json=?,'
            . 'last_error=NULL,worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,'
            . 'last_heartbeat_at=NULL,dead_lettered_at=NULL,completed_at=COALESCE(completed_at,?),updated_at=? '
            . 'WHERE id=? AND queue_name=? AND status IN ("failed","dead_letter")'
        );

        $afterFailedId = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $failedSelect->execute([
                $queueName,
                $afterFailedId,
                JobType::PROCESS_BUCKET_UPLOAD,
                JobType::PROCESS_BUCKET_STAGED_PACKAGE,
                JobType::IMPORT_STAGED_PACKAGE,
            ]);
            $failedRows = $failedSelect->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($failedRows === []) {
                break;
            }

            foreach ($failedRows as $row) {
                $afterFailedId = max($afterFailedId, (int)($row['id'] ?? 0));
                $id = max(0, (int)($row['id'] ?? 0));
                $jobType = trim((string)($row['job_type'] ?? ''));
                $message = trim((string)($row['last_error'] ?? ''));
                if ($id < 1 || !JobFailureRetryPolicy::isInvalidPackageContentText($jobType, $message)) {
                    continue;
                }

                $payload = $this->jsonObject((string)($row['payload_json'] ?? ''));
                $progress = $this->jsonObject((string)($row['progress_json'] ?? ''));
                $originalName = trim((string)($payload['original_name'] ?? ''));
                $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? $originalName));
                $operation = match ($jobType) {
                    JobType::PROCESS_BUCKET_UPLOAD => 'process_bucket_upload',
                    JobType::PROCESS_BUCKET_STAGED_PACKAGE => 'process_bucket_staged_package',
                    default => 'import_staged_package',
                };

                $result = [
                    'operation' => $operation,
                    'status' => CatalogImportOutcome::INVALID_UE_PACKAGE,
                    'message' => $message,
                    'original_name' => $originalName,
                    'source_relative_path' => $sourceRelativePath,
                    'outcome_class' => 'invalid_ue_package',
                    'system_error_recorded' => false,
                ];
                foreach (['file_id', 'md5', 'sha1', 'size', 'bytes', 'archive_source_name', 'archive_entry_path'] as $field) {
                    if (array_key_exists($field, $payload)) {
                        $result[$field] = $payload[$field];
                    }
                }

                $progress['stage'] = 'complete';
                $progress['done'] = 100;
                $progress['total'] = 100;
                $progress['percent'] = 100;
                $progress['status'] = CatalogImportOutcome::INVALID_UE_PACKAGE;
                $progress['message'] = 'Invalid Unreal package; recorded as a non-retryable data-quality outcome. ' . $message;
                $progress['error'] = $message;
                $progress['outcome_class'] = 'invalid_ue_package';
                $progress['system_error_recorded'] = false;

                $now = gmdate('Y-m-d H:i:s');
                $failedUpdate->execute([
                    PdoJobQueueSupport::encodeJson($result),
                    PdoJobQueueSupport::encodeJson($progress),
                    $now,
                    $now,
                    $id,
                    $queueName,
                ]);
                if ($failedUpdate->rowCount() < 1) {
                    continue;
                }

                $invalidUeReclassified++;
                $parentId = max(0, (int)($row['parent_job_id'] ?? 0));
                if ($parentId > 0) {
                    $parentIds[$parentId] = true;
                }
            }

            if (count($failedRows) < self::BATCH_LIMIT) {
                break;
            }
        }

        $reclassified = $profileMismatchReclassified + $invalidUeReclassified;
        if ($parentIds === []) {
            return [
                'reclassified' => $reclassified,
                'profile_mismatch_reclassified' => $profileMismatchReclassified,
                'invalid_ue_reclassified' => $invalidUeReclassified,
                'requeued' => 0,
            ];
        }

        $ancestors = $this->affectedAncestors($queueName, array_keys($parentIds));
        if ($ancestors === []) {
            return [
                'reclassified' => $reclassified,
                'profile_mismatch_reclassified' => $profileMismatchReclassified,
                'invalid_ue_reclassified' => $invalidUeReclassified,
                'requeued' => 0,
            ];
        }

        $requeued = 0;
        // Publish the entire affected coordinator chain atomically. Multiple
        // detached workers may start concurrently; none may observe an outer
        // parent queued while a nested coordinator still carries stale outcome
        // aggregation from the historical child status.
        $this->db->beginTransaction();
        try {
            foreach ($ancestors as $row) {
                if ($this->requeueCoordinator($queueName, $row)) {
                    $requeued++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return [
            'reclassified' => $reclassified,
            'profile_mismatch_reclassified' => $profileMismatchReclassified,
            'invalid_ue_reclassified' => $invalidUeReclassified,
            'requeued' => $requeued,
        ];
    }

    /**
     * @param list<int> $initialIds
     * @return list<array<string,mixed>>
     */
    private function affectedAncestors(string $queueName, array $initialIds): array
    {
        $frontier = array_values(array_unique(array_filter(
            array_map('intval', $initialIds),
            static fn(int $id): bool => $id > 0
        )));
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
            && in_array((string)($result['status'] ?? ''), ['partial', CatalogImportOutcome::ARCHIVE_INVALID_FILES], true);
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
            $progress['message'] = 'Recalculating archive child outcomes after outcome reclassification; source bytes are not being re-extracted.';
            $progress['archive_result'] = $result;
        } else {
            $progress['archive_member_router_version'] = 2;
            $progress['stage'] = 'archive_member_content_wait_child';
            $progress['status'] = 'running';
            $progress['message'] = 'Recalculating nested archive outcome after outcome reclassification; source bytes are not being re-read.';
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
