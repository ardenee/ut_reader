<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogNonBlockingImportJobHandler` for catalog non blocking import job
 *          handler.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure job wrapper that keeps bad files from blocking the queue and repairs interrupted verified imports.
 */
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
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceReimportService;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedCompactMetadataHealth;
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

/**
 * Treats a bad package/archive as a completed import attempt with a failed
 * outcome. The queue can then continue immediately to the next uploaded file.
 * Queue/storage/database/programming failures still escape and use the normal
 * retry and diagnostics policy.
 *
 * Once a complete browser upload is available, expensive redirect preparation is
 * itself durable: decompressed output remains in a per-job prepared workspace
 * across worker/process retries and is removed only after this job returns a
 * completed terminal result.
 */
final class CatalogNonBlockingImportJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly CatalogStagedImportJobHandler $inner,
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $this->inner->supports($jobType);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $preparedJob = $job;
        $preparedStore = null;
        $redirectMeta = null;
        $completedResult = false;

        try {
            $context->checkpoint([
                'stage' => 'dispatch',
                'done' => 1,
                'total' => 100,
                'percent' => 1,
                'message' => 'Preparing import handler for ' . basename((string)($job->payload['original_name'] ?? 'package')),
            ]);

            if ($job->type === JobType::IMPORT_STAGED_PACKAGE) {
                [$preparedJob, $preparedStore, $redirectMeta] = $this->prepareRedirectPayload($job, $context);
            }

            $result = $this->inner->handle($preparedJob, $context);
            $result = $this->repairInterruptedVerifiedImport($preparedJob, $result, $context);
            if (is_array($redirectMeta)) {
                $result['decompressed'] = true;
                $result['redirect_decoder'] = (string)($redirectMeta['decoder'] ?? '');
                $result['redirect_signature'] = (int)($redirectMeta['wrapper_signature'] ?? 0);
                $result['redirect_compressed_bytes'] = (int)($redirectMeta['compressed_bytes'] ?? 0);
                $result['redirect_output_bytes'] = (int)($redirectMeta['bytes'] ?? 0);
                $result['redirect_is_unreal_package'] = (bool)($redirectMeta['is_unreal_package'] ?? false);
                $result['redirect_source_name'] = (string)($job->payload['original_name'] ?? '');
                $result['redirect_preparation_reused'] = !empty($redirectMeta['reused_prepared_output']);
            }
            $completedResult = true;
            return $result;
        } catch (JobCancellationRequested $error) {
            // Keep a completed prepared payload. A cancelled job can be restarted
            // later and should not need to decompress the same server-side file.
            throw $error;
        } catch (PDOException $error) {
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw $error;
        } catch (\Error $error) {
            throw $error;
        } catch (Throwable $error) {
            if ($this->isInfrastructureFailure($error)) {
                // Infrastructure failure is retryable; retain the prepared file.
                throw $error;
            }

            $message = $this->shortError($error);
            $payload = $job->payload;
            $originalName = trim((string)($payload['original_name'] ?? 'package'));
            $sourceRelativePath = trim(str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $originalName)), '/');

            $context->checkpoint([
                'stage' => 'failed',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => 'failed',
                'message' => $message,
            ]);

            $completedResult = true;
            return [
                'operation' => $job->type === JobType::IMPORT_STAGED_PAK
                    ? 'import_staged_pak'
                    : 'import_staged_package',
                'status' => 'failed',
                'file_id' => 0,
                'message' => $message,
                'original_name' => $originalName,
                'source_relative_path' => $sourceRelativePath,
            ];
        } finally {
            if ($completedResult && $preparedStore instanceof CatalogPreparedJobFileStore) {
                $preparedStore->clear();
            }
        }
    }

    /**
     * Compact publication can fail after ue_files and canonical package storage
     * have already committed. Retrying then detects that stable row as a duplicate.
     * A format-2 registration is not sufficient evidence of health: verify the
     * physical container and repair the stable file in place when it is missing,
     * corrupt or incompletely published.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function repairInterruptedVerifiedImport(
        ClaimedJob $job,
        array $result,
        JobExecutionContext $context
    ): array {
        if ($job->type !== JobType::IMPORT_STAGED_PACKAGE
            || (string)($result['status'] ?? '') !== 'duplicate') {
            return $result;
        }

        $fileId = (int)($result['file_id'] ?? 0);
        if ($fileId < 1) {
            return $result;
        }

        if (VerifiedCompactMetadataHealth::healthy($this->db, $this->config, $fileId)) {
            return $result;
        }

        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;
        $context->checkpoint([
            'stage' => 'compact_metadata_repair',
            'done' => 95,
            'total' => 100,
            'percent' => 95,
            'status' => 'repairing',
            'file_id' => $fileId,
            'message' => 'Retry found verified file #' . $fileId
                . ' without healthy format-2 metadata; repairing the interrupted import in place.',
        ]);

        $repair = (new CatalogFileMaintenanceReimportService($this->db, $this->config))->reimport(
            $fileId,
            $userId,
            static function (array $progress) use ($context, $fileId): void {
                $sourcePercent = max(0, min(100, (int)($progress['percent'] ?? 0)));
                $context->heartbeatIfDue([
                    'stage' => 'compact_metadata_repair',
                    'done' => 95 + (int)floor($sourcePercent * 4 / 100),
                    'total' => 100,
                    'percent' => min(99, 95 + (int)floor($sourcePercent * 4 / 100)),
                    'file_id' => $fileId,
                    'message' => (string)($progress['message'] ?? 'Repairing interrupted compact metadata publication.'),
                ]);
            },
            false
        );

        // Do not report recovery until the authoritative container verifies.
        VerifiedCompactMetadataHealth::verify($this->db, $this->config, $fileId);

        $message = 'Recovered interrupted verified import for file #' . $fileId
            . '; format-2 compact metadata was rebuilt and verified in place.';
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'status' => 'verified',
            'file_id' => $fileId,
            'message' => $message,
        ]);

        $result['status'] = 'verified';
        $result['message'] = $message;
        $result['recovered_incomplete_metadata'] = true;
        $result['maintenance_repair'] = $repair;
        return $result;
    }

    /**
     * Resolve the staged wrapper, then delegate all format dispatch and decoding
     * to the shared redirect processor before the package scanner runs. Completed
     * decompression is persisted under catalog job storage and reused on retry.
     *
     * @return array{0:ClaimedJob,1:?CatalogPreparedJobFileStore,2:array<string,mixed>|null}
     */
    private function prepareRedirectPayload(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $originalName = trim((string)($payload['original_name'] ?? ''));
        if ($originalName === '') {
            return [$job, null, null];
        }

        $processor = new CatalogRedirectArchiveProcessor($this->config);
        if (!$processor->supports($originalName)) {
            return [$job, null, null];
        }

        $preparedStore = new CatalogPreparedJobFileStore($this->config, $job->id, 'redirect');
        $persisted = $preparedStore->load();
        if (is_array($persisted)) {
            $decoded = [
                'path' => (string)$persisted['path'],
                'filename' => (string)($persisted['logical_name'] ?? 'package.bin'),
                'decoder' => (string)($persisted['decoder'] ?? ''),
                'wrapper_signature' => (int)($persisted['wrapper_signature'] ?? 0),
                'compressed_bytes' => (int)($persisted['compressed_bytes'] ?? 0),
                'bytes' => (int)($persisted['size'] ?? 0),
                'is_unreal_package' => (bool)($persisted['is_unreal_package'] ?? false),
                'source_relative_path' => (string)($persisted['source_relative_path'] ?? ''),
                'reused_prepared_output' => true,
            ];
            $context->checkpoint([
                'stage' => 'redirect_ready',
                'done' => 45,
                'total' => 100,
                'percent' => 45,
                'message' => 'Reusing durable decompressed redirect output '
                    . basename((string)$decoded['filename']) . ' (' . $this->bytes((int)$decoded['bytes']) . ').',
                'output_bytes' => (int)$decoded['bytes'],
                'decoder' => (string)$decoded['decoder'],
                'prepared_reused' => true,
            ]);
            return [$this->preparedJob($job, $payload, $decoded), $preparedStore, $decoded];
        }

        $context->checkpoint([
            'stage' => 'redirect_resolve',
            'done' => 2,
            'total' => 100,
            'percent' => 2,
            'message' => 'Resolving staged redirect archive ' . basename($originalName),
        ]);

        $store = new CatalogIncomingFileStore($this->config);
        $sourcePath = $store->resolve(trim((string)($payload['staged_path'] ?? '')));
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
                $percent = max(3, min(44, 3 + (int)floor($sourcePercent * 41 / 100)));
                $context->checkpoint([
                    'stage' => 'redirect_decompress',
                    'done' => (int)($progress['compressed_done'] ?? 0),
                    'total' => max(1, (int)($progress['compressed_total'] ?? 1)),
                    'percent' => $percent,
                    'message' => (string)($progress['message'] ?? 'Decompressing redirect archive.'),
                    'compressed_bytes' => (int)($progress['compressed_done'] ?? 0),
                    'output_bytes' => (int)($progress['output_bytes'] ?? 0),
                    'chunks' => (int)($progress['chunks'] ?? 0),
                ]);
            },
            true
        );

        $sourceRelativePath = trim(
            str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $originalName)),
            '/'
        );
        $preparedRelativePath = $this->replaceRelativeFilename(
            $sourceRelativePath,
            (string)$decoded['filename']
        );
        $durable = $preparedStore->publish(
            (string)$decoded['path'],
            (string)$decoded['filename'],
            [
                'decoder' => (string)($decoded['decoder'] ?? ''),
                'wrapper_signature' => (int)($decoded['wrapper_signature'] ?? 0),
                'compressed_bytes' => (int)($decoded['compressed_bytes'] ?? 0),
                'is_unreal_package' => (bool)($decoded['is_unreal_package'] ?? false),
                'source_relative_path' => $preparedRelativePath,
            ]
        );
        $decoded['path'] = (string)$durable['path'];
        $decoded['bytes'] = (int)$durable['size'];
        $decoded['source_relative_path'] = $preparedRelativePath;
        $decoded['reused_prepared_output'] = false;

        $context->checkpoint([
            'stage' => 'redirect_ready',
            'done' => 45,
            'total' => 100,
            'percent' => 45,
            'message' => 'Redirect archive decompressed and durably prepared as '
                . basename((string)$decoded['filename']) . ' (' . $this->bytes((int)$decoded['bytes']) . ').',
            'output_bytes' => (int)$decoded['bytes'],
            'decoder' => (string)$decoded['decoder'],
            'prepared_reused' => false,
        ]);

        return [$this->preparedJob($job, $payload, $decoded), $preparedStore, $decoded];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $decoded
     */
    private function preparedJob(ClaimedJob $job, array $payload, array $decoded): ClaimedJob
    {
        $sourceRelativePath = trim((string)($decoded['source_relative_path'] ?? ''));
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $this->replaceRelativeFilename(
                trim(str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $payload['original_name'] ?? '')), '/'),
                (string)$decoded['filename']
            );
        }
        $payload['prepared_source_path'] = (string)$decoded['path'];
        $payload['prepared_source_persistent'] = true;
        $payload['redirect_prepared'] = true;
        $payload['original_name'] = (string)$decoded['filename'];
        $payload['source_relative_path'] = $sourceRelativePath;
        $payload['size'] = (int)$decoded['bytes'];
        unset($payload['sha256']);

        return new ClaimedJob(
            $job->id,
            $job->queue,
            $job->type,
            $payload,
            $job->leaseToken,
            $job->attempt,
            $job->maxAttempts,
            $job->leaseExpiresAt,
            $job->resourceClass,
            $job->resourceLimit,
            $job->concurrencyKey,
            $job->resumeProgress
        );
    }

    private function replaceRelativeFilename(string $relativePath, string $name): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '. /');
        return ($directory !== '' ? $directory . '/' : '') . $name;
    }

    private function isInfrastructureFailure(Throwable $error): bool
    {
        if ($error instanceof \LogicException) {
            return true;
        }

        $message = strtolower(trim($error->getMessage()));
        foreach ([
            'staged import file is unavailable',
            'staged import file identity changed',
            'target game no longer exists',
            'could not allocate package import working file',
            'could not create package import working copy',
            'prepared job source file is unavailable',
            'could not persist prepared job file',
            'could not publish prepared job file',
            'could not persist prepared job-file metadata',
            'job payload requires',
            'job lease no longer belongs',
            'could not lock chunked upload state',
            'timed out waiting for chunked upload state',
            'direct compact metadata publication failed',
            'compact metadata',
            'blocked metadata',
            'metadata publication',
            'sqlstate[',
        ] as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
        return trim($message) !== '' ? trim($message) : 'Package import failed.';
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? (string)$value : number_format($value, 2)) . ' ' . $units[$unit];
    }
}
