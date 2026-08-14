<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

/** Filesystem state/lock/log storage for the host-local detached worker pool. */
final class CatalogWorkerRuntimeStateStore
{
    public function __construct(
        private readonly string $runtime,
        private readonly int $maxWorkers
    ) {
        if (trim($runtime) === '') {
            throw new \InvalidArgumentException('Detached worker runtime path is required.');
        }
    }

    public function queue(string $queue): string
    {
        $queue = trim($queue);
        if ($queue === '' || strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1) {
            throw new \InvalidArgumentException('A valid queue name is required.');
        }
        return $queue;
    }

    public function slot(int $slot): int
    {
        if ($slot < 1 || $slot > $this->maxWorkers) {
            throw new \InvalidArgumentException('Worker slot must be between 1 and ' . $this->maxWorkers . '.');
        }
        return $slot;
    }

    public function ensureRuntime(): void
    {
        if (!is_dir($this->runtime) && !mkdir($this->runtime, 0750, true) && !is_dir($this->runtime)) {
            throw new \RuntimeException('Could not create detached worker runtime storage.');
        }
    }

    public function key(string $queue): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->queue($queue)) ?: 'catalog';
    }

    public function path(string $queue, string $kind, int $slot = 0): string
    {
        $queue = $this->queue($queue);
        $base = $this->runtime . DIRECTORY_SEPARATOR . $this->key($queue);
        if (in_array($kind, ['lock', 'state', 'log', 'slot-stop'], true)) {
            $slot = $this->slot($slot);
            $base .= $slot === 1 ? '' : '.worker-' . $slot;
        }
        return $base . match ($kind) {
            'lock' => '.lock',
            'state' => '.state.json',
            'log' => '.log',
            'queue-stop' => '.stop.json',
            'slot-stop' => '.slot-stop.json',
            'pool' => '.pool.json',
            default => throw new \InvalidArgumentException('Unknown detached worker runtime path.'),
        };
    }

    /** @return array<string,mixed> */
    public function readJson(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    public function writeJson(string $path, array $value): void
    {
        $this->ensureRuntime();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write detached worker runtime state.');
        }
        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not publish detached worker runtime state.');
        }
    }

    /** @return resource|false */
    public function acquireWorkerLock(string $queue, int $slot = 1)
    {
        $queue = $this->queue($queue);
        $slot = $this->slot($slot);
        $this->ensureRuntime();
        $handle = fopen($this->path($queue, 'lock', $slot), 'c+');
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

    public function lockHeld(string $queue, int $slot): bool
    {
        $handle = fopen($this->path($queue, 'lock', $slot), 'c+');
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

    public function tailLog(string $queue, int $maximumBytes = 16384, int $slot = 1): string
    {
        $path = $this->path($queue, 'log', $slot);
        $maximumBytes = max(1024, min(131072, $maximumBytes));
        $parts = [];
        foreach ([['stdout', $path], ['stderr', $path . '.error.log']] as [$label, $candidate]) {
            $tail = $this->tailFile($candidate, $maximumBytes);
            if ($tail !== '') {
                $parts[] = '[' . $label . '] ' . $tail;
            }
        }
        return implode("\n", $parts);
    }

    private function tailFile(string $path, int $maximumBytes): string
    {
        if (!is_file($path) || !is_readable($path) || ($size = filesize($path)) === false || $size < 1) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            return '';
        }
        try {
            $offset = max(0, $size - $maximumBytes);
            if ($offset > 0) {
                fseek($handle, $offset);
                fgets($handle);
            }
            $data = stream_get_contents($handle);
            return is_string($data) ? trim($data) : '';
        } finally {
            fclose($handle);
        }
    }
}
