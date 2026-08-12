<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogDetachedWorkerStop` for catalog detached worker stop.
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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Stops one worker slot or a complete detached worker pool. Each PHP process has
 * an independent lock/state/log file, while queue-stop requests remain shared.
 */
final class CatalogDetachedWorkerStop
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array<string,mixed> */
    public function stopJob(int $jobId, ?int $requestedBy, string $reason): array
    {
        $row = $this->job($jobId);
        if ($row === null) {
            return ['status' => 'not_found', 'terminated' => false];
        }

        $queue = new PdoJobQueue($this->db);
        $requestedStatus = $queue->requestCancellation($jobId, $requestedBy, $reason);
        if ((string)$row['status'] !== 'running' || $requestedStatus !== 'cancel_requested') {
            return ['status' => $requestedStatus, 'terminated' => false];
        }

        $queueName = (string)$row['queue_name'];
        $jobWorkerId = trim((string)($row['worker_id'] ?? ''));
        $launcher = new CatalogDetachedWorker($this->config);
        $worker = $launcher->workerForId($queueName, $jobWorkerId);

        if ($worker === []) {
            if (str_starts_with($jobWorkerId, 'detached:')) {
                $this->forceCancelJob($jobId, $requestedBy, $reason);
                return ['status' => 'cancelled', 'terminated' => false, 'worker_inactive' => true];
            }
            return ['status' => 'cancel_requested', 'terminated' => false, 'worker_inactive' => true];
        }

        $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
        $slot = max(1, (int)($worker['slot'] ?? $state['worker_slot'] ?? 1));
        $pid = max(0, (int)($state['pid'] ?? 0));
        if (empty($worker['active'])) {
            $this->forceCancelJob($jobId, $requestedBy, $reason);
            return [
                'status' => 'cancelled',
                'terminated' => false,
                'worker_inactive' => true,
                'worker_slot' => $slot,
                'pid' => $pid,
            ];
        }

        $launcher->requestSlotStop($queueName, $slot);
        $terminated = $pid > 0 && $this->terminateExpectedWorker($pid);
        $inactive = $terminated || $this->waitUntilSlotInactive($launcher, $queueName, $slot, 2500);

        if ($inactive) {
            $this->forceCancelJob($jobId, $requestedBy, $reason);
            $this->markStopped($launcher, $queueName, $state, $pid, 'job_stop', $slot);
            $launcher->clearSlotStopRequest($queueName, $slot);
            return [
                'status' => 'cancelled',
                'terminated' => $terminated,
                'worker_inactive' => true,
                'worker_slot' => $slot,
                'pid' => $pid,
            ];
        }

        return [
            'status' => 'cancel_requested',
            'terminated' => false,
            'worker_inactive' => false,
            'worker_slot' => $slot,
            'pid' => $pid,
        ];
    }

    /** @return array<string,mixed> */
    public function stopQueue(string $queueName, ?int $requestedBy, string $reason): array
    {
        $launcher = new CatalogDetachedWorker($this->config);
        $before = $launcher->requestStop($queueName);
        $ownedWorkerIds = [];
        $pids = [];
        $terminatedPids = [];

        foreach ((array)($before['workers'] ?? []) as $worker) {
            if (!is_array($worker)) {
                continue;
            }
            $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
            $workerId = trim((string)($state['worker_id'] ?? ''));
            if ($workerId !== '') {
                $ownedWorkerIds[$workerId] = true;
            }
            if (empty($worker['active'])) {
                continue;
            }
            $pid = max(0, (int)($state['pid'] ?? 0));
            if ($pid > 0) {
                $pids[] = $pid;
                if ($this->terminateExpectedWorker($pid)) {
                    $terminatedPids[] = $pid;
                }
            }
        }

        $inactive = empty($before['active']) || $this->waitUntilInactive($launcher, $queueName, 4000);
        $queue = new PdoJobQueue($this->db);
        $cancelled = 0;
        $cooperative = 0;

        foreach ($this->runningJobs($queueName) as $row) {
            $rowWorkerId = trim((string)($row['worker_id'] ?? ''));
            $ownedByPool = $rowWorkerId !== '' && isset($ownedWorkerIds[$rowWorkerId]);
            if ($inactive && $ownedByPool) {
                $cancelled += $this->forceCancelJob((int)$row['id'], $requestedBy, $reason);
                continue;
            }
            $status = $queue->requestCancellation((int)$row['id'], $requestedBy, $reason);
            if (in_array($status, ['cancelled', 'cancel_requested'], true)) {
                $cooperative++;
            }
        }

        if ($inactive) {
            foreach ((array)($before['workers'] ?? []) as $worker) {
                if (!is_array($worker)) {
                    continue;
                }
                $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
                if ($state === []) {
                    continue;
                }
                $slot = max(1, (int)($worker['slot'] ?? $state['worker_slot'] ?? 1));
                $this->markStopped(
                    $launcher,
                    $queueName,
                    $state,
                    max(0, (int)($state['pid'] ?? 0)),
                    'queue_stop',
                    $slot
                );
            }
            $launcher->clearStopRequest($queueName);
        }

        return [
            'worker' => $launcher->status($queueName),
            'terminated' => $terminatedPids !== [],
            'terminated_workers' => count($terminatedPids),
            'worker_pids' => $pids,
            'terminated_pids' => $terminatedPids,
            'pid' => (int)($pids[0] ?? 0),
            'cancelled_jobs' => $cancelled,
            'cooperative_jobs' => $cooperative,
        ];
    }

    /** @return array<string,mixed> */
    public function restartStaleQueue(string $queueName): array
    {
        $launcher = new CatalogDetachedWorker($this->config);
        $worker = $launcher->status($queueName, true);
        if (empty($worker['active']) || empty($worker['stale_code'])) {
            return [
                'restarted' => false,
                'reason' => empty($worker['active']) ? 'worker_inactive' : 'worker_current',
                'requeued_jobs' => 0,
                'cancelled_jobs' => 0,
                'worker' => $worker,
            ];
        }

        $desiredWorkers = max(1, (int)($worker['desired_count'] ?? $launcher->configuredWorkerCount()));
        $launcher->requestStop($queueName);
        $workerIds = [];
        $pids = [];
        $terminatedPids = [];
        foreach ((array)($worker['workers'] ?? []) as $slotWorker) {
            if (!is_array($slotWorker)) {
                continue;
            }
            $state = is_array($slotWorker['state'] ?? null) ? $slotWorker['state'] : [];
            $workerId = trim((string)($state['worker_id'] ?? ''));
            if ($workerId !== '') {
                $workerIds[$workerId] = true;
            }
            if (empty($slotWorker['active'])) {
                continue;
            }
            $pid = max(0, (int)($state['pid'] ?? 0));
            if ($pid > 0) {
                $pids[] = $pid;
                if ($this->terminateExpectedWorker($pid)) {
                    $terminatedPids[] = $pid;
                }
            }
        }

        $inactive = $this->waitUntilInactive($launcher, $queueName, 4500);
        if (!$inactive) {
            return [
                'restarted' => false,
                'reason' => 'worker_would_not_stop',
                'terminated' => false,
                'worker_pids' => $pids,
                'requeued_jobs' => 0,
                'cancelled_jobs' => 0,
                'worker' => $launcher->status($queueName, true),
            ];
        }

        $cancelled = 0;
        $requeued = 0;
        foreach (array_keys($workerIds) as $workerId) {
            $cancelled += $this->cancelRequestedWorkerJobs($queueName, $workerId);
            $requeued += $this->requeueWorkerJobs($queueName, $workerId);
        }
        foreach ((array)($worker['workers'] ?? []) as $slotWorker) {
            if (!is_array($slotWorker)) {
                continue;
            }
            $state = is_array($slotWorker['state'] ?? null) ? $slotWorker['state'] : [];
            if ($state === []) {
                continue;
            }
            $slot = max(1, (int)($slotWorker['slot'] ?? $state['worker_slot'] ?? 1));
            $this->markStopped(
                $launcher,
                $queueName,
                $state,
                max(0, (int)($state['pid'] ?? 0)),
                'stale_code_restart',
                $slot
            );
        }
        $launcher->clearStopRequest($queueName);

        return [
            'restarted' => true,
            'reason' => 'stale_code',
            'terminated' => $terminatedPids !== [],
            'terminated_workers' => count($terminatedPids),
            'worker_pids' => $pids,
            'requeued_jobs' => $requeued,
            'cancelled_jobs' => $cancelled,
            'desired_workers' => $desiredWorkers,
            'previous_code_version' => (string)($worker['code_version_running'] ?? ''),
            'current_code_version' => (string)($worker['code_version_current'] ?? ''),
            'worker' => $launcher->status($queueName, true),
        ];
    }

    /** @return array<string,mixed>|null */
    private function job(int $jobId): ?array
    {
        if ($jobId < 1) {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT id,queue_name,status,worker_id FROM ue_background_jobs WHERE id=? LIMIT 1'
        );
        $statement->execute([$jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function runningJobs(string $queueName): array
    {
        $statement = $this->db->prepare(
            'SELECT id,worker_id FROM ue_background_jobs WHERE queue_name=? AND status="running" ORDER BY id'
        );
        $statement->execute([$queueName]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function forceCancelJob(int $jobId, ?int $requestedBy, string $reason): int
    {
        return $this->forceCancelWhere('id=?', [$jobId], $requestedBy, $reason);
    }

    /** @param list<mixed> $whereArgs */
    private function forceCancelWhere(string $where, array $whereArgs, ?int $requestedBy, string $reason): int
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        $reason = mb_substr(trim($reason) !== '' ? trim($reason) : 'Stopped by administrator.', 0, 1000, 'UTF-8');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
            . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, '
            . 'cancel_requested_at=COALESCE(cancel_requested_at,?), '
            . 'cancel_requested_by=COALESCE(cancel_requested_by,?), '
            . 'cancel_reason=CASE WHEN cancel_reason IS NULL OR cancel_reason="" THEN ? ELSE cancel_reason END, '
            . 'completed_at=?, updated_at=? WHERE ' . $where . ' AND status="running"'
        );
        $statement->execute(array_merge([$timestamp, $requestedBy, $reason, $timestamp, $timestamp], $whereArgs));
        return $statement->rowCount();
    }

    private function cancelRequestedWorkerJobs(string $queueName, string $workerId): int
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="cancelled", dedupe_key=NULL, worker_id=NULL, lease_token=NULL, '
            . 'leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, completed_at=?, updated_at=? '
            . 'WHERE queue_name=? AND status="running" AND worker_id=? AND cancel_requested_at IS NOT NULL'
        );
        $statement->execute([$timestamp, $timestamp, $queueName, $workerId]);
        return $statement->rowCount();
    }

    private function requeueWorkerJobs(string $queueName, string $workerId): int
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET status="queued", attempts=GREATEST(attempts-1,0), available_at=?, '
            . 'worker_id=NULL, lease_token=NULL, leased_at=NULL, lease_expires_at=NULL, last_heartbeat_at=NULL, '
            . 'last_error="Detached worker pool was restarted after a code update; job resumed without consuming an attempt.", '
            . 'updated_at=? WHERE queue_name=? AND status="running" AND worker_id=? AND cancel_requested_at IS NULL'
        );
        // Preserve progress_json/progress_updated_at. A code-refresh restart must
        // resume the durable unit rather than replay completed work.
        $statement->execute([$timestamp, $timestamp, $queueName, $workerId]);
        return $statement->rowCount();
    }

    private function waitUntilInactive(CatalogDetachedWorker $launcher, string $queueName, int $milliseconds): bool
    {
        $deadline = microtime(true) + max(0, $milliseconds) / 1000;
        do {
            usleep(100000);
            if (empty($launcher->status($queueName)['active'])) {
                return true;
            }
        } while (microtime(true) < $deadline);
        return false;
    }

    private function waitUntilSlotInactive(
        CatalogDetachedWorker $launcher,
        string $queueName,
        int $slot,
        int $milliseconds
    ): bool {
        $deadline = microtime(true) + max(0, $milliseconds) / 1000;
        do {
            usleep(100000);
            if (empty($launcher->statusSlot($queueName, $slot)['active'])) {
                return true;
            }
        } while (microtime(true) < $deadline);
        return false;
    }

    /** @param array<string,mixed> $previousState */
    private function markStopped(
        CatalogDetachedWorker $launcher,
        string $queueName,
        array $previousState,
        int $pid,
        string $reason,
        int $slot
    ): void {
        $launcher->writeState($queueName, array_merge($previousState, [
            'status' => 'stopped',
            'queue' => $queueName,
            'worker_slot' => $slot,
            'pid' => $pid,
            'ended_at' => gmdate('c'),
            'exit_reason' => $reason,
            'forced' => true,
        ]), $slot);
    }

    private function terminateExpectedWorker(int $pid): bool
    {
        if ($pid < 1 || !$this->isExpectedWorkerProcess($pid)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return false;
            }
            $output = [];
            $code = 1;
            @exec('taskkill /PID ' . $pid . ' /T /F 2>&1', $output, $code);
            return $code === 0;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
            for ($attempt = 0; $attempt < 10; $attempt++) {
                usleep(100000);
                if (!@posix_kill($pid, 0)) {
                    return true;
                }
            }
            @posix_kill($pid, 9);
            usleep(100000);
            return !@posix_kill($pid, 0);
        }

        if (!function_exists('exec')) {
            return false;
        }
        $output = [];
        $code = 1;
        @exec('kill -TERM ' . $pid . ' 2>&1', $output, $code);
        return $code === 0;
    }

    private function isExpectedWorkerProcess(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('exec')) {
                return false;
            }
            $output = [];
            $code = 1;
            @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $output, $code);
            if ($code !== 0 || $output === []) {
                return false;
            }
            $line = strtolower(implode(' ', $output));
            return str_contains($line, 'php') && str_contains($line, (string)$pid);
        }

        $commandLinePath = '/proc/' . $pid . '/cmdline';
        if (is_readable($commandLinePath)) {
            $commandLine = (string)@file_get_contents($commandLinePath);
            return str_contains($commandLine, 'catalog-worker-detached.php');
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}
