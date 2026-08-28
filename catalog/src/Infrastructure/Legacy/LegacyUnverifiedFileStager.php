<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the application unverified-file staging port for legacy/source/federation callers.
 * Why: These callers still need the established physical queue contract while staging persistence is namespaced.
 * Role: Compatibility infrastructure adapter; retain until all non-browser staging callers migrate.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Legacy;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadDuplicateDetector;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;

final class LegacyUnverifiedFileStager implements UnverifiedFileStager
{
    private readonly CatalogUnverifiedStagingIndex $staging;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
    }

    public function stageBucketUpload(
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): array {
        if (!is_file($temporaryPath)) {
            throw new RuntimeException('Upload temporary file is missing.');
        }

        $size = (int)(filesize($temporaryPath) ?: 0);
        $md5 = md5_file($temporaryPath);
        $sha1 = sha1_file($temporaryPath);
        if ($size <= 0 || !is_string($md5) || $md5 === ''
            || !is_string($sha1) || $sha1 === '') {
            throw new RuntimeException('Could not calculate the upload-bucket duplicate identity.');
        }
        $md5 = strtolower($md5);
        $sha1 = strtolower($sha1);
        $lockName = 'unrealdb-bucket-md5-' . $md5;
        $lock = \catalog_one($this->db, 'SELECT GET_LOCK(?, 30) acquired', [$lockName]);
        if ((int)($lock['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Timed out while checking the upload bucket for an identical file.');
        }

        try {
            // Re-run the full exact-identity check inside the bucket MD5 lock.
            // This closes the race between the public worker's earlier duplicate
            // check and physical publication into the unverified queue.
            $duplicateCheck = (new CatalogUploadDuplicateDetector($this->db, $this->config))->inspect(
                $size,
                $md5,
                $sha1
            );
            $duplicate = is_array($duplicateCheck['duplicate'] ?? null)
                ? $duplicateCheck['duplicate']
                : null;
            if ($duplicate !== null) {
                @unlink($temporaryPath);
                $existingName = trim((string)($duplicate['original_name'] ?? ''));
                if ($existingName === '') {
                    $existingName = 'existing file #' . (int)$duplicate['file_id'];
                }
                return [
                    'status' => 'duplicate',
                    'file_id' => (int)$duplicate['file_id'],
                    'queue_name' => basename((string)($duplicate['physical_path'] ?? '')),
                    'original_name' => $existingName,
                    'path' => (string)($duplicate['physical_path'] ?? ''),
                    'size' => $size,
                    'message' => 'Physically confirmed exact size/MD5/SHA-1 already exists as '
                        . $existingName . ' (file #' . (int)$duplicate['file_id']
                        . '). Incoming copy discarded.',
                    'parse_error' => null,
                    'md5' => $md5,
                    'sha1' => $sha1,
                ];
            }

            $stored = CatalogUnverifiedQueueStorage::storeBucketUpload(
                $this->config,
                $temporaryPath,
                $originalName,
                $reason
            );
            $indexed = $this->indexStored(
                0,
                (string)$stored['queue_name'],
                (string)$stored['path'],
                (string)$stored['original_name'],
                $reason,
                $uploadedBy,
                $sourceRelativePath,
                (int)$stored['size']
            );
            $indexed['md5'] = $md5;
            $indexed['sha1'] = $sha1;
            return $indexed;
        } finally {
            try {
                \catalog_one($this->db, 'SELECT RELEASE_LOCK(?) released', [$lockName]);
            } catch (Throwable $error) {
                error_log('[UnrealDB upload bucket duplicate lock] ' . $error->getMessage());
            }
        }
    }

    public function stageFailedUpload(
        int $queueGameId,
        string $temporaryPath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): ?array {
        return $this->stageFailedPath(
            $queueGameId,
            $temporaryPath,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            false
        );
    }

    public function stageFailedCopy(
        int $queueGameId,
        string $sourcePath,
        string $originalName,
        string $reason,
        ?int $uploadedBy = null,
        string $sourceRelativePath = ''
    ): ?array {
        return $this->stageFailedPath(
            $queueGameId,
            $sourcePath,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            true
        );
    }


    /**
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}|null
     */
    private function stageFailedPath(
        int $queueGameId,
        string $sourcePath,
        string $originalName,
        string $reason,
        ?int $uploadedBy,
        string $sourceRelativePath,
        bool $copySource
    ): ?array {
        if (!is_file($sourcePath)) {
            return null;
        }
        if (!\scanner_file_has_unreal_package_magic($sourcePath)) {
            if (!$copySource) {
                @unlink($sourcePath);
            }
            return null;
        }

        $game = \catalog_one(
            $this->db,
            'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?',
            [$queueGameId]
        );
        if (!$game) {
            throw new RuntimeException('Target unverified queue game was not found.');
        }

        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, true);
        $cleanName = \scanner_clean_original_filename($originalName);
        $queueName = CatalogUnverifiedQueueStorage::safeQueueName($cleanName);
        $destination = CatalogUnverifiedQueueStorage::uniqueDestination($directory, $queueName);

        if ($copySource) {
            $stored = @copy($sourcePath, $destination);
        } elseif (is_uploaded_file($sourcePath)) {
            $stored = @move_uploaded_file($sourcePath, $destination);
        } else {
            $stored = @rename($sourcePath, $destination);
        }
        if (!$stored) {
            throw new RuntimeException(
                $copySource
                    ? 'Could not copy the failed package into unverified storage.'
                    : 'Could not move the failed package into unverified storage.'
            );
        }

        @file_put_contents($destination . '.txt', $reason);
        return $this->indexStored(
            $queueGameId,
            basename($destination),
            $destination,
            $cleanName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            (int)(filesize($destination) ?: 0)
        );
    }

    /**
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string}
     */
    private function indexStored(
        int $queueGameId,
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        ?int $uploadedBy,
        string $sourceRelativePath,
        int $size
    ): array {
        try {
            $indexed = $this->staging->indexPath(
                $queueGameId,
                $queueName,
                $path,
                $originalName,
                $reason,
                $uploadedBy,
                $sourceRelativePath,
                false
            );
        } catch (Throwable $error) {
            $failure = 'Database staging failed: ' . trim($error->getMessage());
            @file_put_contents($path . '.txt', "\n" . $failure, FILE_APPEND);
            error_log('[UnrealDB unverified staging] ' . $originalName . ': ' . $error->getMessage());
            throw new RuntimeException(
                'The file was retained in the unverified queue, but database staging failed.',
                0,
                $error
            );
        }

        return [
            'status' => (string)$indexed['status'],
            'file_id' => (int)$indexed['file_id'],
            'queue_name' => $queueName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $size,
            'message' => (string)$indexed['message'],
            'parse_error' => isset($indexed['parse_error']) && $indexed['parse_error'] !== null
                ? (string)$indexed['parse_error']
                : null,
        ];
    }
}
