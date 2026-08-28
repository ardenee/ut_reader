<?php
/**
 * Filesystem-backed manifest for one browser profiled-upload batch.
 *
 * Browser ingress must not create one database job per uploaded file. Completed
 * files are staged durably first and appended to this manifest. Only after the
 * browser finishes the whole selection is the manifest sealed and represented
 * by one background coordinator job.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogProfiledUploadBatchStore
{
    private string $directory;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required.');
        }
        $this->directory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'profiled-upload-batches';
    }

    /**
     * @param list<string> $allowedExtensions
     * @return array<string,mixed>
     */
    public function create(
        int $userId,
        int $gameId,
        bool $strictProfile,
        string $engineKey,
        array $allowedExtensions = [],
        int $normalUploadLimitBytes = 0,
        int $containerUploadLimitBytes = 0
    ): array {
        if ($userId < 1 || $gameId < 1) {
            throw new \InvalidArgumentException('Upload batch requires an administrator and target game.');
        }
        $this->ensureDirectory();

        do {
            $batchId = bin2hex(random_bytes(32));
        } while (is_file($this->metadataPath($batchId)) || is_file($this->manifestPath($batchId)));

        $extensions = [];
        foreach ($allowedExtensions as $extension) {
            $extension = strtolower(trim((string)$extension));
            $extension = ltrim($extension, '.');
            if ($extension !== '' && preg_match('/^[a-z0-9_]+$/', $extension) === 1) {
                $extensions[$extension] = $extension;
            }
        }
        $extensions = array_values($extensions);
        sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);

        $normalUploadLimitBytes = max(1, $normalUploadLimitBytes > 0
            ? $normalUploadLimitBytes
            : (int)($this->config['max_upload_bytes'] ?? (256 * 1024 * 1024)));
        $containerUploadLimitBytes = max(
            $normalUploadLimitBytes,
            $containerUploadLimitBytes > 0
                ? $containerUploadLimitBytes
                : (int)($this->config['max_container_upload_bytes'] ?? (64 * 1024 * 1024 * 1024))
        );

        $now = gmdate('Y-m-d H:i:s');
        $metadata = [
            'batch_id' => $batchId,
            'user_id' => $userId,
            'game_id' => $gameId,
            'strict_profile' => $strictProfile,
            'engine_key' => strtoupper(trim($engineKey)),
            'allowed_extensions' => $extensions,
            'normal_upload_limit_bytes' => $normalUploadLimitBytes,
            'container_upload_limit_bytes' => $containerUploadLimitBytes,
            'status' => 'uploading',
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
            'cancelled_at' => null,
            'item_count' => 0,
            'byte_count' => 0,
        ];

        $this->writeMetadata($metadata);
        $manifest = fopen($this->manifestPath($batchId), 'xb');
        if (!is_resource($manifest)) {
            @unlink($this->metadataPath($batchId));
            throw new \RuntimeException('Could not create upload batch manifest.');
        }
        fclose($manifest);
        @chmod($this->metadataPath($batchId), 0640);
        @chmod($this->manifestPath($batchId), 0640);

        return $metadata;
    }

    /** @return array<string,mixed> */
    public function info(string $batchId, ?int $userId = null): array
    {
        $metadata = $this->readMetadata($batchId);
        if ($userId !== null && $userId > 0 && (int)($metadata['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Upload batch belongs to another administrator.');
        }
        return $metadata;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    public function append(int $userId, string $batchId, array $item): array
    {
        return $this->withLock($batchId, function () use ($userId, $batchId, $item): array {
            $metadata = $this->readMetadata($batchId);
            $this->requireOwner($metadata, $userId);
            if ((string)($metadata['status'] ?? '') !== 'uploading') {
                throw new \RuntimeException('Upload batch is no longer accepting files.');
            }

            $item = $this->normalizeItem($item, $metadata);
            $line = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $handle = fopen($this->manifestPath($batchId), 'ab');
            if (!is_resource($handle)) {
                throw new \RuntimeException('Could not open upload batch manifest.');
            }
            try {
                $written = fwrite($handle, $line);
                if ($written === false || $written !== strlen($line)) {
                    throw new \RuntimeException('Could not append upload batch manifest.');
                }
                fflush($handle);
            } finally {
                fclose($handle);
            }
            return $item;
        });
    }

    /** @return array<string,mixed> */
    public function finalize(int $userId, string $batchId): array
    {
        return $this->withLock($batchId, function () use ($userId, $batchId): array {
            $metadata = $this->readMetadata($batchId);
            $this->requireOwner($metadata, $userId);
            $status = (string)($metadata['status'] ?? '');
            if ($status === 'completed') {
                return $metadata;
            }
            if ($status !== 'uploading') {
                throw new \RuntimeException('Cancelled upload batch cannot be finalized.');
            }

            $stats = $this->manifestStats($batchId, $metadata);
            $metadata['status'] = 'completed';
            $metadata['item_count'] = $stats['item_count'];
            $metadata['byte_count'] = $stats['byte_count'];
            $metadata['completed_at'] = gmdate('Y-m-d H:i:s');
            $metadata['updated_at'] = $metadata['completed_at'];
            $this->writeMetadata($metadata);
            return $metadata;
        });
    }

    /** @return array<string,mixed> */
    public function cancel(int $userId, string $batchId): array
    {
        return $this->withLock($batchId, function () use ($userId, $batchId): array {
            $metadata = $this->readMetadata($batchId);
            $this->requireOwner($metadata, $userId);
            if ((string)($metadata['status'] ?? '') === 'completed') {
                throw new \RuntimeException('Completed upload batch cannot be cancelled.');
            }
            if ((string)($metadata['status'] ?? '') !== 'cancelled') {
                $metadata['status'] = 'cancelled';
                $metadata['cancelled_at'] = gmdate('Y-m-d H:i:s');
                $metadata['updated_at'] = $metadata['cancelled_at'];
                $this->writeMetadata($metadata);
            }
            return $metadata;
        });
    }

    /**
     * @return array{items:list<array<string,mixed>>,next_offset:int,eof:bool}
     */
    public function readSlice(string $batchId, int $offset, int $limit): array
    {
        $metadata = $this->readMetadata($batchId);
        if ((string)($metadata['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('Upload batch manifest is not finalized.');
        }
        $offset = max(0, $offset);
        $limit = max(1, min($limit, 1000));
        $path = $this->manifestPath($batchId);
        $size = filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Upload batch manifest is unavailable.');
        }
        if ($offset > (int)$size) {
            throw new \RuntimeException('Upload batch manifest offset is invalid.');
        }

        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not read upload batch manifest.');
        }
        $items = [];
        try {
            if ($offset > 0 && fseek($handle, $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Could not seek upload batch manifest.');
            }
            while (count($items) < $limit && ($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Upload batch manifest contains an invalid item.');
                }
                $items[] = $this->normalizeItem($decoded, $metadata);
            }
            $position = ftell($handle);
            if ($position === false) {
                throw new \RuntimeException('Could not determine upload batch manifest position.');
            }
            return [
                'items' => $items,
                'next_offset' => (int)$position,
                'eof' => (int)$position >= (int)$size,
            ];
        } finally {
            fclose($handle);
        }
    }

    /** @return array{item_count:int,byte_count:int} */
    private function manifestStats(string $batchId, array $metadata): array
    {
        $handle = fopen($this->manifestPath($batchId), 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not inspect upload batch manifest.');
        }
        $count = 0;
        $bytes = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $decoded = json_decode(trim($line), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Upload batch manifest contains an invalid item.');
                }
                $item = $this->normalizeItem($decoded, $metadata);
                $count++;
                $bytes += (int)$item['size'];
            }
        } finally {
            fclose($handle);
        }
        return ['item_count' => $count, 'byte_count' => $bytes];
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $metadata @return array<string,mixed> */
    private function normalizeItem(array $item, array $metadata): array
    {
        $kind = strtolower(trim((string)($item['kind'] ?? 'package')));
        if (!in_array($kind, ['package', 'pak'], true)) {
            throw new \InvalidArgumentException('Upload batch item kind is invalid.');
        }
        $stagedPath = trim((string)($item['staged_path'] ?? ''));
        if ($stagedPath === ''
            || (!str_starts_with(str_replace('\\', '/', $stagedPath), 'jobs/incoming/')
                && preg_match('/^chunk-upload:[a-f0-9]{64}$/', $stagedPath) !== 1)) {
            throw new \InvalidArgumentException('Upload batch staged path is invalid.');
        }
        $originalName = CatalogImportPathPolicy::filename((string)($item['original_name'] ?? ''));
        $sourceRelativePath = CatalogImportPathPolicy::relative(
            (string)($item['source_relative_path'] ?? $originalName)
        );
        $size = (int)($item['size'] ?? 0);
        if ($size < 1) {
            throw new \InvalidArgumentException('Upload batch item size is invalid.');
        }
        $gameId = (int)($item['game_id'] ?? $metadata['game_id'] ?? 0);
        if ($gameId !== (int)($metadata['game_id'] ?? 0)) {
            throw new \InvalidArgumentException('Upload batch item targets another game.');
        }

        return [
            'kind' => $kind,
            'staged_path' => str_replace('\\', '/', $stagedPath),
            'original_name' => $originalName,
            'source_relative_path' => $sourceRelativePath,
            'size' => $size,
            'game_id' => $gameId,
            'strict_profile' => (bool)($metadata['strict_profile'] ?? true),
        ];
    }

    /**
     * Remove a finalized/cancelled batch manifest after its database workflow has
     * taken ownership of every staged file. Idempotent for crash recovery.
     */
    public function delete(string $batchId): void
    {
        $batchId = $this->batchId($batchId);
        foreach ([
            $this->metadataPath($batchId),
            $this->manifestPath($batchId),
            $this->lockPath($batchId),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->directory);
    }

    /** @return array<string,mixed> */
    private function readMetadata(string $batchId): array
    {
        $batchId = $this->batchId($batchId);
        $raw = @file_get_contents($this->metadataPath($batchId));
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Upload batch is unavailable.');
        }
        $metadata = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || (string)($metadata['batch_id'] ?? '') !== $batchId) {
            throw new \RuntimeException('Upload batch metadata is invalid.');
        }
        return $metadata;
    }

    /** @param array<string,mixed> $metadata */
    private function writeMetadata(array $metadata): void
    {
        $batchId = $this->batchId((string)($metadata['batch_id'] ?? ''));
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->metadataPath($batchId), $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Could not persist upload batch metadata.');
        }
    }

    /** @template T @param callable():T $operation @return T */
    private function withLock(string $batchId, callable $operation): mixed
    {
        $batchId = $this->batchId($batchId);
        $this->ensureDirectory();
        $handle = fopen($this->lockPath($batchId), 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not open upload batch lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock upload batch.');
            }
            try {
                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function requireOwner(array $metadata, int $userId): void
    {
        if ($userId < 1 || (int)($metadata['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Upload batch belongs to another administrator.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create profiled upload batch storage.');
        }
    }

    private function batchId(string $batchId): string
    {
        $batchId = strtolower(trim($batchId));
        if (preg_match('/^[a-f0-9]{64}$/', $batchId) !== 1) {
            throw new \InvalidArgumentException('Upload batch identifier is invalid.');
        }
        return $batchId;
    }

    private function metadataPath(string $batchId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $batchId . '.json';
    }

    private function manifestPath(string $batchId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $batchId . '.jsonl';
    }

    private function lockPath(string $batchId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $batchId . '.lock';
    }
}
