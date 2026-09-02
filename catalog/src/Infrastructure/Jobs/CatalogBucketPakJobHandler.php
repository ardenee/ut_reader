<?php
/**
 * Retains an Upload Bucket PAK as a container and indexes supported extracted
 * standalone packages as ordinary strict unverified files.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketIdentityProcessor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketPakContainerStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportPathPolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogBucketPakJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 1;
    private const UNIT_PREFIX = 'bucket-pak-entry:';

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogPakArchive.php';
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::PROCESS_BUCKET_UPLOAD, JobType::PROCESS_BUCKET_STAGED_PACKAGE], true);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ((int)($job->payload['bucket_pak_member_id'] ?? 0) > 0) {
            return $this->handleMember($job, $context);
        }
        return $this->handleParent($job, $context);
    }

    /** @return array<string,mixed> */
    private function handleParent(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $originalName = CatalogImportPathPolicy::filename((string)($payload['original_name'] ?? 'archive.pak'));
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new \InvalidArgumentException('Upload Bucket PAK handler received a non-PAK source.');
        }
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId < 1) {
            throw new \InvalidArgumentException('Upload Bucket PAK job has no administrator identity.');
        }

        $resume = $context->resumeProgress();
        $resumeVersion = (int)($resume['workflow_version'] ?? 0);
        $resumeStage = (string)($resume['stage'] ?? '');
        $resumeParentId = (int)($resume['parent_file_id'] ?? 0);
        if ($resumeVersion === self::WORKFLOW_VERSION && $resumeParentId > 0) {
            if ($resumeStage === 'pak_wait') {
                return $this->waitForMembers($job, $context, $resumeParentId);
            }
            if ($resumeStage === 'pak_plan') {
                return $this->resumeMemberPlanning($job, $context, $resumeParentId, $userId, $originalName);
            }
        }

        $sourcePath = $this->parentSourcePath($job);
        $sourceRelativePath = CatalogImportPathPolicy::relative(
            (string)($payload['source_relative_path'] ?? $originalName)
        );
        $size = (int)(filesize($sourcePath) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('Upload Bucket PAK source is empty.');
        }

        $md5 = strtolower(trim((string)($payload['package_md5'] ?? $payload['source_md5'] ?? '')));
        $sha1 = strtolower(trim((string)($payload['package_sha1'] ?? $payload['source_sha1'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            $value = hash_file('md5', $sourcePath);
            $md5 = is_string($value) ? strtolower($value) : '';
        }
        if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            $value = hash_file('sha1', $sourcePath);
            $sha1 = is_string($value) ? strtolower($value) : '';
        }
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Could not calculate retained PAK identity.');
        }

        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'pak_extract',
            'done' => 5,
            'total' => 100,
            'percent' => 5,
            'message' => 'Reading PAK index and extracting contained files.',
        ]);

        try {
            $extracted = \catalog_pak_archive_extract_to_temp($this->config, $sourcePath, $originalName);
        } catch (\RuntimeException $error) {
            if (!$this->isNonUnrealPakResource($error)) {
                throw $error;
            }

            // .pak is also used by non-Unreal software/components (for example
            // Chromium/CEF resources). The extension is legitimate, but without
            // an Unreal FPakInfo magic footer this is an intentional exclusion,
            // not a damaged Unreal archive and not an operator issue.
            $this->cleanupParentSource($job);
            CatalogSystemErrorRecorder::resolveNonUnrealPakJob($job->id);
            $message = 'Excluded non-Unreal .pak resource ' . $originalName
                . ': no Unreal PAK magic footer was found.';
            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'complete',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => 'excluded',
                'message' => $message,
                'classification' => 'non_unreal_pak',
            ]);
            return [
                'operation' => 'process_bucket_pak',
                'status' => 'excluded',
                'file_id' => 0,
                'original_name' => $originalName,
                'source_relative_path' => $sourceRelativePath,
                'classification' => 'non_unreal_pak',
                'message' => $message,
            ];
        }
        $workDir = (string)$extracted['dir'];
        try {
            $files = array_values((array)$extracted['files']);
            $reason = 'Uploaded to the unsorted Upload Bucket as a retained Unreal PAK container on '
                . date('Y-m-d H:i:s') . '. Contained supported packages are indexed separately and linked to this PAK.';
            $parent = (new CatalogBucketPakContainerStore($this->db, $this->config))->publishParent(
                $sourcePath,
                $originalName,
                $reason,
                $userId,
                $sourceRelativePath,
                $md5,
                $sha1,
                (string)($extracted['log'] ?? ''),
                count($files)
            );
            if ((string)$parent['status'] === 'duplicate') {
                $this->cleanupParentSource($job);
                $message = (string)$parent['message'];
                $context->checkpoint([
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'stage' => 'complete',
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'status' => 'duplicate',
                    'file_id' => (int)$parent['file_id'],
                    'message' => $message,
                ]);
                return [
                    'operation' => 'process_bucket_pak',
                    'status' => 'duplicate',
                    'file_id' => (int)$parent['file_id'],
                    'original_name' => $originalName,
                    'message' => $message,
                ];
            }

            $parentFileId = (int)$parent['file_id'];
            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'pak_plan',
                'done' => 10,
                'total' => 100,
                'percent' => 10,
                'parent_file_id' => $parentFileId,
                'message' => 'PAK container retained; planning durable contained-package jobs.',
            ]);
            $this->planMembers($job, $context, $parentFileId, $files, $userId);
            $this->checkpointWait($context, $parentFileId);
            $this->cleanupParentSource($job);
        } finally {
            if ($workDir !== '') {
                \catalog_pak_archive_delete_tree($workDir);
            }
        }

        return $this->waitForMembers($job, $context, $parentFileId);
    }

    /** @return array<string,mixed> */
    private function resumeMemberPlanning(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $parentFileId,
        int $userId,
        string $originalName
    ): array {
        $sourcePath = $this->parentSourcePath($job);
        $context->heartbeatIfDue([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'pak_plan',
            'done' => 10,
            'total' => 100,
            'percent' => 10,
            'parent_file_id' => $parentFileId,
            'message' => 'Resuming contained-package planning for retained PAK file #' . $parentFileId . '.',
        ]);
        $extracted = \catalog_pak_archive_extract_to_temp($this->config, $sourcePath, $originalName);
        $workDir = (string)$extracted['dir'];
        try {
            $this->planMembers(
                $job,
                $context,
                $parentFileId,
                array_values((array)$extracted['files']),
                $userId
            );
            $this->checkpointWait($context, $parentFileId);
            $this->cleanupParentSource($job);
        } finally {
            if ($workDir !== '') {
                \catalog_pak_archive_delete_tree($workDir);
            }
        }
        return $this->waitForMembers($job, $context, $parentFileId);
    }

    private function checkpointWait(JobExecutionContext $context, int $parentFileId): void
    {
        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'pak_wait',
            'done' => 60,
            'total' => 100,
            'percent' => 60,
            'parent_file_id' => $parentFileId,
            'message' => 'Retained PAK container; waiting for extracted package inspection jobs.',
        ]);
    }

    /** @param list<array<string,mixed>> $files */
    private function planMembers(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $parentFileId,
        array $files,
        int $userId
    ): void {
        $store = new CatalogBucketPakContainerStore($this->db, $this->config);
        $incoming = new CatalogIncomingFileStore($this->config);
        $allowed = array_fill_keys(
            (new CatalogUploadBucketFilePolicy($this->db, $this->config))->allowedPackageExtensions(),
            true
        );
        foreach (['pak', 'zip', '7z', 'rar', 'uexp', 'ubulk', 'uptnl', 'm_ubulk'] as $extension) {
            unset($allowed[$extension]);
        }
        $queue = new PdoJobQueue($this->db);
        $existingUnits = $this->existingMemberUnits($job->id);
        $total = max(1, count($files));
        $queued = 0;

        foreach ($files as $index => $file) {
            $context->heartbeatIfDue();
            $entryPath = CatalogImportPathPolicy::relative((string)($file['relative'] ?? ''));
            $entryName = CatalogImportPathPolicy::filename(basename($entryPath !== '' ? $entryPath : (string)($file['path'] ?? 'entry.bin')));
            $extension = strtolower((string)pathinfo($entryName, PATHINFO_EXTENSION));
            if ($entryPath === '' || !isset($allowed[$extension])) {
                $store->ensureMember(
                    $parentFileId,
                    (int)$index,
                    $entryPath !== '' ? $entryPath : $entryName,
                    $entryName,
                    $extension,
                    'skipped',
                    $extension === 'pak'
                        ? 'Nested PAK containers are not recursively expanded in the Upload Bucket.'
                        : 'Entry type is not a standalone catalogued package.'
                );
                continue;
            }

            $physical = (string)($file['path'] ?? '');
            if (!is_file($physical) || !is_readable($physical) || is_link($physical)) {
                $store->ensureMember(
                    $parentFileId,
                    (int)$index,
                    $entryPath,
                    $entryName,
                    $extension,
                    'rejected',
                    'Extracted PAK entry is unavailable.'
                );
                continue;
            }

            $unitKey = self::UNIT_PREFIX . (int)$index;
            if (isset($existingUnits[$unitKey])) {
                $queued++;
                continue;
            }

            $memberId = 0;
            $staged = null;
            try {
                $memberId = $store->ensureMember(
                    $parentFileId,
                    (int)$index,
                    $entryPath,
                    $entryName,
                    $extension,
                    'queued',
                    'Queued for strict package inspection.'
                );
                $staged = $incoming->stageLocalFile($physical, $entryName);
                $dedupe = 'bucket-pak-entry:' . $parentFileId . ':' . (int)$index . ':'
                    . strtolower((string)$staged['sha256']);
                $queue->enqueue(
                    $job->queue,
                    JobType::PROCESS_BUCKET_STAGED_PACKAGE,
                    [
                        'staged_path' => (string)$staged['relative_path'],
                        'original_name' => $entryName,
                        'source_relative_path' => $entryPath,
                        'user_id' => $userId,
                        'size' => (int)$staged['size'],
                        'sha256' => (string)$staged['sha256'],
                        'bucket_pak_parent_file_id' => $parentFileId,
                        'bucket_pak_member_id' => $memberId,
                        'bucket_pak_entry_path' => $entryPath,
                    ],
                    8,
                    null,
                    $dedupe,
                    $userId,
                    3,
                    $job->id,
                    $unitKey
                );
                $existingUnits[$unitKey] = true;
                $queued++;
                $staged = null;
            } catch (Throwable $error) {
                if (is_array($staged)) {
                    try {
                        $incoming->delete((string)($staged['relative_path'] ?? ''));
                    } catch (Throwable) {
                    }
                }
                if ($this->infrastructureFailure($error)) {
                    throw $error;
                }
                if ($memberId > 0) {
                    $store->completeMember($memberId, 'rejected', null, false, $error->getMessage());
                }
            }

            $percent = 10 + (int)floor((min(count($files), (int)$index + 1) * 45) / $total);
            $context->heartbeatIfDue([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'pak_plan',
                'done' => min(55, $percent),
                'total' => 100,
                'percent' => min(55, $percent),
                'parent_file_id' => $parentFileId,
                'queued_members' => $queued,
                'message' => 'Preparing PAK member ' . ((int)$index + 1) . ' of ' . count($files) . '.',
            ]);
        }
    }

    /** @return array<string,true> */
    private function existingMemberUnits(int $parentJobId): array
    {
        $statement = $this->db->prepare(
            'SELECT workflow_unit_key FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key LIKE ?'
        );
        $statement->execute([$parentJobId, self::UNIT_PREFIX . '%']);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $key) {
            $key = trim((string)$key);
            if ($key !== '') {
                $out[$key] = true;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function waitForMembers(ClaimedJob $job, JobExecutionContext $context, int $parentFileId): array
    {
        $state = (new PdoWorkflowChildStateQuery($this->db))->fetch($job->id, self::UNIT_PREFIX);
        if (($state['queued'] + $state['running']) > 0) {
            $finished = $state['completed'] + $state['failed'] + $state['dead_letter'] + $state['cancelled'];
            $percent = 60 + (int)floor((min($state['total'], $finished) * 39) / max(1, $state['total']));
            $context->defer(2, [
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'pak_wait',
                'done' => min(99, $percent),
                'total' => 100,
                'percent' => min(99, $percent),
                'parent_file_id' => $parentFileId,
                'children' => $state,
                'message' => 'PAK member jobs: ' . $finished . '/' . $state['total']
                    . ' finished, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
            ]);
        }

        $summary = (new CatalogBucketPakContainerStore($this->db, $this->config))->finishParent($parentFileId);
        $problemJobs = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
        $message = 'PAK retained in Upload Bucket: ' . $summary['indexed'] . ' contained package(s) indexed, '
            . $summary['duplicate'] . ' duplicate(s), ' . $summary['skipped'] . ' skipped, '
            . $summary['rejected'] . ' rejected.';
        if ($problemJobs > 0) {
            $message .= ' ' . $problemJobs . ' infrastructure/cancelled child job(s) remain reviewable separately.';
        }
        $context->checkpoint([
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'status' => $problemJobs > 0 || $summary['rejected'] > 0 ? 'partial' : 'bucketed',
            'file_id' => $parentFileId,
            'children' => $state,
            'member_summary' => $summary,
            'message' => $message,
        ]);
        return [
            'operation' => 'process_bucket_pak',
            'status' => $problemJobs > 0 || $summary['rejected'] > 0 ? 'partial' : 'bucketed',
            'file_id' => $parentFileId,
            'member_summary' => $summary,
            'children' => $state,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function handleMember(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $memberId = (int)($payload['bucket_pak_member_id'] ?? 0);
        $parentFileId = (int)($payload['bucket_pak_parent_file_id'] ?? 0);
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $originalName = CatalogImportPathPolicy::filename((string)($payload['original_name'] ?? 'package.bin'));
        $relativePath = CatalogImportPathPolicy::relative((string)($payload['source_relative_path'] ?? $originalName));
        $userId = (int)($payload['user_id'] ?? 0);
        if ($memberId < 1 || $parentFileId < 1 || $stagedPath === '' || $userId < 1) {
            throw new \InvalidArgumentException('Upload Bucket PAK member payload is incomplete.');
        }

        $store = new CatalogBucketPakContainerStore($this->db, $this->config);
        $preparedStore = new CatalogPreparedJobFileStore($this->config, $job->id, 'bucket-pak-entry');
        $prepared = $preparedStore->load();
        if (!is_array($prepared)) {
            $source = (new CatalogIncomingFileStore($this->config))->resolve($stagedPath);
            $md5 = hash_file('md5', $source);
            $sha1 = hash_file('sha1', $source);
            if (!is_string($md5) || !is_string($sha1)) {
                throw new \RuntimeException('Could not hash extracted PAK package.');
            }
            $prepared = $preparedStore->publish($source, $originalName, [
                'md5' => strtolower($md5),
                'sha1' => strtolower($sha1),
                'source_relative_path' => $relativePath,
            ]);
        }

        $working = $this->workingCopy((string)$prepared['path'], $originalName);
        try {
            $context->checkpoint([
                'stage' => 'pak_member',
                'done' => 45,
                'total' => 100,
                'percent' => 45,
                'parent_file_id' => $parentFileId,
                'member_id' => $memberId,
                'message' => 'Strictly reading extracted PAK package ' . $relativePath . '.',
            ]);
            $staged = (new CatalogBucketIdentityProcessor($this->db, $this->config))->stage(
                $working,
                $originalName,
                'Extracted from retained Upload Bucket PAK file #' . $parentFileId . ': ' . $relativePath,
                $userId,
                $relativePath,
                strtolower((string)$prepared['md5']),
                strtolower((string)$prepared['sha1']),
                static function (array $progress) use ($context): void {
                    $context->heartbeatIfDue($progress);
                }
            );
            $working = '';
            $status = (string)($staged['status'] ?? 'indexed');
            $fileId = (int)($staged['file_id'] ?? 0);
            $store->completeMember(
                $memberId,
                $status === 'duplicate' ? 'duplicate' : 'indexed',
                $fileId > 0 ? $fileId : null,
                $status !== 'duplicate',
                (string)($staged['message'] ?? 'Extracted package indexed.')
            );
            $preparedStore->clear();
            return [
                'operation' => 'process_bucket_pak_entry',
                'status' => $status === 'duplicate' ? 'duplicate' : 'indexed',
                'file_id' => $fileId,
                'parent_file_id' => $parentFileId,
                'member_id' => $memberId,
                'original_name' => $originalName,
                'source_relative_path' => $relativePath,
                'message' => (string)($staged['message'] ?? 'Extracted PAK package indexed.'),
            ];
        } catch (Throwable $error) {
            if ($this->infrastructureFailure($error)) {
                throw $error;
            }
            $store->completeMember($memberId, 'rejected', null, false, $error->getMessage());
            $preparedStore->clear();
            return [
                'operation' => 'process_bucket_pak_entry',
                'status' => 'rejected',
                'file_id' => 0,
                'parent_file_id' => $parentFileId,
                'member_id' => $memberId,
                'original_name' => $originalName,
                'source_relative_path' => $relativePath,
                'message' => 'Excluded invalid/unreadable contained package: ' . trim($error->getMessage()),
            ];
        } finally {
            if ($working !== '' && is_file($working)) {
                @unlink($working);
            }
        }
    }

    private function isNonUnrealPakResource(Throwable $error): bool
    {
        return str_starts_with(
            trim($error->getMessage()),
            'Unsupported PAK file: no Unreal PAK magic footer was found.'
        );
    }

    private function parentSourcePath(ClaimedJob $job): string
    {
        if ($job->type === JobType::PROCESS_BUCKET_UPLOAD) {
            $uploadId = trim((string)($job->payload['upload_id'] ?? ''));
            $userId = (int)($job->payload['user_id'] ?? 0);
            if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
                throw new \RuntimeException('Completed PAK upload identity is invalid.');
            }
            return (string)$this->chunkStore()->resolveCompletedFile($uploadId, $userId)['path'];
        }
        $stagedPath = trim((string)($job->payload['staged_path'] ?? ''));
        if ($stagedPath === '') {
            throw new \RuntimeException('Staged PAK source is missing.');
        }
        return (new CatalogIncomingFileStore($this->config))->resolve($stagedPath);
    }

    private function cleanupParentSource(ClaimedJob $job): void
    {
        try {
            if ($job->type === JobType::PROCESS_BUCKET_UPLOAD) {
                $uploadId = trim((string)($job->payload['upload_id'] ?? ''));
                if (preg_match('/^[a-f0-9]{64}$/', $uploadId) === 1) {
                    (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
                }
                return;
            }
            $stagedPath = trim((string)($job->payload['staged_path'] ?? ''));
            if ($stagedPath !== '') {
                (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB bucket PAK cleanup] job=' . $job->id . ' ' . $error->getMessage());
        }
    }

    private function chunkStore(): CatalogChunkedUploadStore
    {
        $config = $this->config;
        $config['max_upload_bytes'] = PHP_INT_MAX;
        $config['max_container_upload_bytes'] = PHP_INT_MAX;
        return new CatalogChunkedUploadStore($config);
    }

    private function workingCopy(string $sourcePath, string $name): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Prepared PAK member is unavailable.');
        }
        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $path = dirname($sourcePath) . DIRECTORY_SEPARATOR . '.pak-member-'
            . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!@copy($sourcePath, $path)) {
            throw new \RuntimeException('Could not create disposable PAK member working copy.');
        }
        $sourceSize = filesize($sourcePath);
        $copySize = filesize($path);
        if ($sourceSize === false || $copySize === false || (int)$sourceSize !== (int)$copySize) {
            @unlink($path);
            throw new \RuntimeException('Disposable PAK member working copy is incomplete.');
        }
        return $path;
    }

    private function infrastructureFailure(Throwable $error): bool
    {
        if ($error instanceof PDOException) {
            return true;
        }
        $message = strtolower($error->getMessage());
        foreach (['deadlock', 'lock wait', 'sqlstate', 'database schema', 'storage path', 'storage folder', 'could not create', 'disk'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }
}
