<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogDetachedWorker
{
    private string $storageRoot;
    private string $runtimeDirectory;
    private string $catalogRoot;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required.');
        }
        $this->storageRoot = $storageRoot;
        $this->runtimeDirectory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'worker';
        $this->catalogRoot = dirname(__DIR__, 3);
    }

    /** @return array<string,mixed> */
    public function start(string $queueName, int $maxJobs = 10000): array
    {
        $queueName = $this->queueName($queueName);
        $maxJobs = max(1, min($maxJobs, 10000));
        $this->ensureRuntimeDirectory();

        $status = $this->status($queueName);
        if (!empty($status['active'])) {
            return ['started' => false, 'reason' => 'already_running', 'worker' => $status];
        }

        $this->clearStopRequest($queueName);
        $leaseSeconds = max(15, min((int)($this->config['queue']['lease_seconds'] ?? 120), 3600));
        $phpBinary = $this->phpBinary();
        $script = $this->catalogRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'catalog-worker-detached.php';
        if (!is_file($script)) {
            throw new \RuntimeException('Detached worker script is missing.');
        }

        $arguments = [
            '--queue=' . $queueName,
            '--max-jobs=' . $maxJobs,
            '--sleep-ms=250',
            '--lease-seconds=' . $leaseSeconds,
        ];
        $logPath = $this->logPath($queueName);
        $this->writeState($queueName, [
            'status' => 'launching',
            'queue' => $queueName,
            'max_jobs' => $maxJobs,
            'requested_at' => gmdate('c'),
        ]);

        $this->spawn($phpBinary, $script, $arguments, $logPath);
        usleep(100000);

        return [
            'started' => true,
            'reason' => 'launched',
            'worker' => $this->status($queueName),
        ];
    }

    /** @return array<string,mixed> */
    public function status(string $queueName): array
    {
        $queueName = $this->queueName($queueName);
        $this->ensureRuntimeDirectory();
        $state = $this->readJson($this->statePath($queueName));
        $active = $this->lockIsHeld($queueName);

        if (!$active && (string)($state['status'] ?? '') === 'launching') {
            $requestedAt = strtotime((string)($state['requested_at'] ?? ''));
            if ($requestedAt !== false && $requestedAt >= time() - 10) {
                $active = true;
            }
        }

        return [
            'active' => $active,
            'queue' => $queueName,
            'stop_requested' => is_file($this->stopPath($queueName)),
            'state' => $state,
            'log_file' => basename($this->logPath($queueName)),
        ];
    }

    /** @return array<string,mixed> */
    public function requestStop(string $queueName): array
    {
        $queueName = $this->queueName($queueName);
        $this->ensureRuntimeDirectory();
        $this->writeJson($this->stopPath($queueName), [
            'queue' => $queueName,
            'requested_at' => gmdate('c'),
        ]);
        return $this->status($queueName);
    }

    public function stopRequested(string $queueName): bool
    {
        return is_file($this->stopPath($this->queueName($queueName)));
    }

    public function clearStopRequest(string $queueName): void
    {
        @unlink($this->stopPath($this->queueName($queueName)));
    }

    /** @return resource|false */
    public function acquireWorkerLock(string $queueName)
    {
        $queueName = $this->queueName($queueName);
        $this->ensureRuntimeDirectory();
        $handle = fopen($this->lockPath($queueName), 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not open the detached worker lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);
        return $handle;
    }

    /** @param array<string,mixed> $state */
    public function writeState(string $queueName, array $state): void
    {
        $queueName = $this->queueName($queueName);
        $state['updated_at'] = gmdate('c');
        $this->writeJson($this->statePath($queueName), $state);
    }

    private function lockIsHeld(string $queueName): bool
    {
        $handle = fopen($this->lockPath($queueName), 'c+');
        if (!is_resource($handle)) {
            return false;
        }
        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$acquired;
    }

    /** @param list<string> $arguments */
    private function spawn(string $phpBinary, string $script, array $arguments, string $logPath): void
    {
        $parts = [escapeshellarg($phpBinary), escapeshellarg($script)];
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        $program = implode(' ', $parts);

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B ' . $program . ' >> ' . escapeshellarg($logPath) . ' 2>&1';
            $handle = @popen($command, 'r');
            if (!is_resource($handle)) {
                throw new \RuntimeException('Could not launch the detached Windows worker process.');
            }
            pclose($handle);
            return;
        }

        $command = 'nohup ' . $program . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null &';
        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not launch the detached worker process.');
        }
        pclose($handle);
    }

    private function phpBinary(): string
    {
        $configured = trim((string)($this->config['queue']['worker_php_binary'] ?? ''));
        if ($configured === '') {
            $configured = trim((string)(getenv('UNREALDB_WORKER_PHP_BINARY') ?: ''));
        }
        if ($configured !== '') {
            return $configured;
        }

        $candidate = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (is_file($candidate)) {
            return $candidate;
        }

        if (is_file(PHP_BINARY) && preg_match('/^php(?:\.exe)?$/i', basename(PHP_BINARY)) === 1) {
            return PHP_BINARY;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    }

    private function queueName(string $queueName): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \InvalidArgumentException('A valid queue name is required.');
        }
        return $queueName;
    }

    private function ensureRuntimeDirectory(): void
    {
        if (!is_dir($this->runtimeDirectory)
            && !mkdir($this->runtimeDirectory, 0750, true)
            && !is_dir($this->runtimeDirectory)) {
            throw new \RuntimeException('Could not create detached worker runtime storage.');
        }
    }

    private function queueKey(string $queueName): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $queueName) ?: 'catalog';
    }

    private function lockPath(string $queueName): string
    {
        return $this->runtimeDirectory . DIRECTORY_SEPARATOR . $this->queueKey($queueName) . '.lock';
    }

    private function statePath(string $queueName): string
    {
        return $this->runtimeDirectory . DIRECTORY_SEPARATOR . $this->queueKey($queueName) . '.state.json';
    }

    private function stopPath(string $queueName): string
    {
        return $this->runtimeDirectory . DIRECTORY_SEPARATOR . $this->queueKey($queueName) . '.stop.json';
    }

    private function logPath(string $queueName): string
    {
        return $this->runtimeDirectory . DIRECTORY_SEPARATOR . $this->queueKey($queueName) . '.log';
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not update detached worker state.');
        }
        @chmod($path, 0640);
    }
}
