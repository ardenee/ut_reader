<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes bounded bulk restart/cancel requests and snapshots bulk delete requests for Background Jobs.
 * Why: Bulk mutation SQL and resumable dependency-job handling do not belong in an HTTP entry point.
 * Role: Infrastructure persistence service used by the Background Jobs API.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobHistoryCleanupQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

final class PdoBackgroundJobBulkAction
{
    private const BATCH_LIMIT = 10000;
    private const ARCHIVE_DESCENDANT_UPDATE_BATCH = 500;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param list<int> $jobIds
     * @return array<string,mixed>
     */
    public function execute(
        string $action,
        string $scope,
        string $queueName,
        string $status,
        string $search,
        array $jobIds,
        ?int $userId
    ): array {
        $this->setShortLockWait();

        $directRetainedArchiveScope = $scope === 'matching'
            && $status === 'partial_archive'
            && $search === '';
        if ($directRetainedArchiveScope) {
            // Retained archives shown as logical roots are either true top-level
            // jobs or direct source children of a completed profiled-upload batch.
            // The former parent_job_id IS NULL shortcut silently excluded almost
            // every archive from large profiled imports, so "Retry retryable
            // archives" only touched a handful of rows while the Issues count
            // appeared stuck.
            $fromSql = 'ue_background_jobs j';
            $where = [
                '(j.parent_job_id IS NULL OR EXISTS('
                    . 'SELECT 1 FROM ue_background_jobs retained_parent '
                    . 'WHERE retained_parent.id=j.parent_job_id '
                    . 'AND retained_parent.queue_name=j.queue_name '
                    . 'AND retained_parent.job_type="' . JobType::PROFILED_UPLOAD_BATCH . '"'
                . '))',
                'j.queue_name=?',
            ];
            $params = [$queueName];
        } else {
            // Use the exact same search/workflow-child visibility scope as the
            // list endpoint. "Select all matching" must never mutate hidden routine
            // child rows that were not part of the administrator's current result.
            $browserScope = (new PdoBackgroundJobSearchScope($this->db))->build($queueName, $search);
            $fromSql = (string)$browserScope['from'];
            $where = [];
            $params = [];
            if (trim((string)$browserScope['where']) !== '') {
                $where[] = '(' . $browserScope['where'] . ')';
                $params = $browserScope['params'];
            }
        }

        if ($scope === 'selected') {
            if ($jobIds === []) {
                throw new \InvalidArgumentException('Select at least one background job.');
            }
            $where[] = 'j.id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')';
            array_push($params, ...$jobIds);
        }
        if ($status !== '') {
            $condition = CatalogJobDisplayStatus::filterCondition($status, 'j');
            $where[] = '(' . $condition['sql'] . ')';
            array_push($params, ...$condition['params']);
        }

