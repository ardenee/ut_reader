<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

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
        $temporaryStagedPath = '';
        $redirectMeta = null;

        try {
            if ($job->type === JobType::IMPORT_STAGED_PACKAGE) {
                [$preparedJob, $temporaryStagedPath, $redirectMeta] = $this->prepareRedirectPayload($job);
            }

            $result = $this->inner->handle($preparedJob, $context);
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
            if ($temporaryStagedPath !== '') {
                (new CatalogIncomingFileStore($this->config))->delete($temporaryStagedPath);
            }
        }
    }

    /**
     * Decompress self-identifying 1234/5678 streams and exact UZ2 records before
     * the package scanner runs. The decoded payload may be a package, text file,
     * native library or another redirect-distributed file type.
     *
     * @return array{0:ClaimedJob,1:string,2:array<string,mixed>|null}
     */
    private function prepareRedirectPayload(ClaimedJob $job): array
    {
        $payload = $job->payload;
        $originalName = trim((string)($payload['original_name'] ?? ''));
        if ($originalName === '') {
            return [$job, '', null];
        }

        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchivePayload.php';
        if (!\catalog_redirect_archive_is_supported_filename($originalName)) {
            return [$job, '', null];
        }

        $store = new CatalogIncomingFileStore($this->config);
        $sourcePath = $store->resolve(trim((string)($payload['staged_path'] ?? '')));
        $decoded = \catalog_redirect_archive_decompress_payload_to_temp(
            $sourcePath,
            $originalName,
            (int)($this->config['max_upload_bytes'] ?? 0)
        );

        try {
            $staged = $store->stageLocalFile((string)$decoded['path'], (string)$decoded['filename']);
        } finally {
            @unlink((string)$decoded['path']);
        }

        $sourceRelativePath = trim(
            str_replace('\\', '/', (string)($payload['source_relative_path'] ?? $originalName)),
            '/'
        );
        $payload['staged_path'] = $staged['relative_path'];
        $payload['original_name'] = $staged['original_name'];
        $payload['source_relative_path'] = $this->replaceRelativeFilename(
            $sourceRelativePath,
            $staged['original_name']
        );
        $payload['size'] = $staged['size'];
        $payload['sha256'] = $staged['sha256'];

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

        return [$prepared, (string)$staged['relative_path'], $decoded];
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
}
