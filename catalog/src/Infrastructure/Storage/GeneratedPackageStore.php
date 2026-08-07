<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `GeneratedPackageStore` for generated package store.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

final class GeneratedPackageStore
{
    private string $directory;

    public function __construct(string $storageRoot)
    {
        $storageRoot = rtrim($storageRoot, DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A package storage root is required.');
        }
        $this->directory = $storageRoot . DIRECTORY_SEPARATOR . 'generated-packages';
    }

    public function retentionSeconds(): int
    {
        $raw = getenv('UNREALDB_GENERATED_PACKAGE_RETENTION_SECONDS');
        $value = $raw !== false ? filter_var($raw, FILTER_VALIDATE_INT) : false;
        return $value === false ? 86400 : max(900, min((int)$value, 604800));
    }

    public function temporaryPath(int $jobId, string $extension): string
    {
        $this->ensureDirectory();
        $extension = $this->extension($extension);
        return $this->directory . DIRECTORY_SEPARATOR
            . '.job-' . max(1, $jobId) . '-' . bin2hex(random_bytes(8)) . '.' . $extension . '.part';
    }

    /** @return array{artifact_name:string,path:string,size:int,sha256:string,expires_at:string} */
    public function publish(string $temporaryPath, int $jobId, string $extension): array
    {
        $this->ensureDirectory();
        if (!is_file($temporaryPath) || !$this->inside($temporaryPath)) {
            throw new \RuntimeException('Generated package temporary output is unavailable.');
        }

        $sha256 = hash_file('sha256', $temporaryPath);
        $size = filesize($temporaryPath);
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || $size === false) {
            throw new \RuntimeException('Could not finalize generated package identity.');
        }

        $artifactName = max(1, $jobId) . '-' . substr($sha256, 0, 20) . '.' . $this->extension($extension);
        $destination = $this->directory . DIRECTORY_SEPARATOR . $artifactName;
        if (is_file($destination) && !@unlink($destination)) {
            throw new \RuntimeException('Could not replace a previous generated package artifact.');
        }
        if (!@rename($temporaryPath, $destination)) {
            throw new \RuntimeException('Could not publish the generated package artifact.');
        }
        @chmod($destination, 0640);

        return [
            'artifact_name' => $artifactName,
            'path' => $destination,
            'size' => (int)$size,
            'sha256' => $sha256,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $this->retentionSeconds()),
        ];
    }

    public function resolve(string $artifactName): ?string
    {
        if ($artifactName === '' || basename($artifactName) !== $artifactName || str_starts_with($artifactName, '.')) {
            return null;
        }
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . $artifactName;
        return is_file($path) && $this->inside($path) ? $path : null;
    }

    public function delete(string $path): void
    {
        if (is_file($path) && $this->inside($path) && !@unlink($path)) {
            error_log('[UnrealDB packages] Could not remove generated package file: ' . basename($path));
        }
    }

    /** @return array{temporary:int,artifacts:int} */
    public function prune(): array
    {
        $this->ensureDirectory();
        $now = time();
        $temporary = 0;
        $artifacts = 0;
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->directory . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $modified = (int)(filemtime($path) ?: 0);
            $maxAge = str_ends_with($name, '.part') ? 7200 : $this->retentionSeconds();
            if ($modified > 0 && $modified < $now - $maxAge && @unlink($path)) {
                if (str_ends_with($name, '.part')) {
                    $temporary++;
                } else {
                    $artifacts++;
                }
            }
        }
        return ['temporary' => $temporary, 'artifacts' => $artifacts];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create generated package storage.');
        }
    }

    private function inside(string $path): bool
    {
        $directory = realpath($this->directory);
        $parent = realpath(dirname($path));
        return $directory !== false && $parent !== false && hash_equals($directory, $parent);
    }

    private function extension(string $extension): string
    {
        $extension = strtolower(trim($extension, '. '));
        return preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1 ? $extension : 'bin';
    }
}
