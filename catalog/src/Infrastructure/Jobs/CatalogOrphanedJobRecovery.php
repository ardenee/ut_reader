<?php
/**
 * Recovers durable jobs whose worker process is proven absent.
 *
 * Database worker-ownership locks are the primary liveness signal on every
 * platform. Detached-worker file locks remain a compatibility signal for workers
 * launched before database ownership was introduced. Elapsed time alone never
 * authorizes recovery.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkerOwnership;

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
        $ownership = new PdoWorkerOwnership($this->db);
        $workers = $this->runningWorkers($queueName);
        $requeued = 0;
        $cancelled = 0;
        $deadLettered = 0;
        $affected = 0;
        $inactiveWorkers = 0;

        foreach ($workers as $worker) {
            $workerId = (string)$worker['worker_id'];
            if ($ownership->isAlive($queueName, $workerId)) {
                continue;
            }

            $ownedWorker = [];
            if (str_starts_with($workerId, 'detached:')) {
                $ownedWorker = $launcher->workerForId($queueName, $workerId);
                if ($ownedWorker !== [] && !empty($ownedWorker['active'])) {
                    continue;
                }
            } elseif ($this->heartbeatIsFresh((string)($worker['last_heartbeat_at'] ?? ''))) {
                // Compatibility for a CLI/container worker that began before the
                // ownership-lock change was deployed. A current heartbeat proves
                // it is still active even though it cannot hold the new lock.
                continue;
            }

            $inactiveWorkers++;
            $state = is_array($ownedWorker['state'] ?? null) ? $ownedWorker['state'] : [];
            $error = $this->workerStateError(
                $state,
                (string)($ownedWorker['log_tail'] ?? ''),
                'Worker ' . $workerId . ' no longer owns its database liveness lock; recovering its durable job.'
            );
            $result = $this->recoverRows($queueName, $workerId, $error);
            $affected += (int)$result['affected'];
            $requeued += (int)$result['requeued'];
            $cancelled += (int)$result['cancelled'];
            $deadLettered += (int)$result['dead_lettered'];
        }

        return [
            'affected' => $affected,
            'requeued' => $requeued,
            'cancelled' => $cancelled,
            'dead_lettered' => $deadLettered,
            'inactive_workers' => $inactiveWorkers,
            'recovered' => $affected > 0,
            'reason' => $affected > 0 ? 'dead_worker_jobs_recovered' : 'no_dead_worker_jobs',
            'worker' => $launcher->status($queueName, true),
        ];
    }

    /** @return array<string,mixed> */
    public function recordWorkerCrash(string $queueName, string $workerId, string $error): array
    {
        $queueName = $this->queueName($queueName);
        $workerId = trim($workerId);
        if ($workerId === '') {
            throw new \InvalidArgumentException('A worker identity is required for crash recovery.');
        }
        $error = $this->normaliseError($error);
        return $this->recoverRows($queueName, $workerId, $error) + [
            'recovered' => true,
            'reason' => 'worker_crash',
        ];
    }

    /** @return list<array{worker_id:string,last_heartbeat_at:string}> */
    private function runningWorkers(string $queueName): array
    {
        $statement = $this->db->prepare(
            'SELECT worker_id,MAX(COALESCE(last_heartbeat_at,leased_at,updated_at)) last_heartbeat_at '
            . 'FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" AND worker_id IS NOT NULL AND worker_id<>"" '
            . 'GROUP BY worker_id ORDER BY worker_id'
        );
        $statement->execute([$queueName]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $workerId = trim((string)($row['worker_id'] ?? ''));
            if ($workerId === '') {
                continue;
            }
            $rows[] = [
                'worker_id' => $workerId,
                'last_heartbeat_at' => (string)($row['last_heartbeat_at'] ?? ''),
            ];
        }
        return $rows;
    }

    private function heartbeatIsFresh(string $heartbeat): bool
    {
        $heartbeat = trim($heartbeat);
        if ($heartbeat === '') {
            return false;
        }
        $timestamp = strtotime($heartbeat . ' UTC');
        if ($timestamp === false) {
            return false;
        }
        $lease = max(15, (int)($this->config['queue']['lease_seconds'] ?? 120));
        $grace = max(300, min(3600, $lease * 2));
        return $timestamp >= time() - $grace;
    }

    /** @return array{affected:int,requeued:int,cancelled:int,dead_lettered:int,error:string} */
    private function recoverRows(string $queueName, string $workerId, string $error): array
    {
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(
                'SELECT id,attempts,max_attempts,cancel_requested_at,progress_json '
                . 'FROM ue_background_jobs WHERE queue_name=? AND status="running" AND worker_id=? '
                . 'ORDER BY id FOR UPDATE'
            );
            $select->execute([$queueName, $workerId]);
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
                        . 'WHERE id=? AND status="running" AND worker_id=?'
                    );
                    $statement->execute([$jobError, $now, $now, $jobId, $workerId]);
                    $cancelled += $statement->rowCount();
                    continue;
                }

                if ((int)($row['attempts'] ?? 0) >= max(1, (int)($row['max_attempts'] ?? 1))) {
                    $statement = $this->db->prepare(
                        'UPDATE ue_background_jobs SET status="dead_letter",dedupe_key=NULL,worker_id=NULL,lease_token=NULL,'
                        . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,recovery_count=recovery_count+1,'
                        . 'last_error=?,dead_lettered_at=?,completed_at=?,updated_at=? '
                        . 'WHERE id=? AND status="running" AND worker_id=?'
                    );
                    $statement->execute([$jobError, $now, $now, $now, $jobId, $workerId]);
                    $deadLettered += $statement->rowCount();
                    continue;
                }

                $statement = $this->db->prepare(
                    'UPDATE ue_background_jobs SET status="queued",available_at=?,worker_id=NULL,lease_token=NULL,'
                    . 'leased_at=NULL,lease_expires_at=NULL,last_heartbeat_at=NULL,recovery_count=recovery_count+1,'
                    . 'last_error=?,updated_at=? WHERE id=? AND status="running" AND worker_id=?'
                );
                $statement->execute([$available, $jobError, $now, $jobId, $workerId]);
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
    private function workerStateError(array $state, string $logTail, string $fallback = ''): string
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
            $error = trim($fallback) !== ''
                ? trim($fallback)
                : 'Worker process disappeared unexpectedly without recording a PHP exception message.';
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
            $error = 'Worker process terminated unexpectedly without a usable error message.';
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
