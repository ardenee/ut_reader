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
use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

/**
 * Treats a bad package/archive as a completed import attempt with a failed
 * outcome. The queue can then continue immediately to the next uploaded file.
 * Queue/storage/database/programming failures still escape and use the normal
 * retry and diagnostics policy.
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
        $temporaryPreparedPath = '';
        $redirectMeta = null;

        try {
            $context->checkpoint([
                'stage' => 'dispatch',
                'done' => 1,
                'total' => 100,
                'percent' => 1,
                'message' => 'Preparing import handler for ' . basename((string)($job->payload['original_name'] ?? 'package')),
            ]);

            if ($job->type === JobType::IMPORT_STAGED_PACKAGE) {
                [$preparedJob, $temporaryPreparedPath, $redirectMeta] = $this->prepareRedirectPayload($job, $context);
            }

            $result = $this->inner->handle($preparedJob, $context);
            $result = $this->repairInterruptedVerifiedImport($preparedJob, $result, $context);
            if (is_array($redirectMeta)) {
                $result['decompressed'] = true;
                $result['redirect_decoder'] = (string)$redirectMeta['decoder'];
                $result['redirect_signature'] = (int)($redirectMeta['wrapper_signature'] ?? 0);
                $result['redirect_compressed_bytes'] = (int)$redirectMeta['compressed_bytes'];
                $result['redirect_output_bytes'] = (int)$redirectMeta['bytes'];
                $result['redirect_is_unreal_package'] = (bool)$redirectMeta['is_unreal_package'];
                $result['redirect_source_name'] = (string)($job->payload['original_name'] ?? '');
            }
            return $result;
        } catch (JobCancellationRequested $error) {
            throw $error;
        } catch (PDOException $error) {
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw $error;
        } catch (\Error $error) {
            throw $error;
        } catch (Throwable $error) {
            if ($this->isInfrastructureFailure($error)) {
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
            if ($temporaryPreparedPath !== '' && is_file($temporaryPreparedPath)) {
                @unlink($temporaryPreparedPath);
            }
        }
    }

    /**
     * A metadata-publication deadlock can happen after ue_files and canonical
     * package storage have already been committed. Retrying the staged import then
     * detects that row as a duplicate. If that duplicate lacks format-2 metadata,
     * repair the stable file in place instead of incorrectly completing as a
     * harmless duplicate.
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

        $statement = $this->db->prepare(
            'SELECT m.format_version FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId]);
        $formatVersion = (int)($statement->fetchColumn() ?: 0);
        if ($formatVersion === 2) {
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
                . ' without format-2 metadata; repairing the interrupted import in place.',
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

        $message = 'Recovered interrupted verified import for file #' . $fileId
            . '; format-2 compact metadata was rebuilt in place.';
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
     * to the shared redirect processor before the package scanner runs.
     *
     * @return array{0:ClaimedJob,1:string,2:array<string,mixed>|null}
     */
    private function prepareRedirectPayload(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $originalName = trim((string)($payload['original_name'] ?? ''));
        if ($originalName === '') {
            return [$job, '', null];
        }

        $processor = new CatalogRedirectArchiveProcessor($this->config);
        if (!$processor->supports($originalName)) {
            return [$job, '', null];
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
        $decoded = $this->normalizePreparedTemporaryPath($decoded);

        $context->checkpoint([
            'stage' => 'redirect_ready',
            'done' => 45,
            'total' => 100,
            'percent' => 45,
            'message' => 'Redirect archive decompressed to ' . basename((string)$decoded['filename'])
                . ' (' . $this->bytes((int)$decoded['bytes']) . ').',
            'output_bytes' => (int)$decoded['bytes'],
            'decoder' => (string)$decoded['decoder'],
        ]);

        $sourceRelativePath = trim(
            str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $originalName)),
            '/'
        );
        $payload['prepared_source_path'] = (string)$decoded['path'];
        $payload['redirect_prepared'] = true;
        $payload['original_name'] = (string)$decoded['filename'];
        $payload['source_relative_path'] = $this->replaceRelativeFilename(
            $sourceRelativePath,
            (string)$decoded['filename']
        );
        $payload['size'] = (int)$decoded['bytes'];
        unset($payload['sha256']);

        $prepared = new ClaimedJob(
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
            $job->concurrencyKey
        );

        return [$prepared, (string)$decoded['path'], $decoded];
    }

    /**
     * Windows tempnam() keeps only the first three prefix characters. Rename the
     * file inside the same temporary directory so the downstream containment
     * guard can verify the full ue_redirect_ marker on every platform.
     *
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function normalizePreparedTemporaryPath(array $decoded): array
    {
        $path = trim((string)($decoded['path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Prepared redirect payload is unavailable.');
        }
        if (str_starts_with(basename($path), 'ue_redirect_')) {
            return $decoded;
        }

        $directory = dirname($path);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $target = $directory . DIRECTORY_SEPARATOR . 'ue_redirect_' . bin2hex(random_bytes(16)) . '.tmp';
            if (file_exists($target)) {
                continue;
            }
            if (@rename($path, $target)) {
                $decoded['path'] = $target;
                return $decoded;
            }
        }

        @unlink($path);
        throw new \RuntimeException('Could not normalize prepared redirect temporary file.');
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
            'job payload requires',
            'job lease no longer belongs',
            'could not lock chunked upload state',
            'timed out waiting for chunked upload state',
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
