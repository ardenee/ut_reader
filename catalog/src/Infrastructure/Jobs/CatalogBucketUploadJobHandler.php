<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogBucketUploadJobHandler` for catalog bucket upload job handler.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketIdentityProcessor;
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
            $packageMd5 = '';
            $packageSha1 = '';

            if ($redirect) {
                $context->checkpoint([
                    'stage' => 'redirect_decompress',
                    'done' => 5,
                    'total' => 100,
                    'percent' => 5,
                    'message' => 'Starting redirect decompression. Package hashes will be calculated from the decompressed output.',
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
                            'message' => (string)($progress['message'] ?? 'Decompressing and hashing redirect archive.'),
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
                $packageMd5 = strtolower(trim((string)($decoded['md5'] ?? '')));
                $packageSha1 = strtolower(trim((string)($decoded['sha1'] ?? '')));
                $relativePath = $this->replaceRelativeFilename($relativePath, $workingName);
            } else {
                $this->validateOutputExtension($workingName);
                $context->checkpoint([
                    'stage' => 'source_copy',
                    'done' => 5,
                    'total' => 100,
                    'percent' => 5,
                    'message' => 'Preparing the uploaded package and verifying its browser-calculated MD5/SHA-1.',
                ]);
                $prepared = $this->copyToWorkingFile(
                    $sourcePath,
                    $job->id,
                    $context,
                    strtolower(trim((string)($payload['package_md5'] ?? $payload['source_md5'] ?? ''))),
                    strtolower(trim((string)($payload['package_sha1'] ?? $payload['source_sha1'] ?? '')))
                );
                $workingPath = $prepared['path'];
                $packageMd5 = $prepared['md5'];
                $packageSha1 = $prepared['sha1'];
            }

            if (preg_match('/^[a-f0-9]{32}$/', $packageMd5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $packageSha1) !== 1) {
                throw new \RuntimeException('The prepared package identity could not be calculated.');
            }

            $this->validateOutputExtension($workingName);
            $note = 'Uploaded to the unsorted Upload Bucket on ' . date('Y-m-d H:i:s')
                . '. No game assignment has been made yet.';
            if ($redirect) {
                $note .= ' Redirect archive was decompressed after the complete browser batch finished. Decoder: '
                    . $decoder . '. Original wrapper: ' . $originalName
                    . '. MD5/SHA-1 identify the decompressed package, not the wrapper.';
            } else {
                $note .= ' Package identity was calculated before upload and verified while the isolated working copy was written.';
            }

            $staged = (new CatalogBucketIdentityProcessor($this->db, $this->config))->stage(
                $workingPath,
                $workingName,
                $note,
                $userId,
                $relativePath,
                $packageMd5,
                $packageSha1,
                static function (array $progress) use ($context): void {
                    $context->checkpoint($progress);
                }
            );
            $workingPath = '';

            // Successful processing has created the durable bucket copy or found
            // an existing physical duplicate. Browser staging is no longer needed.
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

    /** @return array{path:string,md5:string,sha1:string} */
    private function copyToWorkingFile(
        string $sourcePath,
        int $jobId,
        JobExecutionContext $context,
        string $expectedMd5,
        string $expectedSha1
    ): array {
        $storage = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storage === '') {
            throw new \RuntimeException('Catalog storage path is unavailable.');
        }
        $directory = $storage . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'bucket-working';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create Upload Bucket working storage.');
        }

        // A forced process termination cannot execute finally. Remove only stale
        // working copies belonging to this exact job before a retry creates one.
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'job-' . $jobId . '-*.part') ?: [] as $oldPath) {
            if (is_string($oldPath) && is_file($oldPath)) {
                @unlink($oldPath);
            }
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
        $copyError = null;
        $md5Context = hash_init('md5');
        $sha1Context = hash_init('sha1');
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
                hash_update($md5Context, $buffer);
                hash_update($sha1Context, $buffer);
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
                        'message' => 'Preparing and hashing working copy: ' . $done . ' of ' . $size . ' bytes.',
                        'bytes_done' => $done,
                        'bytes_total' => $size,
                    ]);
                    $lastReport = $now;
                }
            }
            fflush($output);
        } catch (Throwable $error) {
            $copyError = $error;
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($copyError instanceof Throwable) {
            @unlink($destination);
            throw $copyError;
        }
        if ($size < 1 || $done !== $size) {
            @unlink($destination);
            throw new \RuntimeException('Upload Bucket working copy size is incomplete.');
        }

        $md5 = hash_final($md5Context);
        $sha1 = hash_final($sha1Context);
        if (preg_match('/^[a-f0-9]{32}$/', $expectedMd5) === 1 && !hash_equals($expectedMd5, $md5)) {
            @unlink($destination);
            throw new \RuntimeException('Uploaded package MD5 does not match the browser-calculated MD5.');
        }
        if (preg_match('/^[a-f0-9]{40}$/', $expectedSha1) === 1 && !hash_equals($expectedSha1, $sha1)) {
            @unlink($destination);
            throw new \RuntimeException('Uploaded package SHA-1 does not match the browser-calculated SHA-1.');
        }

        return ['path' => $destination, 'md5' => $md5, 'sha1' => $sha1];
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
