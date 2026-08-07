<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogOrphanedJobRecovery` for catalog orphaned job recovery.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;

/**
 * Recovers jobs whose detached worker process has disappeared.
 *
 * A disappeared process is a failed attempt, not a free retry. Repeated fatal
 * crashes therefore reach dead-letter instead of looping forever as running.
 */
final class CatalogOrphanedJobRecovery
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array<string,mixed> */
    public function recoverInactiveQueue(string $queueName): array
    {
        $queueName = $this->queueName($queueName);
        $launcher = new CatalogDetachedWorker($this->config);
        $worker = $launcher->status($queueName, true);
        if (!empty($worker['active'])) {
            return [
                'recovered' => false,
                'reason' => 'worker_active',
                'requeued' => 0,
                'cancelled' => 0,
                'dead_lettered' => 0,
                'worker' => $worker,
            ];
        }

        $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
        $message = $this->workerStateError($state, (string)($worker['log_tail'] ?? ''));
        $result = $this->recoverRows($queueName, null, $message);

        if ((int)$result['affected'] > 0) {
            $launcher->writeState($queueName, array_merge($state, [
                'status' => 'stopped',
                'queue' => $queueName,
                'ended_at' => gmdate('c'),
                'exit_reason' => 'orphan_recovery',
                'error' => $message,
                'orphaned_requeued' => (int)$result['requeued'],
                'orphaned_cancelled' => (int)$result['cancelled'],
                'orphaned_dead_lettered' => (int)$result['dead_lettered'],
            ]));
            $launcher->clearStopRequest($queueName);
        }

        return $result + [
            'recovered' => (int)$result['affected'] > 0,
            'reason' => (int)$result['affected'] > 0 ? 'orphaned_jobs_recovered' : 'no_detached_orphans',
            'worker' => $launcher->status($queueName, true),
        ];
    }

    /** @return array<string,mixed> */
    public function recordWorkerCrash(string $queueName, string $workerId, string $error): array
    {
        $queueName = $this->queueName($queueName);
        $workerId = trim($workerId);
        if ($workerId === '' || !str_starts_with($workerId, 'detached:')) {
            throw new \InvalidArgumentException('A detached worker identity is required for crash recovery.');
        }
        $error = $this->normaliseError($error);
        return $this->recoverRows($queueName, $workerId, $error) + [
            'recovered' => true,
            'reason' => 'worker_crash',
        ];
    }

    /** @return array{affected:int,requeued:int,cancelled:int,dead_lettered:int,error:string} */
    private function recoverRows(string $queueName, ?string $workerId, string $error): array
    {
        $where = 'queue_name=? AND status="running" AND worker_id LIKE "detached:%"';
        $params = [$queueName];
        if ($workerId !== null) {
            $where .= ' AND worker_id=?';
            $params[] = $workerId;
        }

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(
                'SELECT id,attempts,max_attempts,cancel_requested_at,progress_json '
                . 'FROM ue_background_jobs WHERE ' . $where . ' ORDER BY id FOR UPDATE'
            );
            $select->execute($params);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $requeued = 0;
            $cancelled = 0;
            $deadLettered = 0;
            $now = gmdate('Y-m-d H:i:s');
            $available = gmdate('Y-m-d H:i:s', time() + 2);

            foreach ($rows as $row) {
                $jobId = (int)($row['id'] ?? 0);
                if ($jobId < 1) {
                    continue;
                }
                $jobError = $this->withCheckpoint($error, (string)($row['progress_json'] ?? ''));

                if (!empty($row['cancel_requested_at'])) {
                    $statement = $this->db->prepare(
                        'UPDATE ue_background_jobs SET status="cancelled",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                        . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,last_error=?,completed_at=?,updated_at=? '
                        . 'WHERE id=? AND status="running"'
                    );
                    $statement->execute([$jobError, $now, $now, $jobId]);
                    $cancelled += $statement->rowCount();
                    continue;
                }

                if ((int)($row['attempts'] ?? 0) >= max(1, (int)($row['max_attempts'] ?? 1))) {
                    $statement = $this->db->prepare(
                        'UPDATE ue_background_jobs SET status="dead_letter",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                        . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,recovery_count=recovery_count+1,'
                        . 'last_error=?,dead_lettered_at=?,completed_at=?,updated_at=? '
                        . 'WHERE id=? AND status="running"'
                    );
                    $statement->execute([$jobError, $now, $now, $now, $jobId]);
                    $deadLettered += $statement->rowCount();
                    continue;
                }

                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="queued",available_at=?,worker_id=NULL,lease_token=NULL,'
                    . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,recovery_count=recovery_count+1,'
                    . 'last_error=?,updated_at=? WHERE id=? AND status="running"'
                );
                $statement->execute([$available, $jobError, $now, $jobId]);
                $requeued += $statement->rowCount();
            }

            $this->db->commit();
            return [
                'affected' => $requeued + $cancelled + $deadLettered,
                'requeued' => $requeued,
                'cancelled' => $cancelled,
                'dead_lettered' => $deadLettered,
                'error' => $error,
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $state */
    private function workerStateError(array $state, string $logTail): string
    {
        $error = trim((string)($state['error'] ?? ''));
        if ($error === '') {
            $lastResult = is_array($state['last_result'] ?? null) ? $state['last_result'] : [];
            $error = trim((string)($lastResult['error'] ?? ''));
        }
        if ($error === '' || $error === "''" || $error === '""') {
            $lines = preg_split('/\R/', trim($logTail)) ?: [];
            $lastLine = trim((string)end($lines));
            if ($lastLine !== '' && $lastLine !== "''" && $lastLine !== '""') {
                $error = $lastLine;
            }
        }
        if ($error === '' || $error === "''" || $error === '""') {
            $error = 'Detached worker process disappeared unexpectedly without recording a PHP exception message.';
        }

        $file = trim((string)($state['error_file'] ?? ''));
        $line = max(0, (int)($state['error_line'] ?? 0));
        if ($file !== '') {
            $error .= ' at ' . str_replace('\\', '/', $file) . ($line > 0 ? ':' . $line : '');
        }
        return $this->normaliseError($error);
    }

    private function withCheckpoint(string $error, string $progressJson): string
    {
        $progress = json_decode($progressJson, true);
        if (!is_array($progress)) {
            return $this->normaliseError($error);
        }
        $stage = trim((string)($progress['stage'] ?? ''));
        $message = trim((string)($progress['message'] ?? ''));
        $percent = isset($progress['percent']) ? max(0, min(100, (int)$progress['percent'])) : null;
        if ($stage === '' && $message === '') {
            return $this->normaliseError($error);
        }
        $checkpoint = ' Last checkpoint: ' . ($stage !== '' ? str_replace('_', ' ', $stage) : 'unknown stage');
        if ($percent !== null) {
            $checkpoint .= ' (' . $percent . '%)';
        }
        if ($message !== '') {
            $checkpoint .= ' — ' . $message;
        }
        return $this->normaliseError($error . $checkpoint);
    }

    private function normaliseError(string $error): string
    {
        $error = trim($error);
        if ($error === '' || $error === "''" || $error === '""') {
            $error = 'Detached worker process terminated unexpectedly without a usable error message.';
        }
        return mb_substr($error, 0, 60000, 'UTF-8');
    }

    private function queueName(string $queueName): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \InvalidArgumentException('A valid queue name is required.');
        }
        return $queueName;
    }
}
