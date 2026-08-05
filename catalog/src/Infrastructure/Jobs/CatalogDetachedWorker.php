<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogDetachedWorker
{
    public const DEFAULT_WORKERS = 4;
    public const MAX_WORKERS = 8;

    private string $runtime;
    private string $catalogRoot;
    private ?string $version = null;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $storage = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storage === '') {
            throw new \InvalidArgumentException('A catalog storage path is required.');
        }
        $this->runtime = $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
        $this->catalogRoot = dirname(__DIR__, 3);
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
        return $this->phpBinary();
    }

    /** @return array<string,mixed> */
    public function start(string $queue, int $maxJobs = 10000, int $workerCount = 0): array
    {
        $queue = $this->queue($queue);
        $maxJobs = max(1, min(10000, $maxJobs));
        $this->ensureRuntime();

        $before = $this->status($queue);
        $workerCount = $workerCount > 0
            ? $this->normalizeWorkerCount($workerCount)
            : $this->normalizeWorkerCount((int)($before['desired_count'] ?? $this->configuredWorkerCount()));
        if (!empty($before['stale_code'])) {
            return ['started' => false, 'reason' => 'stale_worker_running', 'requested_workers' => $workerCount,
                'started_workers' => 0, 'stopping_workers' => 0, 'worker' => $before];
        }

        $php = $this->phpBinary();
        $this->assertPhpBinary($php);

        $this->writeJson($this->path($queue, 'pool'), [
            'queue' => $queue, 'desired_count' => $workerCount, 'updated_at' => gmdate('c'),
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
        $started = 0;
        $launchedSlots = [];
        for ($slot = 1; $slot <= $workerCount; $slot++) {
            if (!empty($this->statusSlot($queue, $slot)['active'])) {
                continue;
            }
            $this->clearSlotStopRequest($queue, $slot);
            $this->writeState($queue, [
                'status' => 'launching', 'queue' => $queue, 'worker_count' => $workerCount,
                'max_jobs' => $maxJobs, 'code_version' => $this->codeVersion(), 'requested_at' => gmdate('c'),
                'php_binary' => $php,
            ], $slot);
            $this->spawn($php, $script, [
                '--queue=' . $queue, '--max-jobs=' . $maxJobs, '--sleep-ms=250', '--lease-seconds=' . $lease,
                '--worker-slot=' . $slot, '--worker-count=' . $workerCount,
            ], $this->path($queue, 'log', $slot));
            $started++;
            $launchedSlots[] = $slot;
        }

        $after = $this->status($queue, true);
        if ($launchedSlots !== []) {
            $deadline = microtime(true) + 2.0;
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
                if ($activeLaunched > 0 || $terminalLaunched === count($launchedSlots) || microtime(true) >= $deadline) {
                    break;
                }
                usleep(100000);
                $after = $this->status($queue, true);
            } while (true);

            $launchErrors = [];
            $stillLaunching = [];
            foreach ((array)($after['workers'] ?? []) as $worker) {
                $slot = (int)($worker['slot'] ?? 0);
                if (!in_array($slot, $launchedSlots, true) || !empty($worker['active'])) {
                    continue;
                }
                $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
                $stateStatus = strtolower(trim((string)($state['status'] ?? '')));
                $error = trim((string)($state['error'] ?? ''));
                if ($stateStatus === 'failed') {
                    $launchErrors[] = 'worker ' . $slot . ': ' . ($error !== '' ? $error : 'worker process failed during startup');
                } elseif ($stateStatus === 'launching') {
                    $stillLaunching[] = $slot;
                }
            }
            if ($launchErrors !== []) {
                throw new \RuntimeException(
                    'Detached worker startup failed using ' . $php . ': ' . implode(' | ', array_slice($launchErrors, 0, 3))
                );
            }
            if ($stillLaunching !== []) {
                $tail = trim((string)($after['log_tail'] ?? ''));
                $message = 'Detached worker process did not acquire its runtime lock using ' . $php
                    . ' for slot(s) ' . implode(', ', $stillLaunching) . '.';
                if ($tail !== '') {
                    $message .= ' Worker log: ' . substr(preg_replace('/\s+/', ' ', $tail) ?? $tail, -1200);
                }
                throw new \RuntimeException($message);
            }
        }

        $reason = $started > 0 ? (!empty($before['active']) ? 'pool_expanded' : 'launched')
            : ($stopping > 0 ? 'pool_reduced' : 'already_running');
        return ['started' => $started > 0, 'reason' => $reason, 'requested_workers' => $workerCount,
            'started_workers' => $started, 'stopping_workers' => $stopping, 'php_binary' => $php,
            'worker' => $after];
    }

    /** @return array<string,mixed> */
    public function status(string $queue, bool $includeLog = false): array
    {
        $queue = $this->queue($queue);
        $this->ensureRuntime();
        $pool = $this->readJson($this->path($queue, 'pool'));
        $desired = $this->normalizeWorkerCount((int)($pool['desired_count'] ?? $this->configuredWorkerCount()));
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
            if ($running !== '') $versions[$running] = true;
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
            'status' => $active > 0 ? 'running' : ($launching > 0 ? 'launching' : (string)($primary['status'] ?? 'stopped')),
            'queue' => $queue, 'worker_count' => $desired, 'active_workers' => $active, 'processed' => $processed,
        ]);
        $result = [
            'active' => $active > 0, 'active_count' => $active, 'launching_count' => $launching,
            'desired_count' => $desired, 'max_workers' => self::MAX_WORKERS, 'queue' => $queue,
            'php_binary' => $this->phpBinary(),
            'stop_requested' => is_file($this->path($queue, 'queue-stop')), 'state' => $primary,
            'workers' => $workers, 'code_version_current' => $current,
            'code_version_running' => count($versions) === 1 ? (string)array_key_first($versions) : (count($versions) > 1 ? 'mixed' : ''),
            'stale_code' => $stale, 'log_file' => $this->key($queue) . '.worker-*.log',
        ];
        if ($includeLog) $result['log_tail'] = implode("\n\n", $logs);
        return $result;
    }

    /** @return array<string,mixed> */
    public function statusSlot(string $queue, int $slot, bool $includeLog = false, ?string $current = null): array
    {
        $queue = $this->queue($queue);
        $slot = $this->slot($slot);
        $state = $this->readJson($this->path($queue, 'state', $slot));
        $active = $this->lockHeld($queue, $slot);
        $launching = false;
        if (!$active && (string)($state['status'] ?? '') === 'launching') {
            $requested = strtotime((string)($state['requested_at'] ?? ''));
            $launching = $requested !== false && $requested >= time() - 3;
        }
        $current ??= $this->codeVersion(true);
        $running = trim((string)($state['code_version'] ?? ''));
        $result = [
            'slot' => $slot, 'active' => $active, 'launching' => $launching,
            'stop_requested' => is_file($this->path($queue, 'queue-stop')) || is_file($this->path($queue, 'slot-stop', $slot)),
            'state' => $state, 'code_version_current' => $current, 'code_version_running' => $running,
            'stale_code' => $active && ($running === '' || !hash_equals($current, $running)),
            'log_file' => basename($this->path($queue, 'log', $slot)),
        ];
        if ($includeLog) $result['log_tail'] = $this->tailLog($queue, 16384, $slot);
        return $result;
    }

    public function codeVersion(bool $refresh = false): string
    {
        if (!$refresh && $this->version !== null) return $this->version;
        $paths = [
            $this->catalogRoot . '/bin/catalog-worker-detached.php', __FILE__,
            $this->catalogRoot . '/src/Domain/Jobs/JobResourcePolicy.php',
            $this->catalogRoot . '/src/Application/Jobs/JobWorker.php',
            $this->catalogRoot . '/src/Infrastructure/Persistence/PdoJobQueue.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketUploadJobHandler.php',
            $this->catalogRoot . '/src/Infrastructure/Jobs/CatalogBucketRedirectJobHandler.php',
        ];
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = str_replace('\\', '/', $path) . ':' . (is_file($path) ? (string)filemtime($path) . ':' . (string)filesize($path) : 'missing');
        }
        return $this->version = substr(hash('sha256', implode("\n", $parts)), 0, 24);
    }

    public function tailLog(string $queue, int $maximumBytes = 16384, int $slot = 1): string
    {
        $path = $this->path($this->queue($queue), 'log', $this->slot($slot));
        if (!is_file($path) || !is_readable($path) || ($size = filesize($path)) === false || $size < 1) return '';
        $maximumBytes = max(1024, min(131072, $maximumBytes));
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) return '';
        try {
            $offset = max(0, $size - $maximumBytes);
            if ($offset > 0) { fseek($handle, $offset); fgets($handle); }
            $data = stream_get_contents($handle);
            return is_string($data) ? trim($data) : '';
        } finally { fclose($handle); }
    }

    /** @return array<string,mixed> */
    public function requestStop(string $queue): array
    {
        $queue = $this->queue($queue);
        $this->ensureRuntime();
        $this->writeJson($this->path($queue, 'queue-stop'), ['queue' => $queue, 'requested_at' => gmdate('c')]);
        return $this->status($queue);
    }

    public function requestSlotStop(string $queue, int $slot): void
    {
        $queue = $this->queue($queue); $slot = $this->slot($slot); $this->ensureRuntime();
        $this->writeJson($this->path($queue, 'slot-stop', $slot), ['queue' => $queue, 'worker_slot' => $slot, 'requested_at' => gmdate('c')]);
    }

    public function stopRequested(string $queue, int $slot = 0): bool
    {
        $queue = $this->queue($queue);
        return is_file($this->path($queue, 'queue-stop'))
            || ($slot > 0 && is_file($this->path($queue, 'slot-stop', $this->slot($slot))));
    }

    public function clearStopRequest(string $queue): void
    {
        $queue = $this->queue($queue); $this->clearQueueStopRequest($queue);
        for ($slot = 1; $slot <= self::MAX_WORKERS; $slot++) $this->clearSlotStopRequest($queue, $slot);
    }

    public function clearQueueStopRequest(string $queue): void { @unlink($this->path($this->queue($queue), 'queue-stop')); }
    public function clearSlotStopRequest(string $queue, int $slot): void { @unlink($this->path($this->queue($queue), 'slot-stop', $this->slot($slot))); }

    /** @return resource|false */
    public function acquireWorkerLock(string $queue, int $slot = 1)
    {
        $queue = $this->queue($queue); $slot = $this->slot($slot); $this->ensureRuntime();
        $handle = fopen($this->path($queue, 'lock', $slot), 'c+');
        if (!is_resource($handle)) throw new \RuntimeException('Could not open the detached worker lock file.');
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return false; }
        ftruncate($handle, 0); fwrite($handle, (string)getmypid()); fflush($handle);
        return $handle;
    }

    /** @param array<string,mixed> $state */
    public function writeState(string $queue, array $state, int $slot = 1): void
    {
        $queue = $this->queue($queue); $slot = $this->slot($slot);
        $state['worker_slot'] = $slot; $state['updated_at'] = gmdate('c');
        $this->writeJson($this->path($queue, 'state', $slot), $state);
    }

    /** @return array<string,mixed> */
    public function workerForId(string $queue, string $workerId): array
    {
        $workerId = trim($workerId);
        if ($workerId === '') return [];
        foreach ((array)($this->status($queue)['workers'] ?? []) as $worker) {
            $state = is_array($worker['state'] ?? null) ? $worker['state'] : [];
            $candidate = trim((string)($state['worker_id'] ?? ''));
            if ($candidate !== '' && hash_equals($candidate, $workerId)) return is_array($worker) ? $worker : [];
        }
        return [];
    }

    private function lockHeld(string $queue, int $slot): bool
    {
        $handle = fopen($this->path($queue, 'lock', $slot), 'c+');
        if (!is_resource($handle)) return false;
        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) flock($handle, LOCK_UN);
        fclose($handle);
        return !$acquired;
    }

    /** @param list<string> $arguments */
    private function spawn(string $php, string $script, array $arguments, string $log): void
    {
        $parts = [escapeshellarg($php), escapeshellarg($script)];
        foreach ($arguments as $argument) $parts[] = escapeshellarg($argument);
        $program = implode(' ', $parts);
        $command = PHP_OS_FAMILY === 'Windows'
            ? 'start "" /B ' . $program . ' >> ' . escapeshellarg($log) . ' 2>&1'
            : 'nohup ' . $program . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
        $handle = @popen($command, 'r');
        if (!is_resource($handle)) throw new \RuntimeException('Could not launch the detached worker process.');
        pclose($handle);
    }

    private function phpBinary(): string
    {
        $value = trim((string)($this->config['queue']['worker_php_binary'] ?? ''));
        if ($value === '') {
            $value = trim((string)(getenv('UNREALDB_WORKER_PHP_BINARY') ?: ''));
        }
        if ($value !== '') {
            return $value;
        }

        $executable = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $candidates = [];
        $loadedIni = php_ini_loaded_file();
        if (is_string($loadedIni) && $loadedIni !== '') {
            $candidates[] = dirname($loadedIni) . DIRECTORY_SEPARATOR . $executable;
        }
        $extensionDir = trim((string)ini_get('extension_dir'));
        if ($extensionDir !== '') {
            $candidates[] = dirname(rtrim($extensionDir, '/\\')) . DIRECTORY_SEPARATOR . $executable;
        }
        $candidates[] = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $executable;
        if (is_file(PHP_BINARY) && preg_match('/^php(?:\.exe)?$/i', basename(PHP_BINARY)) === 1) {
            $candidates[] = PHP_BINARY;
        }
        $path = (string)(getenv('PATH') ?: '');
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory !== '') {
                $candidates[] = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $executable;
            }
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file($candidate)) {
                $resolved = realpath($candidate);
                return is_string($resolved) && $resolved !== '' ? $resolved : $candidate;
            }
        }
        return $executable;
    }

    private function assertPhpBinary(string $php): void
    {
        $hasPath = str_contains($php, '/') || str_contains($php, '\\') || preg_match('/^[A-Za-z]:/', $php) === 1;
        if ($hasPath && !is_file($php)) {
            throw new \RuntimeException(
                'Configured detached-worker PHP binary does not exist: ' . $php
                . '. Update queue.worker_php_binary in catalog/config.php.'
            );
        }
        if (PHP_OS_FAMILY === 'Windows' && !$hasPath) {
            throw new \RuntimeException(
                'Could not resolve the PHP CLI executable for detached workers. Set queue.worker_php_binary '
                . 'in catalog/config.php, for example D:/php8.5/php.exe.'
            );
        }
    }

    private function queue(string $queue): string
    {
        $queue = trim($queue);
        if ($queue === '' || strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1) {
            throw new \InvalidArgumentException('A valid queue name is required.');
        }
        return $queue;
    }

    private function slot(int $slot): int
    {
        if ($slot < 1 || $slot > self::MAX_WORKERS) throw new \InvalidArgumentException('Worker slot must be between 1 and ' . self::MAX_WORKERS . '.');
        return $slot;
    }

    private function ensureRuntime(): void
    {
        if (!is_dir($this->runtime) && !mkdir($this->runtime, 0750, true) && !is_dir($this->runtime)) {
            throw new \RuntimeException('Could not create detached worker runtime storage.');
        }
    }

    private function key(string $queue): string { return preg_replace('/[^A-Za-z0-9._-]+/', '_', $queue) ?: 'catalog'; }

    private function path(string $queue, string $kind, int $slot = 0): string
    {
        $base = $this->runtime . DIRECTORY_SEPARATOR . $this->key($queue);
        if (in_array($kind, ['lock', 'state', 'log', 'slot-stop'], true)) {
            $slot = $this->slot($slot);
            $base .= $slot === 1 ? '' : '.worker-' . $slot;
        }
        return $base . match ($kind) {
            'lock' => '.lock', 'state' => '.state.json', 'log' => '.log',
            'queue-stop' => '.stop.json', 'slot-stop' => '.slot-stop.json', 'pool' => '.pool.json',
            default => throw new \InvalidArgumentException('Unknown detached worker runtime path.'),
        };
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $this->ensureRuntime();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temporary, $json, LOCK_EX) === false) throw new \RuntimeException('Could not write detached worker runtime state.');
        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) @unlink($path);
        if (!@rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('Could not publish detached worker runtime state.'); }
    }
}
