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
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

final class CatalogBucketUploadJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchive.php';
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::PROCESS_BUCKET_UPLOAD;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $uploadId = trim((string)($payload['upload_id'] ?? ''));
        $originalName = $this->requiredName((string)($payload['original_name'] ?? ''));
        $relativePath = $this->cleanRelativePath((string)($payload['source_relative_path'] ?? $originalName));
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Deferred Upload Bucket job payload is incomplete.');
        }

        $workingPath = '';
        try {
            $context->checkpoint([
                'stage' => 'source_resolve',
                'done' => 2,
                'total' => 100,
                'percent' => 2,
                'message' => 'Resolving the completed browser upload.',
            ]);
            $source = $this->chunkStore()->resolveCompletedFile($uploadId, $userId);
            $sourcePath = (string)$source['path'];
            $workingName = $originalName;
            $redirect = \catalog_redirect_archive_is_supported_filename($originalName);
            $decoder = '';
            $compressedBytes = 0;

            if ($redirect) {
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
                $workingPath = (string)$decoded['path'];
                $workingName = $this->requiredName((string)$decoded['filename']);
                $decoder = (string)$decoded['decoder'];
                $compressedBytes = (int)$decoded['compressed_bytes'];
                $relativePath = $this->replaceRelativeFilename($relativePath, $workingName);
            } else {
                $this->validateOutputExtension($workingName);
                $context->checkpoint([
                    'stage' => 'source_copy',
                    'done' => 5,
                    'total' => 100,
                    'percent' => 5,
                    'message' => 'Preparing the uploaded package for isolated processing.',
                ]);
                $workingPath = $this->copyToWorkingFile($sourcePath, $job->id, $context);
            }

            $this->validateOutputExtension($workingName);
            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s')
                . '. No game assignment has been made yet.';
            if ($redirect) {
                $note .= ' Redirect archive was decompressed after the complete browser batch finished. Decoder: '
                    . $decoder . '. Original wrapper: ' . $originalName . '.';
            } else {
                $note .= ' Package processing began only after the complete browser upload batch finished.';
            }

            $processor = new CatalogBucketUploadProcessor($this->db, $this->config);
            $staged = $processor->stage(
                $workingPath,
                $workingName,
                $note,
                $userId,
                $relativePath,
                static function (array $progress) use ($context): void {
                    $context->checkpoint($progress);
                }
            );
            $workingPath = '';

            // Successful processing has created the durable bucket copy or found
            // an existing duplicate. The browser staging source is no longer needed.
            (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);

            $status = (string)($staged['status'] ?? 'indexed');
            $resultStatus = $status === 'duplicate' ? 'duplicate' : 'bucketed';
            $message = (string)($staged['message'] ?? 'Upload Bucket processing completed.');
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
                'operation' => 'process_bucket_upload',
                'status' => $resultStatus,
                'message' => $message,
                'file_id' => (int)$staged['file_id'],
                'queue_name' => (string)$staged['queue_name'],
                'original_name' => $workingName,
                'source_relative_path' => $relativePath,
                'bytes' => (int)$staged['size'],
                'compressed_bytes' => $compressedBytes,
                'decoder' => $decoder,
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
                    'message' => $this->shortError($error),
                ]);
            } catch (Throwable) {
                // Preserve the original processing exception for retry/dead-letter handling.
            }
            throw $error;
        } finally {
            if ($workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }
    }

    private function copyToWorkingFile(string $sourcePath, int $jobId, JobExecutionContext $context): string
    {
        $storage = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storage === '') {
            throw new \RuntimeException('Catalog storage path is unavailable.');
        }
        $directory = $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'bucket-working';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create Upload Bucket working storage.');
        }
        $destination = $directory . DIRECTORY_SEPARATOR . 'job-' . $jobId . '-' . bin2hex(random_bytes(6)) . '.part';
        $input = fopen($sourcePath, 'rb');
        $output = fopen($destination, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($destination);
            throw new \RuntimeException('Could not open Upload Bucket working copy.');
        }
        $size = (int)(filesize($sourcePath) ?: 0);
        $done = 0;
        $lastReport = microtime(true);
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read the uploaded source package.');
                }
                if ($buffer === '') {
                    if (feof($input)) break;
                    throw new \RuntimeException('Uploaded source copy stopped before end of file.');
                }
                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $written = fwrite($output, substr($buffer, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Could not write the Upload Bucket working copy.');
                    }
                    $offset += $written;
                }
                $done += $length;
                $now = microtime(true);
                if (($now - $lastReport) >= 1.0 || $done >= $size) {
                    $fraction = $size > 0 ? min(1, $done / $size) : 1;
                    $context->checkpoint([
                        'stage' => 'source_copy',
                        'done' => $done,
                        'total' => max(1, $size),
                        'percent' => 5 + (int)floor($fraction * 35),
                        'message' => 'Preparing working copy: ' . $done . ' of ' . $size . ' bytes.',
                        'bytes_done' => $done,
                        'bytes_total' => $size,
                    ]);
                    $lastReport = $now;
                }
            }
            fflush($output);
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($size < 1 || $done !== $size) {
            @unlink($destination);
            throw new \RuntimeException('Upload Bucket working copy size is incomplete.');
        }
        return $destination;
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
            throw new \InvalidArgumentException('Upload Bucket filename is missing.');
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

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? $message : 'Upload Bucket processing failed.';
    }
}
