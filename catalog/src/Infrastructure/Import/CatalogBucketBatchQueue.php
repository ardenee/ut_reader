<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Converts completed browser uploads into durable Upload Bucket processing jobs after transfer completion.
 * Why: Finalization owns durable source validation/deduplication and queue creation, not package parsing.
 * Role: Infrastructure import queue orchestration.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

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
        return $this->baseQueueName() . ':bucket-processing';
    }

    public function legacyQueueName(): string
    {
        return $this->baseQueueName() . ':bucket-redirects';
    }

    public function migrateLegacyQueuedJobs(): int
    {
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET queue_name=?,updated_at=? '
            . 'WHERE queue_name=? AND status="queued"'
        );
        $statement->execute([$this->queueName(), gmdate('Y-m-d H:i:s'), $this->legacyQueueName()]);
        return $statement->rowCount();
    }

    /**
     * @return array{
     *   job_id:int,
     *   duplicate_file_id:int,
     *   deduplicated:bool,
     *   duplicate_source_removed:bool,
     *   duplicate_kind:string,
     *   upload_id:string,
     *   original_name:string,
     *   source_relative_path:string,
     *   size:int,
     *   fingerprint:string,
     *   md5:string,
     *   sha1:string
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
        $sourcePath = (string)($resolved['path'] ?? '');
        $size = (int)($manifest['file_size'] ?? 0);
        $relativePath = CatalogImportPathPolicy::relative((string)($manifest['relative_path'] ?? ''));
        $originalName = CatalogImportPathPolicy::filename(
            basename(str_replace('\\', '/', $relativePath)),
            'Upload Bucket filename is missing.'
        );
        $redirect = \catalog_redirect_archive_is_supported_filename($originalName);
        $fingerprint = $this->fingerprint($manifest);
        if ($size < 1 || $relativePath === '' || $fingerprint === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Completed Upload Bucket source is incomplete.');
        }

        $md5 = '';
        $sha1 = '';
        if (!$redirect) {
            $identityStore = new CatalogBucketUploadIdentityStore($this->config);
            try {
                $identity = $identityStore->load($uploadId, $userId);
            } catch (Throwable) {
                // Compatibility for ordinary uploads completed before browser-side
                // hashing was introduced. Redirect wrappers deliberately do not
                // take this path because their source hashes are not package hashes.
                $legacyMd5 = hash_file('md5', $sourcePath);
                $legacySha1 = hash_file('sha1', $sourcePath);
                if (!is_string($legacyMd5) || !is_string($legacySha1)) {
                    throw new \RuntimeException('Could not calculate legacy staged upload identity.');
                }
                $identity = $identityStore->save(
                    $uploadId,
                    $userId,
                    $size,
                    $legacyMd5,
                    $legacySha1,
                    $originalName,
                    $relativePath,
                    false
                );
            }
            $md5 = strtolower((string)$identity['md5']);
            $sha1 = strtolower((string)$identity['sha1']);
            if ((int)($identity['file_size'] ?? 0) !== $size) {
                throw new \RuntimeException('Upload hash identity size no longer matches the completed source.');
            }

            $inspection = (new CatalogUploadDuplicateDetector($this->db, $this->config))
                ->inspect($size, $md5, $sha1);
            $physical = is_array($inspection['duplicate'] ?? null) ? $inspection['duplicate'] : null;
            if ($physical !== null) {
                $removed = (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
                return [
                    'job_id' => 0,
                    'duplicate_file_id' => (int)$physical['file_id'],
                    'deduplicated' => true,
                    'duplicate_source_removed' => $removed,
                    'duplicate_kind' => (string)$physical['location_kind'],
                    'upload_id' => $uploadId,
                    'original_name' => $originalName,
                    'source_relative_path' => $relativePath,
                    'size' => $size,
                    'fingerprint' => $fingerprint,
                    'md5' => $md5,
                    'sha1' => $sha1,
                ];
            }
        }

        $queueName = $this->queueName();
        $existingId = 0;
        $existingUploadId = '';
        $existingSourceAvailable = false;

        if (!$redirect) {
            $dedupeKey = 'bucket-upload-source:' . $fingerprint;
            $existing = $this->db->prepare(
                'SELECT id,payload_json FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
            );
            $existing->execute([$queueName, $dedupeKey]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            $existingId = is_array($existingRow) ? (int)($existingRow['id'] ?? 0) : 0;
            if (is_array($existingRow) && !empty($existingRow['payload_json'])) {
                $decoded = json_decode((string)$existingRow['payload_json'], true);
                if (is_array($decoded)) {
                    $existingUploadId = trim((string)($decoded['upload_id'] ?? ''));
                }
            }
            if (preg_match('/^[a-f0-9]{64}$/', $existingUploadId) === 1) {
                try {
                    $store->resolveCompletedFile($existingUploadId, null);
                    $existingSourceAvailable = true;
                } catch (Throwable) {
                    $existingSourceAvailable = false;
                }
            }
            if ($existingId > 0 && !$existingSourceAvailable) {
                $existingId = 0;
                $existingUploadId = '';
                $dedupeKey .= ':' . $uploadId;
            }
        } else {
            $dedupeKey = 'bucket-redirect-upload:' . $uploadId;
        }

        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::PROCESS_BUCKET_UPLOAD,
            [
                'upload_id' => $uploadId,
                'staged_path' => 'chunk-upload:' . $uploadId,
                'source_kind' => 'chunk-upload',
                'source_fingerprint' => $fingerprint,
                'source_md5' => $redirect ? '' : $md5,
                'source_sha1' => $redirect ? '' : $sha1,
                'package_md5' => $redirect ? '' : $md5,
                'package_sha1' => $redirect ? '' : $sha1,
                'source_relative_path' => $relativePath,
                'original_name' => $originalName,
                'size' => $size,
                'user_id' => $userId,
                'redirect_wrapper' => $redirect,
            ],
            5,
            null,
            $dedupeKey,
            $userId,
            3
        );

        $deduplicated = !$redirect
            && $existingSourceAvailable
            && $existingId > 0
            && $existingId === $jobId;
        $removed = false;
        if ($deduplicated && $existingUploadId !== '' && !hash_equals($existingUploadId, $uploadId)) {
            $removed = (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
        }

        return [
            'job_id' => $jobId,
            'duplicate_file_id' => 0,
            'deduplicated' => $deduplicated,
            'duplicate_source_removed' => $removed,
            'duplicate_kind' => $deduplicated ? 'active_source' : '',
            'upload_id' => $uploadId,
            'original_name' => $originalName,
            'source_relative_path' => $relativePath,
            'size' => $size,
            'fingerprint' => $fingerprint,
            'md5' => $md5,
            'sha1' => $sha1,
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

    private function baseQueueName(): string
    {
        return trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    }
}
