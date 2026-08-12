<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes bounded bulk restart/cancel/delete operations for durable background jobs.
 * Why: Bulk mutation SQL and resumable dependency-job handling do not belong in an HTTP entry point.
 * Role: Infrastructure persistence service used by the Background Jobs API.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCleanup;
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
     * @return array{action:string,scope:string,queue:string,requested:int,affected:int,skipped:int,deleted_staged_files:int,limited:bool,batch_limit:int,worker:null,worker_error:string,worker_start_required:bool}
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

        $where = ['queue_name=?'];
        $params = [$queueName];
        if ($scope === 'selected') {
            $where[] = 'id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')';
            array_push($params, ...$jobIds);
        }
        if ($status !== '') {
            $condition = CatalogJobDisplayStatus::filterCondition($status);
            $where[] = $condition['sql'];
            array_push($params, ...$condition['params']);
        }
        if ($search !== '') {
            $where[] = '(CAST(id AS CHAR) LIKE ? OR job_type LIKE ? OR COALESCE(concurrency_key,"") LIKE ? '
                . 'OR COALESCE(payload_json,"") LIKE ? OR COALESCE(last_error,"") LIKE ? '
                . 'OR COALESCE(result_json,"") LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $actionCondition = match ($action) {
            'restart' => '(status IN ("cancelled","failed","dead_letter") '
                . 'OR (status="completed" AND display_status IN ("failed","rejected","unverified")))',
            'cancel' => 'status="queued"',
            'delete' => 'status IN ("completed","failed","dead_letter","cancelled")',
            default => throw new \InvalidArgumentException('Unsupported bulk job action.'),
        };
        $where[] = $actionCondition;
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) FROM ue_background_jobs WHERE ' . $whereSql);
        $count->execute($params);
        $requested = (int)$count->fetchColumn();

        $select = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE ' . $whereSql
            . ' ORDER BY id ASC LIMIT ' . self::BATCH_LIMIT
        );
        $select->execute($params);
        $eligibleIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));

        $affected = 0;
        $deletedStagedFiles = 0;
        $limited = $requested > count($eligibleIds);
        $now = gmdate('Y-m-d H:i:s');

        if ($action === 'restart' && $eligibleIds !== []) {
            $affected = $this->restart($queueName, $eligibleIds, $actionCondition, $now);
        } elseif ($action === 'cancel' && $eligibleIds !== []) {
            $affected = $this->cancel($queueName, $eligibleIds, $userId, $now);
        } elseif ($action === 'delete' && $eligibleIds !== []) {
            $result = (new CatalogBackgroundJobCleanup($this->db, $this->config))
                ->deleteTerminalJobs($eligibleIds, $queueName);
            $affected = (int)($result['deleted_jobs'] ?? 0);
            $deletedStagedFiles = (int)($result['deleted_staged_files'] ?? 0);
        }

        return [
            'action' => $action,
            'scope' => $scope,
            'queue' => $queueName,
            'requested' => $requested,
            'affected' => $affected,
            'skipped' => max(0, min($requested, count($eligibleIds)) - $affected),
            'deleted_staged_files' => $deletedStagedFiles,
            'limited' => $limited,
            'batch_limit' => self::BATCH_LIMIT,
            'worker' => null,
            'worker_error' => '',
            'worker_start_required' => $action === 'restart' && $affected > 0,
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

        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued",attempts=0,available_at=?,worker_id=NULL,lease_token=NULL,'
            . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=NULL,result_json=NULL,'
            . 'cancel_requested_at=NULL,cancel_requested_by=NULL,cancel_reason=NULL,'
            . 'dead_lettered_at=NULL,completed_at=NULL,updated_at=? '
            . 'WHERE queue_name=? AND id IN (' . $idSql . ') AND ' . $actionCondition
        );
        // Do not clear progress_json/progress_updated_at. The durable progress
        // snapshot is recovery state. Legacy affected-dependency rows still get
        // their resume_offset projected into payload_json above for compatibility.
        $statement->execute(array_merge([$now, $now, $queueName], $eligibleIds));
        return $statement->rowCount();
    }

    /** @param list<int> $eligibleIds */
    private function cancel(string $queueName, array $eligibleIds, ?int $userId, string $now): int
    {
        $reason = 'Cancelled in bulk from Background Jobs.';
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
        if (!is_array($payload)) {
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
