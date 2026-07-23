<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Stops the one detached worker owned by the web launcher. Cancellation remains
 * cooperative for external/supervised workers, but an unresponsive detached PHP
 * process can be terminated and its database lease cleared immediately.
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
        $launcher = new CatalogDetachedWorker($this->config);
        $worker = $launcher->status($queueName);
        $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
        $jobWorkerId = trim((string)($row['worker_id'] ?? ''));
        $stateWorkerId = trim((string)($state['worker_id'] ?? ''));

        if (empty($worker['active'])) {
            // A detached worker that is no longer alive cannot observe cooperative
            // cancellation, so release only its stale job lease immediately.
            if (str_starts_with($jobWorkerId, 'detached:')) {
                $this->forceCancelJob($jobId, $requestedBy, $reason);
                return ['status' => 'cancelled', 'terminated' => false, 'worker_inactive' => true];
            }
            return ['status' => 'cancel_requested', 'terminated' => false, 'worker_inactive' => true];
        }

        // Never terminate an external worker merely because it owns a queue row.
        if ($jobWorkerId === '' || $stateWorkerId === '' || !hash_equals($stateWorkerId, $jobWorkerId)) {
            return ['status' => 'cancel_requested', 'terminated' => false, 'worker_inactive' => false];
        }

        $launcher->requestStop($queueName);
        $pid = max(0, (int)($state['pid'] ?? 0));
        $terminated = $pid > 0 && $this->terminateExpectedWorker($pid);
        $inactive = $this->waitUntilInactive($launcher, $queueName, 2500);

        if ($terminated || $inactive) {
            $this->forceCancelJob($jobId, $requestedBy, $reason);
            $this->markStopped($launcher, $queueName, $state, $pid, 'job_stop');
            return [
                'status' => 'cancelled',
                'terminated' => $terminated,
                'worker_inactive' => true,
                'pid' => $pid,
            ];
        }

        return [
            'status' => 'cancel_requested',
            'terminated' => false,
            'worker_inactive' => false,
            'pid' => $pid,
        ];
    }

    /** @return array<string,mixed> */
    public function stopQueue(string $queueName, ?int $requestedBy, string $reason): array
    {
        $launcher = new CatalogDetachedWorker($this->config);
        $worker = $launcher->requestStop($queueName);
        $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
        $stateWorkerId = trim((string)($state['worker_id'] ?? ''));
        $pid = max(0, (int)($state['pid'] ?? 0));
        $wasActive = !empty($worker['active']);
        $terminated = $wasActive && $pid > 0 && $this->terminateExpectedWorker($pid);
        $inactive = !$wasActive || $this->waitUntilInactive($launcher, $queueName, 2500);

        $queue = new PdoJobQueue($this->db);
        $cancelled = 0;
        $cooperative = 0;
        foreach ($this->runningJobs($queueName) as $row) {
            $rowWorkerId = trim((string)($row['worker_id'] ?? ''));
            $ownedByDetached = $stateWorkerId !== '' && $rowWorkerId !== '' && hash_equals($stateWorkerId, $rowWorkerId);
            if ($inactive && $ownedByDetached) {
                $cancelled += $this->forceCancelJob((int)$row['id'], $requestedBy, $reason);
                continue;
            }
            $status = $queue->requestCancellation((int)$row['id'], $requestedBy, $reason);
            if (in_array($status, ['cancelled', 'cancel_requested'], true)) {
                $cooperative++;
            }
        }

        if (($terminated || $inactive) && ($wasActive || $stateWorkerId !== '')) {
            $this->markStopped($launcher, $queueName, $state, $pid, 'queue_stop');
        }

        return [
            'worker' => $launcher->status($queueName),
            'terminated' => $terminated,
            'pid' => $pid,
            'cancelled_jobs' => $cancelled,
            'cooperative_jobs' => $cooperative,
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

    /** @param array<string,mixed> $previousState */
    private function markStopped(
        CatalogDetachedWorker $launcher,
        string $queueName,
        array $previousState,
        int $pid,
        string $reason
    ): void {
        $launcher->writeState($queueName, array_merge($previousState, [
            'status' => 'stopped',
            'queue' => $queueName,
            'pid' => $pid,
            'ended_at' => gmdate('c'),
            'exit_reason' => $reason,
            'forced' => true,
        ]));
        $launcher->clearStopRequest($queueName);
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
