<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Converts completed browser uploads into processing jobs only after the whole
 * browser batch has finished transferring.
 */
final class CatalogBucketBatchQueue
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function queueName(): string
    {
        $base = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        return $base . ':bucket-processing';
    }

    /**
     * @return array{
     *   job_id:int,
     *   deduplicated:bool,
     *   duplicate_source_removed:bool,
     *   upload_id:string,
     *   original_name:string,
     *   source_relative_path:string,
     *   size:int,
     *   fingerprint:string
     * }
     */
    public function enqueueCompletedUpload(string $uploadId, int $userId): array
    {
        if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Completed Upload Bucket source metadata is invalid.');
        }

        $store = $this->chunkStore();
        $resolved = $store->resolveCompletedFile($uploadId, $userId);
        $manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
        $size = (int)($manifest['file_size'] ?? 0);
        $relativePath = $this->cleanRelativePath((string)($manifest['relative_path'] ?? ''));
        $originalName = $this->requiredName(basename(str_replace('\\', '/', $relativePath)));
        $fingerprint = $this->fingerprint($manifest);
        if ($size < 1 || $relativePath === '' || $fingerprint === '') {
            throw new \RuntimeException('Completed Upload Bucket source is incomplete.');
        }

        $queueName = $this->queueName();
        $dedupeKey = 'bucket-upload-source:' . $fingerprint;
        $existing = $this->db->prepare(
            'SELECT id,payload_json FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        $existingId = is_array($existingRow) ? (int)($existingRow['id'] ?? 0) : 0;
        $existingUploadId = '';
        if (is_array($existingRow) && !empty($existingRow['payload_json'])) {
            $decoded = json_decode((string)$existingRow['payload_json'], true);
            if (is_array($decoded)) {
                $existingUploadId = trim((string)($decoded['upload_id'] ?? ''));
            }
        }

        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::PROCESS_BUCKET_UPLOAD,
            [
                'upload_id' => $uploadId,
                'source_kind' => 'chunk-upload',
                'source_fingerprint' => $fingerprint,
                'source_relative_path' => $relativePath,
                'original_name' => $originalName,
                'size' => $size,
                'user_id' => $userId,
                'redirect_wrapper' => \catalog_redirect_archive_is_supported_filename($originalName),
            ],
            5,
            null,
            $dedupeKey,
            $userId,
            3
        );

        $deduplicated = $existingId > 0 && $existingId === $jobId;
        $removed = false;
        if ($deduplicated && $existingUploadId !== '' && !hash_equals($existingUploadId, $uploadId)) {
            $removed = (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
        }

        return [
            'job_id' => $jobId,
            'deduplicated' => $deduplicated,
            'duplicate_source_removed' => $removed,
            'upload_id' => $uploadId,
            'original_name' => $originalName,
            'source_relative_path' => $relativePath,
            'size' => $size,
            'fingerprint' => $fingerprint,
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function fingerprint(array $manifest): string
    {
        if ((string)($manifest['status'] ?? '') !== 'complete') {
            throw new \RuntimeException('Upload Bucket source has not completed transferring.');
        }
        $received = is_array($manifest['received'] ?? null) ? $manifest['received'] : [];
        $totalChunks = (int)($manifest['total_chunks'] ?? 0);
        if ($totalChunks < 1) {
            throw new \RuntimeException('Upload Bucket source has no completed chunks.');
        }

        $hash = hash_init('sha256');
        hash_update($hash, "unrealdb-bucket-upload-v1\0");
        hash_update($hash, (string)((int)($manifest['file_size'] ?? 0)) . "\0");
        hash_update($hash, (string)((int)($manifest['chunk_bytes'] ?? 0)) . "\0");
        for ($index = 0; $index < $totalChunks; $index++) {
            $entry = $received['c' . $index] ?? null;
            $chunkHash = is_array($entry) ? strtolower(trim((string)($entry['sha256'] ?? ''))) : '';
            $bytes = is_array($entry) ? (int)($entry['bytes'] ?? 0) : 0;
            if ($bytes < 1 || preg_match('/^[a-f0-9]{64}$/', $chunkHash) !== 1) {
                throw new \RuntimeException('Upload Bucket source is missing verified chunk ' . ($index + 1) . '.');
            }
            hash_update($hash, $index . ':' . $bytes . ':' . $chunkHash . "\n");
        }
        return hash_final($hash);
    }

    private function chunkStore(): CatalogChunkedUploadStore
    {
        $config = $this->config;
        $config['max_upload_bytes'] = PHP_INT_MAX;
        $config['max_container_upload_bytes'] = PHP_INT_MAX;
        return new CatalogChunkedUploadStore($config);
    }

    private function requiredName(string $name): string
    {
        $name = \catalog_clean_unreal_filename($name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Upload Bucket filename is missing.');
        }
        return $name;
    }

    private function cleanRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace(["\0", '\\'], ['', '/'], $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
