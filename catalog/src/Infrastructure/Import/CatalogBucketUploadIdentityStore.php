<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

/**
 * Persists browser-calculated source hashes beside the resumable upload.
 * The sidecar is removed automatically when the chunk-upload directory is
 * deleted, so failed/retried jobs retain it while successful jobs do not.
 */
final class CatalogBucketUploadIdentityStore
{
    private string $root;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for upload identities.');
        }
        $this->root = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'chunked-uploads';
    }

    /** @return array<string,mixed> */
    public function save(
        string $uploadId,
        int $userId,
        int $fileSize,
        string $md5,
        string $sha1,
        string $originalName,
        string $relativePath,
        bool $redirectWrapper
    ): array {
        $uploadId = $this->uploadId($uploadId);
        if ($userId < 1 || $fileSize < 1) {
            throw new \InvalidArgumentException('Upload identity metadata is incomplete.');
        }
        $md5 = strtolower(trim($md5));
        $sha1 = strtolower(trim($sha1));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \InvalidArgumentException('Upload MD5 or SHA-1 is invalid.');
        }

        $directory = $this->directory($uploadId);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Resumable upload storage is missing before identity publication.');
        }

        $identity = [
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'file_size' => $fileSize,
            'md5' => $md5,
            'sha1' => $sha1,
            'original_name' => trim($originalName),
            'relative_path' => trim($relativePath),
            'redirect_wrapper' => $redirectWrapper,
            'created_at' => gmdate(DATE_ATOM),
        ];
        $this->write($directory, $identity);
        return $identity;
    }

    /** @return array<string,mixed> */
    public function load(string $uploadId, ?int $userId = null): array
    {
        $uploadId = $this->uploadId($uploadId);
        $path = $this->path($this->directory($uploadId));
        if (!is_file($path)) {
            throw new \RuntimeException('Upload hash identity is missing. Re-select the source file and retry the upload.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new \RuntimeException('Upload hash identity could not be read.');
        }
        try {
            $identity = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Upload hash identity is corrupt.', 0, $error);
        }
        if (!is_array($identity)
            || !hash_equals($uploadId, (string)($identity['upload_id'] ?? ''))
            || preg_match('/^[a-f0-9]{32}$/', (string)($identity['md5'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{40}$/', (string)($identity['sha1'] ?? '')) !== 1) {
            throw new \RuntimeException('Upload hash identity is invalid.');
        }
        if ($userId !== null && $userId > 0 && (int)($identity['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Upload hash identity belongs to a different administrator.');
        }
        return $identity;
    }

    private function write(string $directory, array $identity): void
    {
        $path = $this->path($directory);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
                throw new \RuntimeException('Could not publish Upload Bucket hash identity.');
            }
            @chmod($path, 0640);
        } finally {
            @unlink($temporary);
        }
    }

    private function uploadId(string $uploadId): string
    {
        $uploadId = strtolower(trim($uploadId));
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Chunked upload identifier is invalid.');
        }
        return $uploadId;
    }

    private function directory(string $uploadId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . substr($uploadId, 0, 2) . DIRECTORY_SEPARATOR . $uploadId;
    }

    private function path(string $directory): string
    {
        return $directory . DIRECTORY_SEPARATOR . 'identity.json';
    }
}
