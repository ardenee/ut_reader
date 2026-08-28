<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogChunkedUploadCleanup` for catalog chunked upload cleanup.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogChunkedUploadCleanup
{
    private string $root;
    private int $staleSeconds;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for chunked upload cleanup.');
        }
        $chunkConfig = is_array($config['chunk_upload'] ?? null) ? $config['chunk_upload'] : [];
        $this->root = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'chunked-uploads';
        $this->staleSeconds = max(1, min((int)($chunkConfig['stale_hours'] ?? 168), 24 * 90)) * 3600;
    }

    public function delete(string $uploadId): bool
    {
        return $this->deleteWithStats($uploadId)['deleted'];
    }

    /** @return array{deleted:bool,bytes:int} */
    public function deleteWithStats(string $uploadId): array
    {
        $directory = $this->directory($uploadId);
        if (!is_dir($directory)) {
            return ['deleted' => false, 'bytes' => 0];
        }

        $bytes = $this->directoryBytes($directory);
        $lock = $this->lock($directory);
        try {
            @unlink($directory . DIRECTORY_SEPARATOR . 'payload.part');
            @unlink($directory . DIRECTORY_SEPARATOR . 'manifest.json');
        } finally {
            $this->unlock($lock);
        }
        @unlink($directory . DIRECTORY_SEPARATOR . '.lock');
        @rmdir($directory);
        @rmdir(dirname($directory));
        $deleted = !is_dir($directory);
        return ['deleted' => $deleted, 'bytes' => $deleted ? $bytes : 0];
    }

    /** @return array{uploads:int,bytes:int} */
    public function pruneIncomplete(): array
    {
        if (!is_dir($this->root)) {
            return ['uploads' => 0, 'bytes' => 0];
        }
        $threshold = time() - $this->staleSeconds;
        $uploads = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isDir()) {
                continue;
            }
            $uploadId = $entry->getFilename();
            if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
                continue;
            }
            $manifestPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'manifest.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $modified = (int)(filemtime($manifestPath) ?: 0);
            if ($modified >= $threshold) {
                continue;
            }
            $manifest = $this->manifest($manifestPath);
            if ((string)($manifest['status'] ?? 'uploading') === 'complete') {
                // This narrow helper only handles abandoned incomplete uploads.
                // Completed/orphaned stores are reclaimed by CatalogJobStorageCleanup,
                // which can check database ownership before deleting them.
                continue;
            }
            $dataPath = $entry->getPathname() . DIRECTORY_SEPARATOR . 'payload.part';
            $size = is_file($dataPath) ? (int)(filesize($dataPath) ?: 0) : 0;
            if ($this->delete($uploadId)) {
                $uploads++;
                $bytes += $size;
            }
        }
        return ['uploads' => $uploads, 'bytes' => $bytes];
    }

    private function directoryBytes(string $directory): int
    {
        $bytes = 0;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $bytes += max(0, (int)$entry->getSize());
        }
        return $bytes;
    }

    /** @return array<string,mixed> */
    private function manifest(string $path): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function directory(string $uploadId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Chunked upload identifier is invalid.');
        }
        return $this->root . DIRECTORY_SEPARATOR . substr($uploadId, 0, 2) . DIRECTORY_SEPARATOR . $uploadId;
    }

    /** @return resource */
    private function lock(string $directory)
    {
        $handle = fopen($directory . DIRECTORY_SEPARATOR . '.lock', 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Could not lock chunked upload cleanup state.');
        }
        return $handle;
    }

    /** @param resource $handle */
    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
