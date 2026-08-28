<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Stages an Upload Bucket package whose authoritative MD5/SHA-1 were already calculated while producing its bytes.
 * Why: Browser uploads and redirect decompression can avoid a redundant hashing pass while sharing explicit storage/index collaborators.
 * Role: Infrastructure import orchestration.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedGameMatchRefreshQueue;

final class CatalogBucketIdentityProcessor
{
    private readonly CatalogBucketPackageOperations $operations;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        ?CatalogBucketPackageOperations $operations = null
    ) {
        $this->operations = $operations ?? new CatalogBucketPackageOperationsService($db, $config);
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string,md5:string,sha1:string}
     */
    public function stage(
        string $temporaryPath,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        string $md5,
        string $sha1,
        ?callable $progress = null
    ): array {
        if (!is_file($temporaryPath)) {
            throw new \RuntimeException('Prepared Upload Bucket file is missing.');
        }
        if ($uploadedBy < 1) {
            throw new \RuntimeException('Administrator identity is missing from the Upload Bucket job.');
        }
        $size = (int)(filesize($temporaryPath) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('Prepared Upload Bucket file is empty.');
        }

        $md5 = strtolower(trim($md5));
        $sha1 = strtolower(trim($sha1));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Prepared package MD5 or SHA-1 is missing.');
        }

        $this->emit($progress, 'hash_identity', 55, 'Using MD5 and SHA-1 calculated while the package bytes were produced.', [
            'bytes_done' => $size,
            'bytes_total' => $size,
            'md5' => $md5,
            'sha1' => $sha1,
        ]);

        // Multiple workers can finish the raw package and its .uz/.uz2/.uz3
        // equivalent at the same time. Serialize only identical package
        // identities so the duplicate check and publication form one critical
        // section without reducing concurrency for unrelated files.
        $this->emit($progress, 'duplicate_lock', 57, 'Serializing identical package identity before duplicate inspection.');
        $identityLock = $this->lockIdentity($size, $md5, $sha1);
        try {
            $this->emit($progress, 'duplicate_check', 58, 'Checking size, MD5 and SHA-1 against physical Upload Bucket and catalog files.');
            $inspection = (new CatalogUploadDuplicateDetector($this->db, $this->config))->inspect($size, $md5, $sha1);
            $duplicate = is_array($inspection['duplicate'] ?? null) ? $inspection['duplicate'] : null;
            if ($duplicate !== null) {
                @unlink($temporaryPath);
                $existingName = trim((string)($duplicate['original_name'] ?? ''));
                if ($existingName === '') {
                    $existingName = trim((string)($duplicate['package_name'] ?? '')) ?: 'existing physical package';
                }
                $location = (string)($duplicate['location_kind'] ?? '') === 'upload_bucket'
                    ? 'the Upload Bucket'
                    : 'catalog storage';
                $message = 'Duplicate size, MD5 and SHA-1 already exist in ' . $location
                    . ' as ' . $existingName . ' (file #' . (int)$duplicate['file_id'] . '). Prepared copy discarded.';
                $this->emit($progress, 'duplicate', 100, $message, ['file_id' => (int)$duplicate['file_id']]);
                return [
                    'status' => 'duplicate',
                    'file_id' => (int)$duplicate['file_id'],
                    'queue_name' => '',
                    'original_name' => $existingName,
                    'path' => (string)$duplicate['physical_path'],
                    'size' => $size,
                    'message' => $message,
                    'parse_error' => null,
                    'md5' => $md5,
                    'sha1' => $sha1,
                ];
            }

            $missingBase = (int)($inspection['missing_base_game_matches'] ?? 0);
            if ($missingBase > 0) {
                $this->emit(
                    $progress,
                    'duplicate_check',
                    59,
                    'Official base-game identity metadata matched, but no physical source file exists. Keeping this package.'
                );
            }

            $this->emit($progress, 'bucket_store', 60, 'Moving the prepared package into Upload Bucket storage.');
            $stored = $this->operations->store($temporaryPath, $originalName, $reason);
            $storedPath = (string)$stored['path'];

            try {
                $indexed = $this->operations->index(
                    (string)$stored['queue_name'],
                    $storedPath,
                    (string)$stored['original_name'],
                    $reason,
                    $uploadedBy,
                    $sourceRelativePath,
                    (int)$stored['size'],
                    $md5,
                    $sha1,
                    $progress
                );
            } catch (Throwable $error) {
                @unlink($storedPath . '.txt');
                @unlink($storedPath);
                throw $error;
            }

            // Exact dependency/object-path evidence is intentionally not built in
            // this worker's staging critical path. Queue it after the package is
            // durably indexed; the same bucket worker pool will pick it up after
            // the current upload job completes.
            $fileId = (int)($indexed['file_id'] ?? 0);
            if ($fileId > 0) {
                try {
                    $indexed['game_match_job_id'] = (new CatalogUnverifiedGameMatchRefreshQueue($this->db, $this->config))
                        ->enqueueFile($fileId, $uploadedBy);
                } catch (Throwable $matchError) {
                    $message = trim($matchError->getMessage());
                    $indexed['game_match_warning'] = $message;
                    error_log(
                        '[UnrealDB bucket match cache] file_id=' . $fileId . ' error='
                        . ($message !== '' ? $message : get_class($matchError))
                    );
                }
            }

            return $indexed + ['md5' => $md5, 'sha1' => $sha1];
        } finally {
            $this->unlockIdentity($identityLock);
        }
    }

    /** @return resource */
    private function lockIdentity(int $size, string $md5, string $sha1)
    {
        $storageRoot = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \RuntimeException('Catalog storage path is unavailable for Upload Bucket identity locking.');
        }

        $identity = hash('sha256', $size . "\0" . $md5 . "\0" . $sha1);
        $directory = $storageRoot
            . DIRECTORY_SEPARATOR . 'jobs'
            . DIRECTORY_SEPARATOR . 'upload-identity-locks'
            . DIRECTORY_SEPARATOR . substr($identity, 0, 2);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create Upload Bucket identity lock storage.');
        }

        $handle = @fopen($directory . DIRECTORY_SEPARATOR . $identity . '.lock', 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Could not lock Upload Bucket package identity.');
        }
        return $handle;
    }

    /** @param resource $handle */
    private function unlockIdentity($handle): void
    {
        $metadata = stream_get_meta_data($handle);
        $path = is_array($metadata) ? (string)($metadata['uri'] ?? '') : '';
        flock($handle, LOCK_UN);
        fclose($handle);

        // Identity locks are synchronization primitives, not persistent state.
        // Once the critical section ends, remove the zero-byte lock file and
        // opportunistically remove its hash-prefix directory.
        if ($path !== '' && is_file($path)) {
            @unlink($path);
            @rmdir(dirname($path));
            @rmdir(dirname(dirname($path)));
        }
    }

    /** @param callable(array<string,mixed>):void|null $progress @param array<string,mixed> $meta */
    private function emit(?callable $progress, string $stage, int $percent, string $message, array $meta = []): void
    {
        if ($progress === null) {
            return;
        }
        $progress($meta + [
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
