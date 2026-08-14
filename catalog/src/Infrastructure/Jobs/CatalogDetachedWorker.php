<?php
/**
 * Host-local detached worker pool supervisor.
 *
 * Runtime state/locks/logs, process launching and code fingerprinting are owned
 * by focused collaborators. This class retains the established public API used
 * by administrator pages and catalog-worker-detached.php.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogDetachedWorker
{
    public const DEFAULT_WORKERS = 4;
    public const MAX_WORKERS = 8;

    private readonly string $catalogRoot;
    private readonly CatalogWorkerRuntimeStateStore $runtime;
    private readonly CatalogWorkerProcessLauncher $launcher;
    private readonly CatalogWorkerCodeVersion $codeVersion;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $storage = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storage === '') {
            throw new \InvalidArgumentException('A catalog storage path is required.');
        }
        $this->catalogRoot = dirname(__DIR__, 3);
        $runtimePath = $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
        $this->runtime = new CatalogWorkerRuntimeStateStore($runtimePath, self::MAX_WORKERS);
        $this->launcher = new CatalogWorkerProcessLauncher($config, $this->catalogRoot);
        $this->codeVersion = new CatalogWorkerCodeVersion($this->catalogRoot);
    }

    public function configuredWorkerCount(): int
    {
        $value = (int)($this->config['queue']['worker_processes'] ?? 0);
        if ($value < 1) {
            $value = (int)(getenv('UNREALDB_WORKER_PROCESSES') ?: self::DEFAULT_WORKERS);
        }
        return $this->normalizeWorkerCount($value);
    }

    public function normalizeWorkerCount(int $count): int
    {
        return max(1, min(self::MAX_WORKERS, $count));
    }

    public function resolvedPhpBinary(): string
    {
        return $this->launcher->phpBinary();
    }

    /** @return array<string,mixed> */
    public function start(string $queue, int $maxJobs = 10000, int $workerCount = 0): array
    {
        $queue = $this->runtime->queue($queue);
        $maxJobs = max(1, min(10000, $maxJobs));
        $this->runtime->ensureRuntime();

        $before = $this->status($queue);
        $workerCount = $workerCount > 0
            ? $this->normalizeWorkerCount($workerCount)
            : $this->normalizeWorkerCount((int)($before['desired_count'] ?? $this->configuredWorkerCount()));
        if (!empty($before['stale_code'])) {
            return [
                'started' => false,
                'reason' => 'stale_worker_running',
                'requested_workers' => $workerCount,
                'started_workers' => 0,
                'stopping_workers' => 0,
                'worker' => $before,
            ];
        }

        $php = $this->launcher->phpBinary();
        $this->launcher->assertPhpBinary($php);
        $this->runtime->writeJson($this->runtime->path($queue, 'pool'), [
            'queue' => $queue,
            'desired_count' => $workerCount,
            'updated_at' => gmdate('c'),
        ]);
        $this->clearQueueStopRequest($queue);

        $stopping = 0;
        for ($slot = $workerCount + 1; $slot <= self::MAX_WORKERS; $slot++) {
            if (!empty($this->statusSlot($queue, $slot)['active'])) {
                $this->requestSlotStop($queue, $slot);
                $stopping++;
            } else {
                $this->clearSlotStopRequest($queue, $slot);
            }
        }

        $script = $this->catalogRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'catalog-worker-detached.php';
        if (!is_file($script)) {
            throw new \RuntimeException('Detached worker script is missing.');
        }
        $lease = max(15, min(3600, (int)($this->config['queue']['lease_seconds'] ?? 120)));
        $launchSpecs = [];
        for ($slot = 1; $slot <= $workerCount; $slot++) {
            $slotStatus = $this->statusSlot($queue, $slot);
            if (!empty($slotStatus['active']) || !empty($slotStatus['launching'])) {
                continue;
            }
            $this->clearSlotStopRequest($queue, $slot);
            $arguments = [
                '--queue=' . $queue,
                '--max-jobs=' . $maxJobs,
                '--sleep-ms=250',
                '--lease-seconds=' . $lease,
                '--worker-slot=' . $slot,
                '--worker-count=' . $workerCount,
            ];
            $log = $this->runtime->path($queue, 'log', $slot);
            $this->writeState($queue, [
                'status' => 'launching',
                'queue' => $queue,
                'worker_count' => $workerCount,
                'max_jobs' => $maxJobs,
                'code_version' => $this->codeVersion(),
                'requested_at' => gmdate('c'),
                'php_binary' => $php,
            ], $slot);
            $launchSpecs[] = ['slot' => $slot, 'arguments' => $arguments, 'log' => $log];
        }

        $this->launcher->launchPool($php, $script, $launchSpecs);
        $started = count($launchSpecs);
        $launchedSlots = array_values(array_map(
            static fn(array $launch): int => (int)$launch['slot'],
            $launchSpecs
        ));

        $after = $this->status($queue, true);
        if ($launchedSlots !== []) {
            $deadline = microtime(true) + (PHP_OS_FAMILY === 'Windows' ? 45.0 : 10.0);
            do {
                $activeLaunched = 0;
                $terminalLaunched = 0;
                foreach ((array)($after['workers'] ?? []) as $worker) {
                    $slot = (int)($worker['slot'] ?? 0);
                    if (!in_array($slot, $launchedSlots, true)) {
                        continue;
                    }
                    if (!empty($worker['active'])) {
                        $activeLaunched++;
                    }
                    $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
                    if (in_array(strtolower((string)($state['status'] ?? '')), ['failed', 'stopped'], true)) {
                        $terminalLaunched++;
                    }
                }
                if ($activeLaunched === count($launchedSlots)
                    || $terminalLaunched === count($launchedSlots)
                    || microtime(true) >= $deadline) {
                    break;
                }
                usleep(100000);
                $after = $this->status($queue, true);
            } while (true);

            $launchErrors = [];
            $stillLaunching = [];
            $notActive = [];
            foreach ((array)($after['workers'] ?? []) as $worker) {
                $slot = (int)($worker['slot'] ?? 0);
                if (!in_array($slot, $launchedSlots, true) || !empty($worker['active'])) {
                    continue;
                }
                $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
                $stateStatus = strtolower(trim((string)($state['status'] ?? '')));
                $error = trim((string)($state['error'] ?? ''));
                if ($stateStatus === 'failed') {
                    $launchErrors[] = 'worker ' . $slot . ': '
                        . ($error !== '' ? $error : 'worker process failed during startup');
                } elseif ($stateStatus === 'launching') {
                    $stillLaunching[] = $slot;
                } else {
                    $notActive[] = $slot;
                }
            }
            if ($launchErrors !== []) {
                throw new \RuntimeException(
                    'Detached worker startup failed using ' . $php . ': '
                    . implode(' | ', array_slice($launchErrors, 0, 3))
                );
            }
            if ($stillLaunching !== [] || $notActive !== []) {
                $missing = array_values(array_unique(array_merge($stillLaunching, $notActive)));
                sort($missing);
                $tail = trim((string)($after['log_tail'] ?? ''));
                $message = 'Detached worker process did not acquire its runtime lock using ' . $php
                    . ' for slot(s) ' . implode(', ', $missing) . '.';
                if ($tail !== '') {
                    $message .= ' Current launch log: '
                        . substr(preg_replace('/\s+/', ' ', $tail) ?? $tail, -1200);
                }
                throw new \RuntimeException($message);
            }
        }

        $reason = $started > 0
            ? (!empty($before['active']) ? 'pool_expanded' : 'launched')
            : ($stopping > 0 ? 'pool_reduced' : 'already_running');
        return [
            'started' => $started > 0,
            'reason' => $reason,
            'requested_workers' => $workerCount,
            'started_workers' => $started,
            'stopping_workers' => $stopping,
            'php_binary' => $php,
            'worker' => $after,
        ];
    }

    /** @return array<string,mixed> */
    public function status(string $queue, bool $includeLog = false): array
    {
        $queue = $this->runtime->queue($queue);
        $this->runtime->ensureRuntime();
        $pool = $this->runtime->readJson($this->runtime->path($queue, 'pool'));
        $desired = $this->normalizeWorkerCount(
            (int)($pool['desired_count'] ?? $this->configuredWorkerCount())
        );
        $current = $this->codeVersion(true);
        $workers = $logs = $versions = [];
        $active = $launching = $processed = $latestAt = 0;
        $stale = false;
        $primary = [];

        for ($slot = 1; $slot <= self::MAX_WORKERS; $slot++) {
            $worker = $this->statusSlot($queue, $slot, $includeLog, $current);
            $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
            if ($slot > $desired && empty($worker['active']) && empty($worker['launching']) && $state === []) {
                continue;
            }
            $workers[] = $worker;
            $active += !empty($worker['active']) ? 1 : 0;
            $launching += !empty($worker['launching']) ? 1 : 0;
            $processed += max(0, (int)($state['processed'] ?? 0));
            $stale = $stale || !empty($worker['stale_code']);
            $running = trim((string)($worker['code_version_running'] ?? ''));
            if ($running !== '') {
                $versions[$running] = true;
            }
            $updated = strtotime((string)($state['updated_at'] ?? $state['ended_at'] ?? $state['started_at'] ?? '')) ?: 0;
            if (!empty($worker['active']) || $updated >= $latestAt) {
                $primary = $state;
                $latestAt = $updated;
            }
            if ($includeLog && trim((string)($worker['log_tail'] ?? '')) !== '') {
                $logs[] = '[worker ' . $slot . '] ' . trim((string)$worker['log_tail']);
            }
        }

        $primary = array_merge($primary, [
            'status' => $active > 0
                ? 'running'
                : ($launching > 0 ? 'launching' : (string)($primary['status'] ?? 'stopped')),
            'queue' => $queue,
            'worker_count' => $desired,
            'active_workers' => $active,
            'processed' => $processed,
        ]);
        $result = [
            'active' => $active > 0,
            'active_count' => $active,
            'launching_count' => $launching,
            'desired_count' => $desired,
            'max_workers' => self::MAX_WORKERS,
            'queue' => $queue,
            'php_binary' => $this->launcher->phpBinary(),
            'stop_requested' => is_file($this->runtime->path($queue, 'queue-stop')),
            'state' => $primary,
            'workers' => $workers,
            'code_version_current' => $current,
            'code_version_running' => count($versions) === 1
                ? (string)array_key_first($versions)
                : (count($versions) > 1 ? 'mixed' : ''),
            'stale_code' => $stale,
            'log_file' => $this->runtime->key($queue) . '.worker-*.log',
        ];
        if ($includeLog) {
            $result['log_tail'] = implode("\n\n", $logs);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function statusSlot(string $queue, int $slot, bool $includeLog = false, ?string $current = null): array
    {
        $queue = $this->runtime->queue($queue);
        $slot = $this->runtime->slot($slot);
        $state = $this->runtime->readJson($this->runtime->path($queue, 'state', $slot));
        $active = $this->runtime->lockHeld($queue, $slot);
        $launching = false;
        if (!$active && (string)($state['status'] ?? '') === 'launching') {
            $requested = strtotime((string)($state['requested_at'] ?? ''));
            $launching = $requested !== false && $requested >= time() - 15;
        }
        $current ??= $this->codeVersion(true);
        $running = trim((string)($state['code_version'] ?? ''));
        $result = [
            'slot' => $slot,
            'active' => $active,
            'launching' => $launching,
            'stop_requested' => is_file($this->runtime->path($queue, 'queue-stop'))
                || is_file($this->runtime->path($queue, 'slot-stop', $slot)),
            'state' => $state,
            'code_version_current' => $current,
            'code_version_running' => $running,
            'stale_code' => $active && ($running === '' || !hash_equals($current, $running)),
            'log_file' => basename($this->runtime->path($queue, 'log', $slot)),
        ];
        if ($includeLog) {
            $result['log_tail'] = $this->tailLog($queue, 16384, $slot);
        }
        return $result;
    }

    public function codeVersion(bool $refresh = false): string
    {
        return $this->codeVersion->current($refresh);
    }

    public function tailLog(string $queue, int $maximumBytes = 16384, int $slot = 1): string
    {
        return $this->runtime->tailLog($queue, $maximumBytes, $slot);
    }

    /** @return array<string,mixed> */
    public function requestStop(string $queue): array
    {
        $queue = $this->runtime->queue($queue);
        $this->runtime->ensureRuntime();
        $this->runtime->writeJson(
            $this->runtime->path($queue, 'queue-stop'),
            ['queue' => $queue, 'requested_at' => gmdate('c')]
        );
        return $this->status($queue);
    }

    public function requestSlotStop(string $queue, int $slot): void
    {
        $queue = $this->runtime->queue($queue);
        $slot = $this->runtime->slot($slot);
        $this->runtime->ensureRuntime();
        $this->runtime->writeJson(
            $this->runtime->path($queue, 'slot-stop', $slot),
            ['queue' => $queue, 'worker_slot' => $slot, 'requested_at' => gmdate('c')]
        );
    }

    public function stopRequested(string $queue, int $slot = 0): bool
    {
        $queue = $this->runtime->queue($queue);
        return is_file($this->runtime->path($queue, 'queue-stop'))
            || ($slot > 0 && is_file($this->runtime->path($queue, 'slot-stop', $this->runtime->slot($slot))));
    }

    public function clearStopRequest(string $queue): void
    {
        $queue = $this->runtime->queue($queue);
        $this->clearQueueStopRequest($queue);
        for ($slot = 1; $slot <= self::MAX_WORKERS; $slot++) {
            $this->clearSlotStopRequest($queue, $slot);
        }
    }

    public function clearQueueStopRequest(string $queue): void
    {
        @unlink($this->runtime->path($this->runtime->queue($queue), 'queue-stop'));
    }

    public function clearSlotStopRequest(string $queue, int $slot): void
    {
        @unlink($this->runtime->path(
            $this->runtime->queue($queue),
            'slot-stop',
            $this->runtime->slot($slot)
        ));
    }

    /** @return resource|false */
    public function acquireWorkerLock(string $queue, int $slot = 1)
    {
        return $this->runtime->acquireWorkerLock($queue, $slot);
    }

    /** @param array<string,mixed> $state */
    public function writeState(string $queue, array $state, int $slot = 1): void
    {
        $queue = $this->runtime->queue($queue);
        $slot = $this->runtime->slot($slot);
        $state['worker_slot'] = $slot;
        $state['updated_at'] = gmdate('c');
        $this->runtime->writeJson($this->runtime->path($queue, 'state', $slot), $state);
    }

    /** @return array<string,mixed> */
    public function workerForId(string $queue, string $workerId): array
    {
        $workerId = trim($workerId);
        if ($workerId === '') {
            return [];
        }
        foreach ((array)($this->status($queue)['workers'] ?? []) as $worker) {
            $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
            $candidate = trim((string)($state['worker_id'] ?? ''));
            if ($candidate !== '' && hash_equals($candidate, $workerId)) {
                return is_array($worker) ? $worker : [];
            }
        }
        return [];
    }
}
