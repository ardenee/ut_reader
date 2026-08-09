<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reconciles missing detached-worker slots from a surviving worker process.
 * Why: A configured pool must not permanently shrink when one detached PHP process exits after the initial launch.
 * Role: Best-effort worker-pool resilience service; never changes durable job state and never runs during an explicit stop.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;

final class CatalogWorkerPoolSelfHealer
{
    private string $runtime;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $storage = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storage === '') {
            throw new \InvalidArgumentException('A catalog storage path is required.');
        }
        $this->runtime = $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
    }

    /** @return array<string,mixed> */
    public function heal(string $queueName, int $maxJobs = 1000000): array
    {
        $launcher = new CatalogDetachedWorker($this->config);
        $before = $launcher->status($queueName, false);
        $desired = max(1, (int)($before['desired_count'] ?? $launcher->configuredWorkerCount()));
        $active = max(0, (int)($before['active_count'] ?? 0));
        $launching = max(0, (int)($before['launching_count'] ?? 0));

        if (!empty($before['stop_requested']) || $active + $launching >= $desired || !$this->hasReadyWork($queueName)) {
            return [
                'healed' => false,
                'reason' => !empty($before['stop_requested']) ? 'stop_requested'
                    : ($active + $launching >= $desired ? 'pool_satisfied' : 'queue_idle'),
                'desired_count' => $desired,
                'active_count' => $active,
                'launching_count' => $launching,
            ];
        }

        $lock = $this->acquireLock($queueName);
        if (!is_resource($lock)) {
            return [
                'healed' => false,
                'reason' => 'another_worker_reconciling',
                'desired_count' => $desired,
                'active_count' => $active,
                'launching_count' => $launching,
            ];
        }

        try {
            $current = $launcher->status($queueName, false);
            $desired = max(1, (int)($current['desired_count'] ?? $desired));
            $active = max(0, (int)($current['active_count'] ?? 0));
            $launching = max(0, (int)($current['launching_count'] ?? 0));
            if (!empty($current['stop_requested']) || $active + $launching >= $desired || !$this->hasReadyWork($queueName)) {
                return [
                    'healed' => false,
                    'reason' => !empty($current['stop_requested']) ? 'stop_requested'
                        : ($active + $launching >= $desired ? 'pool_satisfied' : 'queue_idle'),
                    'desired_count' => $desired,
                    'active_count' => $active,
                    'launching_count' => $launching,
                ];
            }

            $result = $launcher->start($queueName, $maxJobs, $desired);
            $after = is_array($result['worker'] ?? null)
                ? $result['worker']
                : $launcher->status($queueName, false);
            return [
                'healed' => max(0, (int)($after['active_count'] ?? 0)) > $active,
                'reason' => (string)($result['reason'] ?? 'reconciled'),
                'desired_count' => $desired,
                'active_count' => max(0, (int)($after['active_count'] ?? 0)),
                'launching_count' => max(0, (int)($after['launching_count'] ?? 0)),
                'started_workers' => max(0, (int)($result['started_workers'] ?? 0)),
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function hasReadyWork(string $queueName): bool
    {
        try {
            $statement = $this->db->prepare(
                'SELECT EXISTS(SELECT 1 FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="queued" AND cancel_requested_at IS NULL '
                . 'AND available_at<=UTC_TIMESTAMP() LIMIT 1)'
            );
            $statement->execute([$queueName]);
            return (int)$statement->fetchColumn() === 1;
        } catch (Throwable $error) {
            error_log('[UnrealDB worker self-heal ready-work check] ' . $error->getMessage());
            return true;
        }
    }

    /** @return resource|false */
    private function acquireLock(string $queueName)
    {
        if (!is_dir($this->runtime) && !mkdir($this->runtime, 0750, true) && !is_dir($this->runtime)) {
            throw new \RuntimeException('Could not create detached worker runtime storage.');
        }
        $key = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($queueName)) ?: 'catalog';
        $handle = fopen($this->runtime . DIRECTORY_SEPARATOR . $key . '.pool-heal.lock', 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not open the detached worker self-heal lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }
}