        $retryableArchive = '(j.status="completed" AND j.job_type IN ("' . JobType::PROCESS_BUCKET_ARCHIVE . '","'
            . JobType::IMPORT_STAGED_ARCHIVE . '") AND j.display_status="partial" AND NOT '
            . self::decoderBlockedArchiveSql('j') . ')';
        $actionCondition = match ($action) {
            'restart' => '(j.status IN ("cancelled","failed","dead_letter") '
                . 'OR (j.status="completed" AND j.display_status IN ("failed","rejected","unverified")) '
                . 'OR ' . $retryableArchive . ')',
            'cancel' => 'j.status="queued"',
            // Queued/deferred rows are safe to purge only after they are first
            // atomically moved to cancelled below. Running rows are deliberately
            // excluded so cleanup can never delete a job underneath a worker.
            'delete' => 'j.status IN ("queued","completed","failed","dead_letter","cancelled")',
            default => throw new \InvalidArgumentException('Unsupported bulk job action.'),
        };
        $where[] = $actionCondition;
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) FROM ' . $fromSql . ' WHERE ' . $whereSql);
        $count->execute($params);
        $requested = max(0, (int)$count->fetchColumn());

        $select = $this->db->prepare(
            'SELECT j.id FROM ' . $fromSql . ' WHERE ' . $whereSql
            . ' ORDER BY j.id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $select->execute($params);
        $candidateIds = array_values(array_unique(array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        $eligibleIds = $candidateIds;
        $retryBlocked = 0;
        if ($action === 'restart' && $eligibleIds !== []) {
            $eligibleIds = $this->restartableJobIds($queueName, $eligibleIds);
            $retryBlocked = max(0, count($candidateIds) - count($eligibleIds));
        }

        $affected = 0;
        $scheduled = 0;
        $cleanupJobId = 0;
        $deletedStagedFiles = 0;
        $limited = $requested > count($candidateIds);
        $now = gmdate('Y-m-d H:i:s');

        if ($action === 'restart' && $eligibleIds !== []) {
            $affected = $this->restart($queueName, $eligibleIds, $actionCondition, $now);
        } elseif ($action === 'cancel' && $eligibleIds !== []) {
            $affected = $this->cancel($queueName, $eligibleIds, $userId, $now);
        } elseif ($action === 'delete' && $eligibleIds !== []) {
            // Freeze queued/deferred rows before the cleanup job is enqueued.
            // This closes the race where another worker could claim a ready row
            // between the browser snapshot and asynchronous deletion.
            $cancelledForDelete = $this->cancel(
                $queueName,
                $eligibleIds,
                $userId,
                $now,
                'Cancelled automatically because the job was selected for deletion.'
            );
            $affected = $cancelledForDelete;

            $queued = (new CatalogBackgroundJobHistoryCleanupQueue($this->db, $this->config))->enqueueSnapshot(
                $queueName,
                $eligibleIds,
                $requested,
                $limited,
                $userId,
                $scope === 'matching' ? 'Delete matching non-running jobs' : 'Delete selected non-running jobs'
            );
            $cleanupJobId = (int)$queued['job_id'];
            $scheduled = (int)$queued['scheduled'];
        }

        $skipped = match ($action) {
            'restart' => $retryBlocked + max(0, count($eligibleIds) - $affected),
            'delete' => max(0, count($candidateIds) - $scheduled),
            default => max(0, count($candidateIds) - $affected),
        };

        return [
            'action' => $action,
            'scope' => $scope,
            'queue' => $queueName,
            'requested' => $requested,
            'affected' => $affected,
            'scheduled' => $scheduled,
            'cleanup_job_id' => $cleanupJobId,
            'skipped' => $skipped,
            'retry_blocked' => $retryBlocked,
            'deleted_staged_files' => $deletedStagedFiles,
            'limited' => $limited,
            'batch_limit' => self::BATCH_LIMIT,
            'worker' => null,
            'worker_error' => '',
            'worker_start_required' => ($action === 'restart' && $affected > 0)
                || ($action === 'delete' && $cleanupJobId > 0),
        ];
    }

    /**
     * Filter generic Restart through the same immutable-source policy used by the
     * worker. This is authoritative server-side protection: a stale browser cannot
     * requeue a job whose persisted failure proves the same bytes cannot succeed.
     *
     * @param list<int> $jobIds
     * @return list<int>
     */
    private function restartableJobIds(string $queueName, array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        $allowed = [];
        foreach (array_chunk($jobIds, self::ARCHIVE_DESCENDANT_UPDATE_BATCH) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,job_type,last_error,result_json,progress_json FROM ue_background_jobs '
                . 'WHERE queue_name=? AND id IN (' . $idSql . ')'
            );
            $statement->execute(array_merge([$queueName], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $jobType = (string)($row['job_type'] ?? '');
                if (JobFailureRetryPolicy::isDeterministicFailureText(
                    $jobType,
                    self::persistedFailureText($row)
                )) {
                    // Public Upload retains its quarantine bytes on failure.
                    // Automatic retries should stop for immutable bad content,
                    // but an explicit administrator Retry is also the supported
                    // way to re-run those retained bytes after reader/decoder
                    // code changes.
                    if ($jobType !== JobType::PROCESS_PUBLIC_UPLOAD) {
                        continue;
                    }
                }
                $allowed[$id] = true;
            }
        }

        return array_values(array_filter(
            $jobIds,
            static fn(int $id): bool => isset($allowed[$id])
        ));
    }

    /** @param list<int> $eligibleIds */
    private function restart(string $queueName, array $eligibleIds, string $actionCondition, string $now): int
    {
        $idSql = implode(',', array_fill(0, count($eligibleIds), '?'));
        $resumeSelect = $this->db->prepare(
            'SELECT id,job_type,payload_json,progress_json,last_error FROM ue_background_jobs '
            . 'WHERE queue_name=? AND job_type=? AND id IN (' . $idSql . ')'
        );
        $resumeSelect->execute(array_merge([$queueName, JobType::REBUILD_AFFECTED_DEPENDENCIES], $eligibleIds));
        $resumeUpdate = $this->db->prepare('UPDATE ue_background_jobs SET payload_json=? WHERE queue_name=? AND id=?');
        foreach ($resumeSelect->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $resumePayload = self::resumePayload($row);
            if ($resumePayload !== null) {
                $resumeUpdate->execute([$resumePayload, $queueName, (int)$row['id']]);
            }
        }

        // A retained partial archive must be replayed from the beginning of its
        // container. Successful/duplicate/skipped descendants remain terminal.
        // Recoverable problem descendants are replayed so newer reader/classifier
        // code sees their bytes; deterministic immutable-source failures remain
        // terminal instead of being replayed by a generic administrator restart.
        $archiveSelect = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND id IN (' . $idSql . ') '
            . 'AND status="completed" AND display_status="partial" '
            . 'AND job_type IN (?,?)'
        );
        $archiveSelect->execute(array_merge(
            [$queueName],
            $eligibleIds,
            [JobType::PROCESS_BUCKET_ARCHIVE, JobType::IMPORT_STAGED_ARCHIVE]
        ));
        $retainedArchiveIds = array_values(array_unique(array_map(
            'intval',
            $archiveSelect->fetchAll(PDO::FETCH_COLUMN) ?: []
        )));

        // Reset recoverable problem descendants before exposing the parent as
        // queued. A worker may claim a child immediately; that is safe. The reverse
        // order is not: a parent could otherwise observe the old terminal failure
        // and finish partial again before its child had been reactivated.
        if ($retainedArchiveIds !== []) {
            $problemDescendants = $this->archiveProblemDescendantIds($queueName, $retainedArchiveIds);
            $this->restartArchiveProblemDescendants($queueName, $problemDescendants, $now);
        }

        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=NULL,result_json=NULL,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
            . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND '
            . str_replace('j.', '', $actionCondition)
        );
        // Ordinary resumable jobs retain progress_json as recovery state. Archive
        // parents are handled below because their retained source needs a fresh
        // archive walk rather than resuming at an already-complete entry cursor.
        $statement->execute(array_merge([$now, $now, $queueName], $eligibleIds));
        $affected = $statement->rowCount();

        if ($retainedArchiveIds !== []) {
            $archiveIdSql = implode(',', array_fill(0, count($retainedArchiveIds), '?'));
            $resetArchive = $this->db->prepare(
                'UPDATE ue_background_jobs SET progress_json=NULL,progress_updated_at=NULL '
                . 'WHERE queue_name=? AND id IN (' . $archiveIdSql . ') AND status="queued"'
            );
            $resetArchive->execute(array_merge([$queueName], $retainedArchiveIds));
        }

        return $affected;
    }

    /**
     * Return only recoverable terminal problem descendants under retained archive
     * roots. Parent/workflow identity is recursive because an archive member can
     * itself become a nested archive workflow with package children.
     *
     * @param list<int> $archiveIds
     * @return list<int>
     */
    private function archiveProblemDescendantIds(string $queueName, array $archiveIds): array
    {
        if ($archiveIds === []) {
            return [];
        }

        $rootSql = implode(',', array_fill(0, count($archiveIds), '?'));
        $statement = $this->db->prepare(
            'WITH RECURSIVE archive_descendants AS ('
            . 'SELECT id,parent_job_id,job_type,status,display_status,last_error,result_json,progress_json '
            . 'FROM ue_background_jobs '
            . 'WHERE queue_name=? AND parent_job_id IN (' . $rootSql . ') '
            . 'AND workflow_unit_key LIKE "archive:%" '
            . 'UNION ALL '
            . 'SELECT j.id,j.parent_job_id,j.job_type,j.status,j.display_status,j.last_error,j.result_json,j.progress_json '
            . 'FROM ue_background_jobs j '
            . 'INNER JOIN archive_descendants d ON d.id=j.parent_job_id '
            . 'WHERE j.queue_name=? AND j.workflow_unit_key LIKE "archive:%"'
            . ') '
            . 'SELECT DISTINCT id,job_type,last_error,result_json,progress_json FROM archive_descendants WHERE '
            . '(status IN ("cancelled","failed","dead_letter") '
            . 'OR (status="completed" AND display_status IN ("failed","rejected","unverified","partial","error")))'
        );
        $statement->execute(array_merge([$queueName], $archiveIds, [$queueName]));

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $jobType = trim((string)($row['job_type'] ?? ''));
            $failureText = self::persistedFailureText($row);
            if (JobFailureRetryPolicy::isDeterministicFailureText($jobType, $failureText)) {
                continue;
            }
            $ids[$id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /** @param array<string,mixed> $row */
    private static function persistedFailureText(array $row): string
    {
        $lastError = trim((string)($row['last_error'] ?? ''));
        if ($lastError !== '') {
            return $lastError;
        }

        foreach (['result_json', 'progress_json'] as $column) {
            $decoded = json_decode((string)($row[$column] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $message = trim((string)($decoded['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
            $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $error = trim((string)($first['error'] ?? ''));
            if ($error !== '') {
                return $error;
            }
        }

        return '';
    }

    /** @param list<int> $jobIds */
    private function restartArchiveProblemDescendants(string $queueName, array $jobIds, string $now): int
    {
        if ($jobIds === []) {
            return 0;
        }

        $affected = 0;
        foreach (array_chunk($jobIds, self::ARCHIVE_DESCENDANT_UPDATE_BATCH) as $chunk) {
            $idSql = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,'
                . 'worker_id=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,'
                . 'last_error=NULL,result_json=NULL,progress_json=NULL,progress_updated_at=NULL,'
                . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
                . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
                . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND '
                . '(status IN ("cancelled","failed","dead_letter") '
                . 'OR (status="completed" AND display_status IN ("failed","rejected","unverified","partial","error")))'
            );
            $statement->execute(array_merge([$now, $now, $queueName], $chunk));
            $affected += $statement->rowCount();
        }
        return $affected;
    }

    /** @param list<int> $eligibleIds */
    private function cancel(
        string $queueName,
        array $eligibleIds,
        ?int $userId,
        string $now,
        string $reason = 'Cancelled in bulk from Background Jobs.'
    ): int {
        $idSql = implode(',', array_fill(0, count($eligibleIds), '?'));
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,cancel_requested_at=?,'
            . 'cancel_requested_by=?,cancel_reason=?,completed_at=?,updated_at=? '
            . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND status="queued"'
        );
        $statement->execute(array_merge([$now, $userId, $reason, $now, $now, $queueName], $eligibleIds));
        return $statement->rowCount();
    }

    /** @param array<string,mixed> $row */
    private static function resumePayload(array $row): ?string
    {
        if ((string)($row['job_type'] ?? '') !== JobType::REBUILD_AFFECTED_DEPENDENCIES) {
            return null;
        }
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($payload)
            || (int)($payload['affected_file_id'] ?? 0) > 0
            || !is_array($payload['affected_file_ids'] ?? null)) {
            return null;
        }

        $resumeOffset = max(0, (int)($payload['resume_offset'] ?? 0));
        $progress = json_decode((string)($row['progress_json'] ?? ''), true);
        if (is_array($progress)) {
            $resumeOffset = max($resumeOffset, (int)($progress['done'] ?? 0));
            $message = trim((string)($progress['message'] ?? ''));
            if (preg_match('/Processed affected file\s+(\d+)\/\d+/i', $message, $match) === 1) {
                $resumeOffset = max($resumeOffset, (int)$match[1]);
            }
        }
        $lastError = trim((string)($row['last_error'] ?? ''));
        if (preg_match('/Processed affected file\s+(\d+)\/\d+/i', $lastError, $match) === 1) {
            $resumeOffset = max($resumeOffset, (int)$match[1]);
        }
        if ($resumeOffset < 1) {
            return null;
        }

        $payload['resume_offset'] = $resumeOffset;
        $payload['processed_total'] = max($resumeOffset, (int)($payload['processed_total'] ?? 0));
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : null;
    }

    private static function decoderBlockedArchiveSql(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid background-job SQL alias.');
        }
        $result = 'LOWER(COALESCE(' . $alias . '.result_json,""))';
        return '('
            . $result . ' LIKE "%installed php archive decoder cannot decode this archive/member encoding%" '
            . 'OR ' . $result . ' LIKE "%unsupported zip compression method%" '
            . 'OR ' . $result . ' LIKE "%rarentry::extract() returned failure%" '
            . 'OR ' . $result . ' LIKE "%rarentry::extract() also failed%"'
            . ')';
    }

    private function setShortLockWait(): void
    {
        try {
            $this->db->exec('SET SESSION innodb_lock_wait_timeout=5');
        } catch (\Throwable) {
            // Compatible database implementations may not expose this setting.
        }
    }
}
