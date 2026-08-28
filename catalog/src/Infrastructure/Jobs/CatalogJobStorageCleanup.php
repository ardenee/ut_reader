<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reclaims transient/orphaned background-job filesystem storage.
 * Why: Job history and durable upload/import staging must not leave completed
 *      work consuming disk after its database owner is gone.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;

/**
 * Removes job-storage artifacts that no longer have a live/retryable/problem
 * owner. Successful completed jobs are not storage owners.
 *
 * The storage root is always derived from the active runtime configuration:
 *   <storage_path>/jobs
 *
 * That is important on installations where the deployed storage path is on a
 * separate volume from the source checkout.
 */
final class CatalogJobStorageCleanup
{
    private const RESTARTABLE_STATUSES = ['queued', 'running', 'failed', 'dead_letter', 'cancelled'];

    private string $storageRoot;
    private string $jobsRoot;
    private string $incomingDirectory;
    private string $backupImportDirectory;
    private string $preparedDirectory;
    private string $pakImportDirectory;
    private string $chunkedUploadDirectory;
    private string $profiledUploadBatchDirectory;
    private string $eventDirectory;
    private string $bucketWorkingDirectory;
    private string $bucketPakPublishDirectory;
    private string $identityLockDirectory;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for job-storage cleanup.');
        }

        $this->storageRoot = $storageRoot;
        $this->jobsRoot = $storageRoot . DIRECTORY_SEPARATOR . 'jobs';
        $this->incomingDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'incoming';
        $this->backupImportDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'game-backup-import';
        $this->preparedDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'prepared';
        $this->pakImportDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'pak-import';
        $this->chunkedUploadDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'chunked-uploads';
        $this->profiledUploadBatchDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'profiled-upload-batches';
        $this->eventDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'events';
        $this->bucketWorkingDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'bucket-working';
        $this->bucketPakPublishDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'bucket-pak-publish';
        $this->identityLockDirectory = $this->jobsRoot . DIRECTORY_SEPARATOR . 'upload-identity-locks';
    }

    public function root(): string
    {
        return $this->jobsRoot;
    }

    /**
     * @return array<string,mixed>
     */
    public function prune(
        int $minimumAgeSeconds = 300,
        bool $manualCleanup = false,
        ?callable $progress = null
    ): array {
        $minimumAgeSeconds = max(60, min($minimumAgeSeconds, 30 * 86400));
        $this->emitProgress($progress, 'references', 1, [], 0, 0);
        $references = $this->recoveryReferences($minimumAgeSeconds, $manualCleanup);

        $result = ['root' => $this->jobsRoot, 'manual_cleanup' => $manualCleanup];

        $result['incoming'] = $this->pruneIncoming(
            $minimumAgeSeconds,
            $references['incoming'],
            $progress,
            3,
            20
        );
        $result['backup_import'] = $this->pruneBackupImport(
            $minimumAgeSeconds,
            $references['backup_import_jobs']
        );
        $this->emitProgress($progress, 'backup_import', 22, $result['backup_import']);

        $result['prepared'] = $this->pruneOwnedDirectories(
            $this->preparedDirectory,
            $minimumAgeSeconds,
            $references['owner_jobs'],
            $progress,
            'prepared',
            22,
            55
        );
        $result['pak_import'] = $this->pruneOwnedDirectories(
            $this->pakImportDirectory,
            $minimumAgeSeconds,
            $references['owner_jobs'],
            $progress,
            'pak_import',
            55,
            62
        );
        $result['chunked_uploads'] = $this->pruneChunkedUploads(
            $minimumAgeSeconds,
            $references['chunked_uploads'],
            $manualCleanup,
            $progress,
            62,
            72
        );
        $result['profiled_upload_batches'] = $this->pruneProfiledUploadBatches(
            $minimumAgeSeconds,
            $references['profiled_batches'],
            $manualCleanup,
            $progress,
            72,
            77
        );
        $result['events'] = $this->pruneEventFiles($minimumAgeSeconds);
        $this->emitProgress($progress, 'events', 80, $result['events']);

        $result['bucket_working'] = $this->pruneJobNamedFiles(
            $this->bucketWorkingDirectory,
            $minimumAgeSeconds,
            $references['owner_jobs']
        );
        $this->emitProgress($progress, 'bucket_working', 84, $result['bucket_working']);

        $result['bucket_pak_publish'] = $this->pruneJobNamedFiles(
            $this->bucketPakPublishDirectory,
            $minimumAgeSeconds,
            $references['owner_jobs']
        );
        $this->emitProgress($progress, 'bucket_pak_publish', 87, $result['bucket_pak_publish']);

        $result['identity_locks'] = $this->pruneIdentityLocks(
            $minimumAgeSeconds,
            $progress,
            87,
            99
        );
        $this->emitProgress($progress, 'complete', 99, $this->aggregateStats($result));
        return $result;
    }

    /**
     * @return array{
     *   owner_jobs:array<int,true>,
     *   backup_import_jobs:array<int,true>,
     *   incoming:array<string,true>,
     *   chunked_uploads:array<string,true>,
     *   profiled_batches:array<string,true>
     * }
     */
    private function recoveryReferences(int $minimumAgeSeconds, bool $manualCleanup): array
    {
        $result = [
            'owner_jobs' => [],
            'backup_import_jobs' => [],
            'incoming' => [],
            'chunked_uploads' => [],
            'profiled_batches' => [],
        ];

        $statement = $this->db->query(
            'SELECT id,job_type,status,payload_json,result_json FROM ue_background_jobs '
            . 'WHERE status IN ("queued","running","failed","dead_letter","cancelled") '
            . 'OR (status="completed" AND result_json LIKE "%source_retained%")'
        );

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $jobId = (int)($row['id'] ?? 0);
            if ($jobId < 1 || !$this->isRecoveryOwner($row)) {
                continue;
            }

            $result['owner_jobs'][$jobId] = true;
            if ((string)($row['job_type'] ?? '') === JobType::IMPORT_GAME_BACKUP) {
                $result['backup_import_jobs'][$jobId] = true;
            }

            $payload = $this->decodeJson((string)($row['payload_json'] ?? ''));
            if (is_array($payload)) {
                $this->collectPayloadReferences($payload, $result);
            }
        }

        // A profiled browser batch exists before its coordinator DB job is
        // created. Protect files referenced by a genuinely active upload manifest
        // so maintenance cannot race an in-progress browser upload.
        $this->collectActiveProfiledBatchReferences($result, $minimumAgeSeconds, $manualCleanup);

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $result
     */
    private function collectPayloadReferences(array $payload, array &$result): void
    {
        foreach (['batch_id', 'profiled_upload_batch_id'] as $key) {
            $batchId = strtolower(trim((string)($payload[$key] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $batchId) === 1) {
                $result['profiled_batches'][$batchId] = true;
            }
        }

        $walk = function (mixed $value) use (&$walk, &$result): void {
            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child);
                }
                return;
            }
            if (!is_string($value)) {
                return;
            }

            $normalized = ltrim(str_replace('\\', '/', trim($value)), '/');
            $lower = strtolower($normalized);
            if (str_starts_with($lower, 'jobs/incoming/')) {
                $result['incoming'][$lower] = true;
                return;
            }
            if (preg_match('/^chunk-upload:([a-f0-9]{64})$/i', $normalized, $match) === 1) {
                $result['chunked_uploads'][strtolower($match[1])] = true;
            }
        };

        $walk($payload);
    }

    /** @param array<string,mixed> $result */
    private function collectActiveProfiledBatchReferences(
        array &$result,
        int $minimumAgeSeconds,
        bool $manualCleanup
    ): void
    {
        if (!is_dir($this->profiledUploadBatchDirectory)) {
            return;
        }

        $staleSeconds = $manualCleanup ? $minimumAgeSeconds : $this->uploadStaleSeconds();
        $now = time();

        foreach (glob($this->profiledUploadBatchDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metadataPath) {
            if (!is_string($metadataPath) || !is_file($metadataPath) || is_link($metadataPath)) {
                continue;
            }

            $batchId = strtolower((string)pathinfo($metadataPath, PATHINFO_FILENAME));
            if (preg_match('/^[a-f0-9]{64}$/', $batchId) !== 1) {
                continue;
            }

            $metadata = $this->decodeJson((string)@file_get_contents($metadataPath));
            $status = is_array($metadata) ? strtolower(trim((string)($metadata['status'] ?? ''))) : '';
            $manifestPath = $this->profiledUploadBatchDirectory . DIRECTORY_SEPARATOR . $batchId . '.jsonl';
            $latest = max(
                (int)(@filemtime($metadataPath) ?: 0),
                is_file($manifestPath) ? (int)(@filemtime($manifestPath) ?: 0) : 0
            );

            $lockPath = $this->profiledUploadBatchDirectory . DIRECTORY_SEPARATOR . $batchId . '.lock';
            $activeBrowserUpload = $status === 'uploading'
                && (
                    ($latest > 0 && ($now - $latest) < $staleSeconds)
                    || $this->isLocked($lockPath)
                );
            $protectedCoordinator = isset($result['profiled_batches'][$batchId]);
            if (!$activeBrowserUpload && !$protectedCoordinator) {
                continue;
            }

            $result['profiled_batches'][$batchId] = true;
            if (!is_file($manifestPath) || is_link($manifestPath)) {
                continue;
            }

            $handle = @fopen($manifestPath, 'rb');
            if (!is_resource($handle)) {
                continue;
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    $decoded = $this->decodeJson(trim($line));
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $stagedPath = trim((string)($decoded['staged_path'] ?? ''));
                    if ($stagedPath === '') {
                        continue;
                    }
                    $normalized = ltrim(str_replace('\\', '/', $stagedPath), '/');
                    $lower = strtolower($normalized);
                    if (str_starts_with($lower, 'jobs/incoming/')) {
                        $result['incoming'][$lower] = true;
                    } elseif (preg_match('/^chunk-upload:([a-f0-9]{64})$/i', $normalized, $match) === 1) {
                        $result['chunked_uploads'][strtolower($match[1])] = true;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /** @return array{scanned:int,referenced:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneIncoming(
        int $minimumAgeSeconds,
        array $references,
        ?callable $progress = null,
        int $startPercent = 0,
        int $endPercent = 100
    ): array
    {
        $result = ['scanned' => 0, 'referenced' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->incomingDirectory)) {
            return $result;
        }

        $threshold = time() - $minimumAgeSeconds;
        $total = $this->countFiles($this->incomingDirectory);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->incomingDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }

            $result['scanned']++;
            $path = $entry->getPathname();
            $relative = strtolower($this->storageRelativePath($path));
            if ($relative !== '' && isset($references[$relative])) {
                $result['referenced']++;
                $this->emitLoopProgress($progress, 'incoming', $startPercent, $endPercent, $result, $total);
                continue;
            }
            if ((int)$entry->getMTime() > $threshold) {
                $result['recent']++;
                $this->emitLoopProgress($progress, 'incoming', $startPercent, $endPercent, $result, $total);
                continue;
            }

            $size = max(0, (int)$entry->getSize());
            if (@unlink($path)) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
            $this->emitLoopProgress($progress, 'incoming', $startPercent, $endPercent, $result, $total);
        }
        $this->emitProgress($progress, 'incoming', $endPercent, $result, $result['scanned'], $total);
        @rmdir($this->incomingDirectory);
        return $result;
    }

    /** @return array{scanned:int,active:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneBackupImport(int $minimumAgeSeconds, array $activeJobs): array
    {
        $result = ['scanned' => 0, 'active' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->backupImportDirectory)) {
            return $result;
        }

        $threshold = time() - $minimumAgeSeconds;
        foreach (new FilesystemIterator($this->backupImportDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $result['scanned']++;
            $name = $entry->getFilename();
            $jobId = preg_match('/^restore-([0-9]+)-/i', $name, $match) === 1 ? (int)$match[1] : 0;
            if (($jobId > 0 && isset($activeJobs[$jobId])) || ($jobId === 0 && $activeJobs !== [])) {
                $result['active']++;
                continue;
            }
            if ((int)$entry->getMTime() > $threshold) {
                $result['recent']++;
                continue;
            }

            $size = max(0, (int)$entry->getSize());
            if (@unlink($entry->getPathname())) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
        }
        @rmdir($this->backupImportDirectory);
        return $result;
    }

    /** @return array{scanned:int,retained:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneOwnedDirectories(
        string $root,
        int $minimumAgeSeconds,
        array $ownerJobs,
        ?callable $progress = null,
        string $category = 'owned',
        int $startPercent = 0,
        int $endPercent = 100
    ): array
    {
        $result = ['scanned' => 0, 'retained' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($root)) {
            return $result;
        }

        $threshold = time() - $minimumAgeSeconds;
        $total = $this->countTopLevelDirectories($root);
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $result['scanned']++;
            $name = $entry->getFilename();
            $jobId = preg_match('/^job-([0-9]+)$/', $name, $match) === 1 ? (int)$match[1] : 0;
            if ($jobId > 0 && isset($ownerJobs[$jobId])) {
                $result['retained']++;
                $this->emitLoopProgress($progress, $category, $startPercent, $endPercent, $result, $total);
                continue;
            }

            $stats = $this->treeStats($entry->getPathname());
            if ($stats['modified'] > $threshold) {
                $result['recent']++;
                $this->emitLoopProgress($progress, $category, $startPercent, $endPercent, $result, $total);
                continue;
            }
            if ($this->deleteTree($entry->getPathname())) {
                $result['deleted']++;
                $result['bytes'] += $stats['bytes'];
            } else {
                $result['failed']++;
            }
            $this->emitLoopProgress($progress, $category, $startPercent, $endPercent, $result, $total);
        }
        $this->emitProgress($progress, $category, $endPercent, $result, $result['scanned'], $total);
        @rmdir($root);
        return $result;
    }

    /** @return array{scanned:int,referenced:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneChunkedUploads(
        int $minimumAgeSeconds,
        array $references,
        bool $manualCleanup,
        ?callable $progress = null,
        int $startPercent = 0,
        int $endPercent = 100
    ): array
    {
        $result = ['scanned' => 0, 'referenced' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->chunkedUploadDirectory)) {
            return $result;
        }

        $directories = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->chunkedUploadDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $uploadId = strtolower($entry->getFilename());
            if (preg_match('/^[a-f0-9]{64}$/', $uploadId) === 1) {
                $directories[$uploadId] = $entry->getPathname();
            }
        }

        $threshold = time() - $minimumAgeSeconds;
        $uploadStaleThreshold = time() - $this->uploadStaleSeconds();
        $cleanup = new CatalogChunkedUploadCleanup($this->config);
        $total = count($directories);

        foreach ($directories as $uploadId => $directory) {
            $result['scanned']++;
            if (isset($references[$uploadId])) {
                $result['referenced']++;
                $this->emitLoopProgress($progress, 'chunked_uploads', $startPercent, $endPercent, $result, $total);
                continue;
            }

            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = is_file($manifestPath)
                ? $this->decodeJson((string)@file_get_contents($manifestPath))
                : null;
            $status = is_array($manifest) ? strtolower(trim((string)($manifest['status'] ?? ''))) : '';
            $modified = is_file($manifestPath)
                ? (int)(@filemtime($manifestPath) ?: 0)
                : $this->treeStats($directory)['modified'];

            // Manual web cleanup keeps only genuinely active/recent uploads.
            // Scheduled maintenance remains conservative and uses the configured
            // browser-upload stale window.
            if ($status === 'uploading') {
                $lockPath = $directory . DIRECTORY_SEPARATOR . '.lock';
                if ($this->isLocked($lockPath)) {
                    $result['referenced']++;
                    $this->emitLoopProgress($progress, 'chunked_uploads', $startPercent, $endPercent, $result, $total);
                    continue;
                }
                if ($manualCleanup ? $modified > $threshold : $modified >= $uploadStaleThreshold) {
                    $result['recent']++;
                    $this->emitLoopProgress($progress, 'chunked_uploads', $startPercent, $endPercent, $result, $total);
                    continue;
                }
            } elseif ($modified > $threshold) {
                $result['recent']++;
                $this->emitLoopProgress($progress, 'chunked_uploads', $startPercent, $endPercent, $result, $total);
                continue;
            }

            $stats = $cleanup->deleteWithStats($uploadId);
            if (!empty($stats['deleted'])) {
                $result['deleted']++;
                $result['bytes'] += max(0, (int)($stats['bytes'] ?? 0));
            } else {
                // Malformed/orphaned stores can contain files the normal chunk
                // helper does not recognize. They have no owner, so remove the
                // remaining tree directly.
                $bytes = $this->treeStats($directory)['bytes'];
                if ($this->deleteTree($directory)) {
                    $result['deleted']++;
                    $result['bytes'] += $bytes;
                } else {
                    $result['failed']++;
                }
            }
            $this->emitLoopProgress($progress, 'chunked_uploads', $startPercent, $endPercent, $result, $total);
        }

        $this->emitProgress($progress, 'chunked_uploads', $endPercent, $result, $result['scanned'], $total);
        $this->removeEmptyDirectories($this->chunkedUploadDirectory);
        @rmdir($this->chunkedUploadDirectory);
        return $result;
    }

    /** @return array{scanned:int,retained:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneProfiledUploadBatches(
        int $minimumAgeSeconds,
        array $protectedBatches,
        bool $manualCleanup,
        ?callable $progress = null,
        int $startPercent = 0,
        int $endPercent = 100
    ): array
    {
        $result = ['scanned' => 0, 'retained' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->profiledUploadBatchDirectory)) {
            return $result;
        }

        $ids = [];
        foreach (new FilesystemIterator($this->profiledUploadBatchDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            if (preg_match('/^([a-f0-9]{64})\.(?:json|jsonl|lock)$/i', $entry->getFilename(), $match) === 1) {
                $ids[strtolower($match[1])] = true;
            }
        }

        $threshold = time() - $minimumAgeSeconds;
        $uploadStaleThreshold = time() - $this->uploadStaleSeconds();
        $total = count($ids);

        foreach (array_keys($ids) as $batchId) {
            $result['scanned']++;
            $metadataPath = $this->profiledUploadBatchDirectory . DIRECTORY_SEPARATOR . $batchId . '.json';
            $manifestPath = $this->profiledUploadBatchDirectory . DIRECTORY_SEPARATOR . $batchId . '.jsonl';
            $lockPath = $this->profiledUploadBatchDirectory . DIRECTORY_SEPARATOR . $batchId . '.lock';

            $metadata = is_file($metadataPath)
                ? $this->decodeJson((string)@file_get_contents($metadataPath))
                : null;
            $status = is_array($metadata) ? strtolower(trim((string)($metadata['status'] ?? ''))) : '';

            $latest = 0;
            $bytes = 0;
            foreach ([$metadataPath, $manifestPath, $lockPath] as $path) {
                if (!is_file($path) || is_link($path)) {
                    continue;
                }
                $latest = max($latest, (int)(@filemtime($path) ?: 0));
                $bytes += max(0, (int)(@filesize($path) ?: 0));
            }

            if (isset($protectedBatches[$batchId])) {
                $result['retained']++;
                $this->emitLoopProgress($progress, 'profiled_upload_batches', $startPercent, $endPercent, $result, $total);
                continue;
            }
            if ($status === 'uploading') {
                if ($this->isLocked($lockPath)) {
                    $result['retained']++;
                    $this->emitLoopProgress($progress, 'profiled_upload_batches', $startPercent, $endPercent, $result, $total);
                    continue;
                }
                if ($manualCleanup ? $latest > $threshold : $latest >= $uploadStaleThreshold) {
                    $result['recent']++;
                    $this->emitLoopProgress($progress, 'profiled_upload_batches', $startPercent, $endPercent, $result, $total);
                    continue;
                }
            } elseif ($latest > $threshold) {
                $result['recent']++;
                $this->emitLoopProgress($progress, 'profiled_upload_batches', $startPercent, $endPercent, $result, $total);
                continue;
            }

            $lock = null;
            if (is_file($lockPath)) {
                $lock = @fopen($lockPath, 'c+b');
                if (is_resource($lock) && !@flock($lock, LOCK_EX | LOCK_NB)) {
                    fclose($lock);
                    $result['retained']++;
                    $this->emitLoopProgress($progress, 'profiled_upload_batches', $startPercent, $endPercent, $result, $total);
                    continue;
                }
            }

            $ok = true;
            try {
                foreach ([$metadataPath, $manifestPath] as $path) {
                    if (is_file($path) && !@unlink($path)) {
                        $ok = false;
                    }
                }
            } finally {
                if (is_resource($lock)) {
                    @flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
            if (is_file($lockPath) && !@unlink($lockPath)) {
                $ok = false;
            }

            if ($ok) {
                $result['deleted']++;
                $result['bytes'] += $bytes;
            } else {
                $result['failed']++;
            }
            $this->emitLoopProgress($progress, 'profiled_upload_batches', $startPercent, $endPercent, $result, $total);
        }

        $this->emitProgress($progress, 'profiled_upload_batches', $endPercent, $result, $result['scanned'], $total);
        @rmdir($this->profiledUploadBatchDirectory);
        return $result;
    }

    /** @return array{scanned:int,retained:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneEventFiles(int $minimumAgeSeconds): array
    {
        $result = ['scanned' => 0, 'retained' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->eventDirectory)) {
            return $result;
        }

        $candidates = [];
        foreach (new FilesystemIterator($this->eventDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            if (preg_match('/^job-([0-9]+)\.jsonl$/', $entry->getFilename(), $match) !== 1) {
                continue;
            }
            $jobId = (int)$match[1];
            if ($jobId > 0) {
                $candidates[$jobId] = $entry->getPathname();
            }
        }

        $existing = $this->existingJobIds(array_keys($candidates));
        $threshold = time() - $minimumAgeSeconds;
        foreach ($candidates as $jobId => $path) {
            $result['scanned']++;
            if (isset($existing[$jobId])) {
                $result['retained']++;
                continue;
            }
            if ((int)(@filemtime($path) ?: 0) > $threshold) {
                $result['recent']++;
                continue;
            }
            $size = max(0, (int)(@filesize($path) ?: 0));
            if (@unlink($path)) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
        }

        @rmdir($this->eventDirectory);
        return $result;
    }

    /** @return array{scanned:int,retained:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneJobNamedFiles(string $root, int $minimumAgeSeconds, array $ownerJobs): array
    {
        $result = ['scanned' => 0, 'retained' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($root)) {
            return $result;
        }

        $threshold = time() - $minimumAgeSeconds;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }

            $result['scanned']++;
            $path = $entry->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
            $jobId = preg_match('/(?:^|\/)job-([0-9]+)(?:[-\/.]|$)/i', $relative, $match) === 1
                ? (int)$match[1]
                : 0;

            if ($jobId > 0 && isset($ownerJobs[$jobId])) {
                $result['retained']++;
                continue;
            }
            if ((int)$entry->getMTime() > $threshold) {
                $result['recent']++;
                continue;
            }

            $size = max(0, (int)$entry->getSize());
            if (@unlink($path)) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
        }

        @rmdir($root);
        return $result;
    }

    /** @return array{scanned:int,active:int,recent:int,deleted:int,bytes:int,failed:int} */
    private function pruneIdentityLocks(
        int $minimumAgeSeconds,
        ?callable $progress = null,
        int $startPercent = 0,
        int $endPercent = 100
    ): array
    {
        $result = ['scanned' => 0, 'active' => 0, 'recent' => 0, 'deleted' => 0, 'bytes' => 0, 'failed' => 0];
        if (!is_dir($this->identityLockDirectory)) {
            return $result;
        }

        $threshold = time() - $minimumAgeSeconds;
        $total = $this->countFiles($this->identityLockDirectory);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->identityLockDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }

            $result['scanned']++;
            if ((int)$entry->getMTime() > $threshold) {
                $result['recent']++;
                $this->emitLoopProgress($progress, 'identity_locks', $startPercent, $endPercent, $result, $total);
                continue;
            }

            $path = $entry->getPathname();
            $size = max(0, (int)$entry->getSize());
            $handle = @fopen($path, 'c+b');
            if (!is_resource($handle)) {
                $result['failed']++;
                $this->emitLoopProgress($progress, 'identity_locks', $startPercent, $endPercent, $result, $total);
                continue;
            }
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                $result['active']++;
                $this->emitLoopProgress($progress, 'identity_locks', $startPercent, $endPercent, $result, $total);
                continue;
            }
            @flock($handle, LOCK_UN);
            fclose($handle);

            if (@unlink($path)) {
                $result['deleted']++;
                $result['bytes'] += $size;
            } else {
                $result['failed']++;
            }
            $this->emitLoopProgress($progress, 'identity_locks', $startPercent, $endPercent, $result, $total);
        }

        $this->emitProgress($progress, 'identity_locks', $endPercent, $result, $result['scanned'], $total);
        $this->removeEmptyDirectories($this->identityLockDirectory);
        @rmdir($this->identityLockDirectory);
        return $result;
    }

    /** @param list<int> $ids @return array<int,true> */
    private function existingJobIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $existing = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id FROM ue_background_jobs WHERE id IN (' . $placeholders . ')'
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $jobId = (int)$id;
                if ($jobId > 0) {
                    $existing[$jobId] = true;
                }
            }
        }
        return $existing;
    }

    /** @param array<string,mixed> $row */
    private function isRecoveryOwner(array $row): bool
    {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (in_array($status, self::RESTARTABLE_STATUSES, true)) {
            return true;
        }
        if ($status !== 'completed') {
            return false;
        }

        $result = $this->decodeJson((string)($row['result_json'] ?? ''));
        return is_array($result) && !empty($result['source_retained']);
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function isLocked(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            return true;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return true;
        }
        @flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }

    private function countFiles(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && !$entry->isLink()) {
                $count++;
            }
        }
        return $count;
    }

    private function countTopLevelDirectories(string $root): int
    {
        if (!is_dir($root)) {
            return 0;
        }
        $count = 0;
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir() && !$entry->isLink()) {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string,mixed> $stats */
    private function emitLoopProgress(
        ?callable $progress,
        string $category,
        int $startPercent,
        int $endPercent,
        array $stats,
        int $total
    ): void {
        $scanned = max(0, (int)($stats['scanned'] ?? 0));
        if ($scanned === 0 || ($scanned % 100) !== 0) {
            return;
        }
        $range = max(0, $endPercent - $startPercent);
        $fraction = $total > 0 ? min(1, $scanned / $total) : 0.0;
        $percent = min($endPercent, $startPercent + (int)floor($range * $fraction));
        $this->emitProgress($progress, $category, $percent, $stats, $scanned, $total);
    }

    /** @param array<string,mixed> $stats */
    private function emitProgress(
        ?callable $progress,
        string $category,
        int $percent,
        array $stats = [],
        int $done = 0,
        int $total = 0
    ): void {
        if ($progress === null) {
            return;
        }
        $deleted = max(0, (int)($stats['deleted'] ?? 0));
        $bytes = max(0, (int)($stats['bytes'] ?? 0));
        $label = str_replace('_', ' ', $category);
        $message = 'Cleaning job storage: ' . $label;
        if ($total > 0) {
            $message .= ' — ' . number_format($done) . '/' . number_format($total) . ' checked';
        } elseif ($done > 0) {
            $message .= ' — ' . number_format($done) . ' checked';
        }
        if ($deleted > 0) {
            $message .= '; ' . number_format($deleted) . ' deleted';
        }
        if ($bytes > 0) {
            $message .= '; ' . $this->formatBytes($bytes) . ' reclaimed';
        }
        $message .= '.';

        $progress([
            'stage' => 'job_storage_cleanup',
            'category' => $category,
            'done' => $done,
            'total' => max(1, $total),
            'percent' => max(1, min(99, $percent)),
            'scanned' => max(0, (int)($stats['scanned'] ?? $done)),
            'deleted' => $deleted,
            'reclaimed_bytes' => $bytes,
            'recent' => max(0, (int)($stats['recent'] ?? 0)),
            'retained' => max(
                0,
                (int)($stats['retained'] ?? 0) + (int)($stats['referenced'] ?? 0) + (int)($stats['active'] ?? 0)
            ),
            'failed' => max(0, (int)($stats['failed'] ?? 0)),
            'message' => $message,
        ]);
    }

    /** @param array<string,mixed> $result @return array<string,int> */
    private function aggregateStats(array $result): array
    {
        $totals = ['scanned' => 0, 'deleted' => 0, 'bytes' => 0, 'recent' => 0, 'retained' => 0, 'failed' => 0];
        foreach ($result as $value) {
            if (!is_array($value)) {
                continue;
            }
            $totals['scanned'] += max(0, (int)($value['scanned'] ?? 0));
            $totals['deleted'] += max(0, (int)($value['deleted'] ?? 0));
            $totals['bytes'] += max(0, (int)($value['bytes'] ?? 0));
            $totals['recent'] += max(0, (int)($value['recent'] ?? 0));
            $totals['retained'] += max(
                0,
                (int)($value['retained'] ?? 0) + (int)($value['referenced'] ?? 0) + (int)($value['active'] ?? 0)
            );
            $totals['failed'] += max(0, (int)($value['failed'] ?? 0));
        }
        return $totals;
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2)) . ' ' . $unit;
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }

    private function uploadStaleSeconds(): int
    {
        $chunk = is_array($this->config['chunk_upload'] ?? null) ? $this->config['chunk_upload'] : [];
        return max(3600, min((int)($chunk['stale_hours'] ?? 168) * 3600, 90 * 86400));
    }

    /** @return array{bytes:int,modified:int} */
    private function treeStats(string $path): array
    {
        $bytes = 0;
        $modified = 0;
        if (!is_dir($path)) {
            return ['bytes' => 0, 'modified' => 0];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $bytes += max(0, (int)$entry->getSize());
            $modified = max($modified, (int)$entry->getMTime());
        }
        return ['bytes' => $bytes, 'modified' => $modified];
    }

    private function deleteTree(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        $ok = true;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->deleteTree($path . DIRECTORY_SEPARATOR . $entry)) {
                $ok = false;
            }
        }
        return @rmdir($path) && $ok;
    }

    private function removeEmptyDirectories(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            }
        }
    }

    private function storageRelativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->storageRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with(strtolower($normalized), strtolower($root))) {
            return '';
        }
        return ltrim(substr($normalized, strlen($root)), '/');
    }
}
