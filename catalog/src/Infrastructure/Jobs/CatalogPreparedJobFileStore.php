<?php
/**
 * Durable per-job prepared-file workspace.
 *
 * Long-running jobs may have an expensive preparation phase (for example
 * redirect decompression) whose output is safe to reuse across worker/process
 * retries. This store publishes that output atomically under catalog storage and
 * keeps a small metadata manifest beside it. The workspace is removed only when
 * the owning job reaches a completed terminal result or stale-artifact cleanup
 * proves the job no longer needs it.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogPreparedJobFileStore
{
    private string $directory;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, int $jobId, string $slot)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for prepared job files.');
        }
        if ($jobId < 1) {
            throw new \InvalidArgumentException('A positive job id is required for prepared job files.');
        }
        $slot = strtolower(trim($slot));
        if ($slot === '' || preg_match('/^[a-z0-9._-]{1,64}$/', $slot) !== 1) {
            throw new \InvalidArgumentException('Prepared job-file slot is invalid.');
        }
        $this->directory = $storageRoot
            . DIRECTORY_SEPARATOR . 'jobs'
            . DIRECTORY_SEPARATOR . 'prepared'
            . DIRECTORY_SEPARATOR . 'job-' . $jobId
            . DIRECTORY_SEPARATOR . $slot;
    }

    /**
     * Publish a complete prepared file into durable controlled storage.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function publish(string $sourcePath, string $logicalName, array $metadata = []): array
    {
        if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Prepared job source file is unavailable.');
        }
        $logicalName = $this->logicalName($logicalName);
        $this->ensureDirectory();

        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($logicalName, PATHINFO_EXTENSION));
        $filename = 'prepared' . ($extension !== '' ? '.' . strtolower($extension) : '.bin');
        $destination = $this->directory . DIRECTORY_SEPARATOR . $filename;
        $part = $destination . '.part-' . bin2hex(random_bytes(6));
        $sourceSize = filesize($sourcePath);
        if ($sourceSize === false || (int)$sourceSize < 1) {
            throw new \RuntimeException('Prepared job source file is empty.');
        }

        try {
            if (!@rename($sourcePath, $part)) {
                if (!@copy($sourcePath, $part)) {
                    throw new \RuntimeException('Could not persist prepared job file.');
                }
                $partSize = filesize($part);
                if ($partSize === false || (int)$partSize !== (int)$sourceSize) {
                    throw new \RuntimeException('Prepared job-file copy is incomplete.');
                }
                @unlink($sourcePath);
            }
            @chmod($part, 0640);
            if (!@rename($part, $destination)) {
                throw new \RuntimeException('Could not publish prepared job file.');
            }

            clearstatcache(true, $destination);
            $size = filesize($destination);
            if ($size === false || (int)$size !== (int)$sourceSize) {
                throw new \RuntimeException('Published prepared job-file size is invalid.');
            }

            $state = $metadata + [
                'logical_name' => $logicalName,
                'filename' => $filename,
                'size' => (int)$size,
                'published_at' => gmdate('c'),
            ];
            $this->writeState($state);
            return ['path' => $destination] + $state;
        } catch (\Throwable $error) {
            @unlink($part);
            if (!is_file($this->statePath())) {
                @unlink($destination);
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function load(): ?array
    {
        $state = $this->readState();
        if ($state === []) {
            return null;
        }
        $filename = trim((string)($state['filename'] ?? ''));
        if ($filename === '' || basename($filename) !== $filename) {
            return null;
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        $real = realpath($path);
        $root = realpath($this->directory);
        if ($real === false || $root === false || !is_file($real) || !is_readable($real) || is_link($real)) {
            return null;
        }
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedReal = str_replace('\\', '/', $real);
        if (!str_starts_with($normalizedReal, $normalizedRoot)) {
            return null;
        }
        clearstatcache(true, $real);
        $size = filesize($real);
        if ($size === false || (int)$size < 1 || (int)$size !== (int)($state['size'] ?? 0)) {
            return null;
        }
        return ['path' => $real] + $state;
    }

    public function clear(): void
    {
        $this->deleteTree($this->directory);
        $parent = dirname($this->directory);
        @rmdir($parent);
        @rmdir(dirname($parent));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)
            && !@mkdir($this->directory, 0750, true)
            && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create prepared job-file storage.');
        }
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Could not persist prepared job-file metadata.');
        }
        @chmod($path, 0640);
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function statePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'state.json';
    }

    private function logicalName(string $name): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'prepared.bin';
    }

    private function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
