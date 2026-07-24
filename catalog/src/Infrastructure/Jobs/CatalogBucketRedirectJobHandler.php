<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

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
                'stage' => 'redirect_resolve',
                'done' => 2,
                'total' => 100,
                'percent' => 2,
                'message' => 'Resolving Upload Bucket redirect ' . basename($originalName),
            ]);
            $sourcePath = $this->resolveSource($sourceKind, $uploadId, $stagedPath, $userId);

            $processor = new CatalogRedirectArchiveProcessor($this->config);
            $context->checkpoint([
                'stage' => 'redirect_decompress',
                'done' => 3,
                'total' => 100,
                'percent' => 3,
                'message' => 'Starting redirect decompression for ' . basename($originalName),
            ]);
            $decoded = $processor->decompressToTemp(
                $sourcePath,
                $originalName,
                static function (array $progress) use ($context): void {
                    $sourcePercent = max(0, min(100, (int)($progress['percent'] ?? 0)));
                    $context->checkpoint([
                        'stage' => 'redirect_decompress',
                        'done' => (int)($progress['compressed_done'] ?? 0),
                        'total' => max(1, (int)($progress['compressed_total'] ?? 1)),
                        'percent' => max(3, min(70, 3 + (int)floor($sourcePercent * 67 / 100))),
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

            $context->checkpoint([
                'stage' => 'bucket_stage',
                'done' => 75,
                'total' => 100,
                'percent' => 75,
                'message' => 'Duplicate-checking and indexing ' . basename($workingName),
            ]);

            $cleanNote = $originalName !== $workingName
                ? ' Original browser filename was: ' . $originalName . '.'
                : '';
            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s')
                . '. No game assignment has been made yet. Redirect archive .'
                . (string)$decoded['source_extension']
                . ' was decompressed by the detached CLI worker before storage; compressed wrapper was not retained. Decoder: '
                . (string)$decoded['decoder'] . '.' . $cleanNote;

            $staged = (new LegacyUnverifiedFileStager($this->db, $this->config))->stageBucketUpload(
                $decodedPath,
                $workingName,
                $note,
                $userId,
                $this->replaceRelativeFilename($relativePath, $workingName)
            );
            $decodedPath = '';

            $status = (string)($staged['status'] ?? 'stored');
            $message = $status === 'duplicate'
                ? (string)$staged['message']
                : 'Decompressed redirect archive into Upload Bucket and indexed as unverified using '
                    . (string)$decoded['decoder'];
            if ($status !== 'duplicate' && $staged['parse_error'] !== null) {
                $message .= '; package tables could not be read: ' . trim((string)$staged['parse_error']);
            }

            $context->checkpoint([
                'stage' => 'completed',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => $status === 'duplicate' ? 'duplicate' : 'decompressed',
                'message' => $message,
            ]);

            return [
                'operation' => 'prepare_bucket_redirect',
                'status' => $status === 'duplicate' ? 'duplicate' : 'decompressed',
                'message' => $message,
                'file_id' => (int)$staged['file_id'],
                'queue_name' => (string)$staged['queue_name'],
                'original_name' => $workingName,
                'source_relative_path' => $this->replaceRelativeFilename($relativePath, $workingName),
                'bytes' => (int)$staged['size'],
                'compressed_bytes' => (int)$decoded['compressed_bytes'],
                'decoder' => (string)$decoded['decoder'],
                'md5' => (string)($staged['md5'] ?? ''),
            ];
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (PDOException $error) {
            throw $error;
        } catch (Throwable $error) {
            $message = $this->shortError($error);
            $context->checkpoint([
                'stage' => 'failed',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => 'failed',
                'message' => $message,
            ]);
            return [
                'operation' => 'prepare_bucket_redirect',
                'status' => 'failed',
                'message' => $message,
                'file_id' => 0,
                'original_name' => $originalName,
                'source_relative_path' => $relativePath,
            ];
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
                if ($extension !== '') {
                    $allowed[$extension] = true;
                }
            }
        }
        if ($allowed === []) {
            foreach (($this->config['allowed_extensions'] ?? []) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '') {
                    $allowed[$extension] = true;
                }
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
        $path = trim(str_replace(["\0", '\\'], ['', '/'], $path), '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
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

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
        return trim($message) !== '' ? trim($message) : 'Redirect archive processing failed.';
    }
}
