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
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobHistoryCleanupQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

final class PdoBackgroundJobBulkAction
{
    private const BATCH_LIMIT = 10000;

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
            // Retained archives are completed top-level archive roots. Do not
            // materialise the generic root/problem-child browser UNION merely to
            // select this already-specific recovery set; large child ledgers made
            // both the view and "Retry all" unnecessarily expensive.
            $fromSql = 'ue_background_jobs j';
            $where = ['j.parent_job_id IS NULL', 'j.queue_name=?'];
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

        $actionCondition = match ($action) {
            'restart' => '(j.status IN ("cancelled","failed","dead_letter") '
                . 'OR (j.status="completed" AND j.display_status IN ("failed","rejected","unverified")) '
                . 'OR (j.status="completed" AND j.job_type IN ("' . JobType::PROCESS_BUCKET_ARCHIVE . '","'
                . JobType::IMPORT_STAGED_ARCHIVE . '") AND j.display_status="partial"))',
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
        $eligibleIds = array_values(array_unique(array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: [])));

        $affected = 0;
        $scheduled = 0;
        $cleanupJobId = 0;
        $deletedStagedFiles = 0;
        $limited = $requested > count($eligibleIds);
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

        return [
            'action' => $action,
            'scope' => $scope,
            'queue' => $queueName,
            'requested' => $requested,
            'affected' => $affected,
            'scheduled' => $scheduled,
            'cleanup_job_id' => $cleanupJobId,
            'skipped' => $action === 'delete'
                ? max(0, min($requested, count($eligibleIds)) - $scheduled)
                : max(0, min($requested, count($eligibleIds)) - $affected),
            'deleted_staged_files' => $deletedStagedFiles,
            'limited' => $limited,
            'batch_limit' => self::BATCH_LIMIT,
            'worker' => null,
            'worker_error' => '',
            'worker_start_required' => ($action === 'restart' && $affected > 0)
                || ($action === 'delete' && $cleanupJobId > 0),
        ];
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
        // container. Existing successful archive-member child jobs are deduped by
        // parent/member key, while members that never produced a child are tried
        // again. Capture these IDs before result/status are reset.
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

    private function setShortLockWait(): void
    {
        try {
            $this->db->exec('SET SESSION innodb_lock_wait_timeout=5');
        } catch (\Throwable) {
            // Compatible database implementations may not expose this setting.
        }
    }
}
