<?php
/**
 * Processes one archive-extracted package through the existing Upload Bucket
 * identity/indexing path. The extracted member is already in controlled durable
 * staging, so this handler does not depend on browser/chunk-upload state.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportOutcome;
use UnrealDb\Catalog\Infrastructure\Import\CatalogInvalidPackageException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketIdentityProcessor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportPathPolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogInvalidUeFileReporter;

final class CatalogBucketStagedPackageJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::PROCESS_BUCKET_STAGED_PACKAGE;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $originalName = CatalogImportPathPolicy::filename((string)($payload['original_name'] ?? ''));
        $relativePath = CatalogImportPathPolicy::relative(
            (string)($payload['source_relative_path'] ?? $originalName)
        );
        $userId = (int)($payload['user_id'] ?? 0);
        if ($stagedPath === '' || $userId < 1) {
            throw new \InvalidArgumentException('Archive member Upload Bucket job payload is incomplete.');
        }

        $policy = new CatalogUploadBucketFilePolicy($this->db, $this->config);
        $policy->validateName($originalName, true);
        $incoming = new CatalogIncomingFileStore($this->config);
        $preparedStore = new CatalogPreparedJobFileStore($this->config, $job->id, 'bucket-archive-member');
        $prepared = $preparedStore->load();
        $redirect = false;
        $decoder = '';
        $compressedBytes = 0;

        if (!is_array($prepared)) {
            $sourcePath = $incoming->resolve($stagedPath);
            $workingName = $originalName;
            $redirect = $policy->isRedirectWrapper($originalName);
            if ($redirect) {
                $context->checkpoint([
                    'stage' => 'redirect_decompress',
                    'done' => 5,
                    'total' => 100,
                    'percent' => 5,
                    'message' => 'Decompressing redirect member extracted from the archive.',
                ]);
                $decoded = (new CatalogRedirectArchiveProcessor($this->config))->decompressToTemp(
                    $sourcePath,
                    $originalName,
                    static function (array $progress) use ($context): void {
                        $sourcePercent = max(0, min(100, (int)($progress['percent'] ?? 0)));
                        $context->heartbeatIfDue([
                            'stage' => 'redirect_decompress',
                            'done' => (int)($progress['compressed_done'] ?? 0),
                            'total' => max(1, (int)($progress['compressed_total'] ?? 1)),
                            'percent' => max(5, min(40, 5 + (int)floor($sourcePercent * 35 / 100))),
                            'message' => (string)($progress['message'] ?? 'Decompressing redirect archive member.'),
                        ]);
                    },
                    true
                );
                $workingName = CatalogImportPathPolicy::filename((string)$decoded['filename']);
                $relativePath = CatalogImportPathPolicy::replaceFilename($relativePath, $workingName);
                $decoder = (string)$decoded['decoder'];
                $compressedBytes = (int)$decoded['compressed_bytes'];
                $prepared = $preparedStore->publish(
                    (string)$decoded['path'],
                    $workingName,
                    [
                        'redirect' => true,
                        'decoder' => $decoder,
                        'compressed_bytes' => $compressedBytes,
                        'md5' => strtolower((string)$decoded['md5']),
                        'sha1' => strtolower((string)$decoded['sha1']),
                        'source_relative_path' => $relativePath,
                    ]
                );
            } else {
                $md5 = hash_file('md5', $sourcePath);
                $sha1 = hash_file('sha1', $sourcePath);
                if (!is_string($md5) || !is_string($sha1)) {
                    throw new \RuntimeException('Could not hash archive-extracted package.');
                }
                $prepared = $preparedStore->publish(
                    $sourcePath,
                    $workingName,
                    [
                        'redirect' => false,
                        'decoder' => '',
                        'compressed_bytes' => 0,
                        'md5' => strtolower($md5),
                        'sha1' => strtolower($sha1),
                        'source_relative_path' => $relativePath,
                    ]
                );
            }
            $context->checkpoint([
                'stage' => 'package_prepared',
                'done' => 45,
                'total' => 100,
                'percent' => 45,
                'message' => 'Archive member preparation is durable.',
            ]);
        }

        $workingName = CatalogImportPathPolicy::filename((string)($prepared['logical_name'] ?? $originalName));
        $relativePath = CatalogImportPathPolicy::relative(
            (string)($prepared['source_relative_path'] ?? $relativePath)
        );
        $redirect = !empty($prepared['redirect']);
        $decoder = (string)($prepared['decoder'] ?? '');
        $compressedBytes = (int)($prepared['compressed_bytes'] ?? 0);
        $md5 = strtolower(trim((string)($prepared['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($prepared['sha1'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Prepared archive member identity is invalid.');
        }
        $policy->validateName($workingName, false);

        $preparedPath = (string)($prepared['path'] ?? '');
        $workingPath = $this->workingCopy($preparedPath, $workingName);
        try {
            $note = 'Extracted from archive ' . trim((string)($payload['archive_source_name'] ?? '')) . '.';
            if ($redirect) {
                $note .= ' Redirect wrapper decompressed with ' . $decoder . '.';
            }
            $staged = (new CatalogBucketIdentityProcessor($this->db, $this->config))->stage(
                $workingPath,
                $workingName,
                $note,
                $userId,
                $relativePath,
                $md5,
                $sha1,
                static function (array $progress) use ($context): void {
                    $context->checkpoint($progress);
                }
            );
            $workingPath = '';
        } catch (Throwable $error) {
            if ($this->isDeterministicNonPackage($error)) {
                // Treat this as terminal for automatic retry, but retain the
                // durable prepared bytes. A future reader/tag fix can turn what
                // looked deterministic today into a valid package (for example a
                // newly supported package-tag variant), so an explicit archive
                // rerun must not require the source to be uploaded again.
                $message = 'Invalid Unreal package; durable member source retained for explicit current-code revalidation. '
                    . $this->errorText($error) . ' ' . $this->firstBytesDiagnostic($preparedPath);
                $validation = CatalogInvalidUeErrorClassifier::classify(
                    $this->errorText($error),
                    $error instanceof CatalogInvalidPackageException ? $error->validationCode() : '',
                    $error instanceof CatalogInvalidPackageException ? $error->validationArguments() : []
                );
                $systemErrorRecorded = CatalogInvalidUeFileReporter::record([
                    'job_id' => $job->id,
                    'parent_job_id' => $job->parentJobId ?? 0,
                    'job_type' => $job->type,
                    'user_id' => $userId,
                    'file_name' => $workingName,
                    'source_relative_path' => $relativePath,
                    'archive_source_name' => (string)($payload['archive_source_name'] ?? ''),
                    'archive_entry_path' => (string)($payload['archive_entry_path'] ?? $workingName),
                    'size' => is_file($preparedPath) ? (int)(filesize($preparedPath) ?: 0) : 0,
                    'md5' => $md5,
                    'sha1' => $sha1,
                    'reason' => $validation['reason'],
                    'error_code' => $validation['code'],
                    'arguments' => $validation['arguments'],
                ]);
                $context->checkpoint([
                    'stage' => 'complete',
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'status' => CatalogImportOutcome::INVALID_UE_PACKAGE,
                    'message' => $message,
                    'source_retained' => true,
                    'system_error_recorded' => $systemErrorRecorded,
                    'validation_code' => $validation['code'],
                    'validation_arguments' => $validation['arguments'],
                ]);
                return $this->terminalResult(
                    CatalogImportOutcome::INVALID_UE_PACKAGE,
                    $message,
                    $workingName,
                    $relativePath,
                    $preparedPath,
                    $compressedBytes,
                    $decoder,
                    $md5,
                    $sha1,
                    true,
                    $systemErrorRecorded,
                    $validation['code'],
                    $validation['arguments']
                );
            }

            if ($this->isReaderValidationFailure($error)) {
                // Extraction succeeded, but the immutable member bytes contradict
                // Unreal package serialization. This is an invalid UE file, not
                // an archive extraction failure. Keep the durable prepared member
                // for diagnostics or a future reader correction without making the
                // parent archive retryable.
                $message = 'Invalid Unreal package; archive extraction completed successfully and the durable member source was retained. '
                    . $this->errorText($error)
                    . ' SHA1=' . $sha1 . '. '
                    . $this->firstBytesDiagnostic($preparedPath);
                $validation = CatalogInvalidUeErrorClassifier::classify(
                    $this->errorText($error),
                    $error instanceof CatalogInvalidPackageException ? $error->validationCode() : '',
                    $error instanceof CatalogInvalidPackageException ? $error->validationArguments() : []
                );
                $systemErrorRecorded = CatalogInvalidUeFileReporter::record([
                    'job_id' => $job->id,
                    'parent_job_id' => $job->parentJobId ?? 0,
                    'job_type' => $job->type,
                    'user_id' => $userId,
                    'file_name' => $workingName,
                    'source_relative_path' => $relativePath,
                    'archive_source_name' => (string)($payload['archive_source_name'] ?? ''),
                    'archive_entry_path' => (string)($payload['archive_entry_path'] ?? $workingName),
                    'size' => is_file($preparedPath) ? (int)(filesize($preparedPath) ?: 0) : 0,
                    'md5' => $md5,
                    'sha1' => $sha1,
                    'reason' => $validation['reason'],
                    'error_code' => $validation['code'],
                    'arguments' => $validation['arguments'],
                ]);
                $context->checkpoint([
                    'stage' => 'complete',
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'status' => CatalogImportOutcome::INVALID_UE_PACKAGE,
                    'message' => $message,
                    'source_retained' => true,
                    'system_error_recorded' => $systemErrorRecorded,
                    'validation_code' => $validation['code'],
                    'validation_arguments' => $validation['arguments'],
                ]);
                return $this->terminalResult(
                    CatalogImportOutcome::INVALID_UE_PACKAGE,
                    $message,
                    $workingName,
                    $relativePath,
                    $preparedPath,
                    $compressedBytes,
                    $decoder,
                    $md5,
                    $sha1,
                    true,
                    $systemErrorRecorded,
                    $validation['code'],
                    $validation['arguments']
                );
            }

            throw $error;
        } finally {
            if ($workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }

        $incoming->delete($stagedPath);
        $preparedStore->clear();

        // If this job previously produced an invalid-Unreal System Error and an
        // explicit rerun now succeeds with current code, close that exact error.
        CatalogSystemErrorRecorder::resolveInvalidUeJob($job->id, $job->id);

        $status = (string)($staged['status'] ?? 'indexed');
        $resultStatus = $status === 'duplicate' ? 'duplicate' : 'bucketed';
        $message = (string)($staged['message'] ?? 'Archive member was added to the Upload Bucket.');
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'status' => $resultStatus,
            'message' => $message,
            'file_id' => (int)($staged['file_id'] ?? 0),
        ]);

        return [
            'operation' => 'process_bucket_staged_package',
            'status' => $resultStatus,
            'message' => $message,
            'file_id' => (int)($staged['file_id'] ?? 0),
            'queue_name' => (string)($staged['queue_name'] ?? ''),
            'original_name' => $workingName,
            'source_relative_path' => $relativePath,
            'bytes' => (int)($staged['size'] ?? 0),
            'compressed_bytes' => $compressedBytes,
            'decoder' => $decoder,
            'md5' => (string)($staged['md5'] ?? $md5),
            'sha1' => (string)($staged['sha1'] ?? $sha1),
        ];
    }

    private function isDeterministicNonPackage(Throwable $error): bool
    {
        $message = strtolower($this->errorText($error));
        return str_contains($message, 'does not contain a supported unreal package header')
            || str_contains($message, 'unreal package magic not found');
    }

    private function isReaderValidationFailure(Throwable $error): bool
    {
        if ($error instanceof CatalogInvalidPackageException) {
            return true;
        }
        return JobFailureRetryPolicy::isInvalidPackageContentText(
            JobType::PROCESS_BUCKET_STAGED_PACKAGE,
            $this->errorText($error)
        );
    }

    /** @return array<string,mixed> */
    private function terminalResult(
        string $status,
        string $message,
        string $workingName,
        string $relativePath,
        string $preparedPath,
        int $compressedBytes,
        string $decoder,
        string $md5,
        string $sha1,
        bool $sourceRetained,
        bool $systemErrorRecorded = false,
        string $validationCode = '',
        array $validationArguments = []
    ): array {
        return [
            'operation' => 'process_bucket_staged_package',
            'status' => $status,
            'message' => $message,
            'file_id' => 0,
            'queue_name' => '',
            'original_name' => $workingName,
            'source_relative_path' => $relativePath,
            'bytes' => is_file($preparedPath) ? (int)(filesize($preparedPath) ?: 0) : 0,
            'compressed_bytes' => $compressedBytes,
            'decoder' => $decoder,
            'md5' => $md5,
            'sha1' => $sha1,
            'source_retained' => $sourceRetained,
            'system_error_recorded' => $systemErrorRecorded,
            'validation_code' => $validationCode,
            'validation_arguments' => $validationArguments,
        ];
    }

    private function firstBytesDiagnostic(string $path): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return 'First bytes unavailable.';
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return 'First bytes unavailable.';
        }
        try {
            $bytes = fread($handle, 32);
        } finally {
            fclose($handle);
        }
        if (!is_string($bytes) || $bytes === '') {
            return 'File is empty or its first bytes are unavailable.';
        }
        $hex = strtoupper(implode(' ', str_split(bin2hex($bytes), 2)));
        $ascii = preg_replace('/[^\x20-\x7E]/', '.', $bytes) ?? '';
        return 'First bytes: ' . $hex . ' (ASCII "' . $ascii . '").';
    }

    private function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? $message : get_class($error);
    }

    private function workingCopy(string $sourcePath, string $name): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Prepared archive member is unavailable.');
        }
        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
        $path = dirname($sourcePath) . DIRECTORY_SEPARATOR . '.archive-member-'
            . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!@link($sourcePath, $path) && !@copy($sourcePath, $path)) {
            throw new \RuntimeException('Could not create archive-member working copy.');
        }
        $sourceSize = filesize($sourcePath);
        $copySize = filesize($path);
        if ($sourceSize === false || $copySize === false || (int)$sourceSize !== (int)$copySize) {
            @unlink($path);
            throw new \RuntimeException('Archive-member working copy is incomplete.');
        }
        return $path;
    }
}
