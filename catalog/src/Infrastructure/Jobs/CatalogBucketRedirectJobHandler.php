<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketUploadProcessor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

/** Compatibility handler for jobs created by the earlier per-file redirect flow. */
final class CatalogBucketRedirectJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::PREPARE_BUCKET_REDIRECT;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $sourceKind = trim((string)($payload['source_kind'] ?? 'chunk-upload'));
        $uploadId = trim((string)($payload['upload_id'] ?? ''));
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $originalName = $this->requiredName((string)($payload['original_name'] ?? ''));
        $relativePath = $this->cleanRelativePath((string)($payload['source_relative_path'] ?? $originalName));
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId < 1) {
            throw new \InvalidArgumentException('Bucket redirect job payload is incomplete.');
        }
        if ($sourceKind === 'chunk-upload' && preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Bucket redirect chunk identifier is invalid.');
        }
        if ($sourceKind === 'incoming-file' && $stagedPath === '') {
            throw new \InvalidArgumentException('Bucket redirect staged path is missing.');
        }
        if (!in_array($sourceKind, ['chunk-upload', 'incoming-file'], true)) {
            throw new \InvalidArgumentException('Bucket redirect source kind is invalid.');
        }

        $decodedPath = '';
        try {
            $context->checkpoint([
                'stage' => 'source_resolve',
                'done' => 2,
                'total' => 100,
                'percent' => 2,
                'message' => 'Resolving retained redirect source.',
            ]);
            $sourcePath = $this->resolveSource($sourceKind, $uploadId, $stagedPath, $userId);

            $context->checkpoint([
                'stage' => 'redirect_decompress',
                'done' => 5,
                'total' => 100,
                'percent' => 5,
                'message' => 'Starting redirect decompression.',
            ]);
            $decoded = (new CatalogRedirectArchiveProcessor($this->config))->decompressToTemp(
                $sourcePath,
                $originalName,
                static function (array $progress) use ($context): void {
                    $sourcePercent = max(0, min(100, (int)($progress['percent'] ?? 0)));
                    $context->checkpoint([
                        'stage' => 'redirect_decompress',
                        'done' => (int)($progress['compressed_done'] ?? 0),
                        'total' => max(1, (int)($progress['compressed_total'] ?? 1)),
                        'percent' => max(5, min(40, 5 + (int)floor($sourcePercent * 35 / 100))),
                        'message' => (string)($progress['message'] ?? 'Decompressing redirect archive.'),
                        'compressed_bytes' => (int)($progress['compressed_done'] ?? 0),
                        'output_bytes' => (int)($progress['output_bytes'] ?? 0),
                        'chunks' => (int)($progress['chunks'] ?? 0),
                    ]);
                },
                true
            );
            $decodedPath = (string)$decoded['path'];
            $workingName = $this->requiredName((string)$decoded['filename']);
            $this->validateOutputExtension($workingName);
            $relativePath = $this->replaceRelativeFilename($relativePath, $workingName);

            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s')
                . '. No game assignment has been made yet. Legacy redirect job was decompressed by the CLI worker. Decoder: '
                . (string)$decoded['decoder'] . '. Original wrapper: ' . $originalName . '.';
            $staged = (new CatalogBucketUploadProcessor($this->db, $this->config))->stage(
                $decodedPath,
                $workingName,
                $note,
                $userId,
                $relativePath,
                static function (array $progress) use ($context): void {
                    $context->checkpoint($progress);
                }
            );
            $decodedPath = '';

            if ($sourceKind === 'chunk-upload') {
                (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
            } else {
                (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
            }

            $status = (string)($staged['status'] ?? 'indexed');
            $resultStatus = $status === 'duplicate' ? 'duplicate' : 'decompressed';
            $message = (string)($staged['message'] ?? 'Redirect processing completed.');
            if ($status !== 'duplicate' && $staged['parse_error'] !== null) {
                $message .= ' Package tables could not be read: ' . trim((string)$staged['parse_error']);
            }

            $context->checkpoint([
                'stage' => 'complete',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => $resultStatus,
                'message' => $message,
                'file_id' => (int)$staged['file_id'],
            ]);

            return [
                'operation' => 'prepare_bucket_redirect',
                'status' => $resultStatus,
                'message' => $message,
                'file_id' => (int)$staged['file_id'],
                'queue_name' => (string)$staged['queue_name'],
                'original_name' => $workingName,
                'source_relative_path' => $relativePath,
                'bytes' => (int)$staged['size'],
                'compressed_bytes' => (int)$decoded['compressed_bytes'],
                'decoder' => (string)$decoded['decoder'],
                'md5' => (string)($staged['md5'] ?? ''),
                'sha1' => (string)($staged['sha1'] ?? ''),
            ];
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (Throwable $error) {
            try {
                $context->checkpoint([
                    'stage' => 'failed',
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'status' => 'failed',
                    'message' => trim($error->getMessage()) ?: 'Redirect archive processing failed.',
                ]);
            } catch (Throwable) {
            }
            // Throw so the queue applies attempts, retry delay and dead-letter state.
            throw $error;
        } finally {
            if ($decodedPath !== '' && is_file($decodedPath)) {
                @unlink($decodedPath);
            }
        }
    }

    private function resolveSource(string $sourceKind, string $uploadId, string $stagedPath, int $userId): string
    {
        if ($sourceKind === 'chunk-upload') {
            return (string)$this->chunkStore()->resolveCompletedFile($uploadId, $userId)['path'];
        }
        return (new CatalogIncomingFileStore($this->config))->resolve($stagedPath);
    }

    private function chunkStore(): CatalogChunkedUploadStore
    {
        $config = $this->config;
        $config['max_upload_bytes'] = PHP_INT_MAX;
        $config['max_container_upload_bytes'] = PHP_INT_MAX;
        return new CatalogChunkedUploadStore($config);
    }

    private function validateOutputExtension(string $name): void
    {
        $allowed = [];
        foreach (\gp_all_profiles($this->db) as $profile) {
            foreach (\gp_extensions($profile) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '') $allowed[$extension] = true;
            }
        }
        if ($allowed === []) {
            foreach (($this->config['allowed_extensions'] ?? []) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '') $allowed[$extension] = true;
            }
        }
        $extension = \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($allowed !== [] && !isset($allowed[$extension])) {
            throw new \InvalidArgumentException(
                'Extension .' . ($extension !== '' ? $extension : '(none)')
                . ' is not allowed by any active game profile.'
            );
        }
    }

    private function replaceRelativeFilename(string $relativePath, string $name): string
    {
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '. /');
        return ($directory !== '' ? $directory . '/' : '') . $name;
    }

    private function requiredName(string $name): string
    {
        $name = \catalog_clean_unreal_filename(basename(str_replace('\\', '/', trim($name))));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Bucket redirect filename is missing.');
        }
        return $name;
    }

    private function cleanRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace(["\0", '\\'], ['', '/'], $path), '/')) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                if ($parts !== []) array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
