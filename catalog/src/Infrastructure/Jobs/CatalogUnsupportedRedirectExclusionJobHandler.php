<?php
/**
 * Prevents deterministic redirect wrappers for non-catalogued file types from
 * entering decompression/package parsing. Unsupported targets are intentional
 * exclusions, not failed jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketFilePolicy;

final class CatalogUnsupportedRedirectExclusionJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly JobHandler $inner,
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchive.php';
    }

    public function supports(string $jobType): bool
    {
        return $this->inner->supports($jobType);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $originalName = trim((string)($payload['original_name'] ?? ''));
        $outputName = CatalogUploadBucketFilePolicy::deterministicRedirectOutputName($originalName);
        if ($outputName === null) {
            return $this->inner->handle($job, $context);
        }

        $outputExtension = \catalog_clean_unreal_extension((string)pathinfo($outputName, PATHINFO_EXTENSION));
        $allowed = $this->allowedPackageExtensions($job);
        if ($allowed === null || $allowed === [] || isset($allowed[$outputExtension])) {
            return $this->inner->handle($job, $context);
        }

        $label = $outputExtension !== '' ? '.' . $outputExtension : '(no extension)';
        $message = 'Excluded ' . basename($originalName)
            . ': redirect output ' . basename($outputName) . ' uses ' . $label
            . ', which is not a catalogued package type for this upload target.';

        $this->cleanupExcludedSource($job);
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'status' => 'excluded',
            'message' => $message,
            'excluded_extension' => $outputExtension,
        ]);

        return [
            'operation' => $this->operation($job->type),
            'status' => 'excluded',
            'file_id' => 0,
            'message' => $message,
            'original_name' => $originalName,
            'redirect_output_name' => $outputName,
            'excluded_extension' => $outputExtension,
            'source_relative_path' => trim((string)($payload['source_relative_path'] ?? $originalName)),
        ];
    }

    /** @return array<string,true>|null */
    private function allowedPackageExtensions(ClaimedJob $job): ?array
    {
        if ($job->type === JobType::IMPORT_STAGED_PACKAGE) {
            $gameId = (int)($job->payload['game_id'] ?? 0);
            if ($gameId < 1) {
                return null;
            }
            $statement = $this->db->prepare(
                'SELECT p.allowed_extensions_json FROM ue_games g '
                . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                . 'WHERE g.id=? LIMIT 1'
            );
            $statement->execute([$gameId]);
            $json = $statement->fetchColumn();
            if (!is_string($json)) {
                return null;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return null;
            }
            $allowed = [];
            foreach ($decoded as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '') {
                    $allowed[$extension] = true;
                }
            }
            return $allowed;
        }

        $policy = new CatalogUploadBucketFilePolicy($this->db, $this->config);
        return array_fill_keys($policy->allowedPackageExtensions(), true);
    }

    private function cleanupExcludedSource(ClaimedJob $job): void
    {
        $payload = $job->payload;
        try {
            if ($job->type === JobType::PROCESS_BUCKET_UPLOAD) {
                $uploadId = trim((string)($payload['upload_id'] ?? ''));
                if (preg_match('/^[a-f0-9]{64}$/', $uploadId) === 1) {
                    (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
                }
                (new CatalogPreparedJobFileStore($this->config, $job->id, 'bucket-package'))->clear();
                return;
            }

            $stagedPath = trim((string)($payload['staged_path'] ?? ''));
            if (preg_match('/^chunk-upload:([a-f0-9]{64})$/', $stagedPath, $match) === 1) {
                (new CatalogChunkedUploadCleanup($this->config))->delete($match[1]);
            } elseif ($stagedPath !== '') {
                (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
            }

            if ($job->type === JobType::PREPARE_BUCKET_REDIRECT
                && (string)($payload['source_kind'] ?? '') === 'chunk-upload') {
                $uploadId = trim((string)($payload['upload_id'] ?? ''));
                if (preg_match('/^[a-f0-9]{64}$/', $uploadId) === 1) {
                    (new CatalogChunkedUploadCleanup($this->config))->delete($uploadId);
                }
            }

            if ($job->type === JobType::IMPORT_STAGED_PACKAGE) {
                (new CatalogPreparedJobFileStore($this->config, $job->id, 'redirect'))->clear();
            } elseif ($job->type === JobType::PROCESS_BUCKET_STAGED_PACKAGE) {
                (new CatalogPreparedJobFileStore($this->config, $job->id, 'bucket-archive-member'))->clear();
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB redirect exclusion cleanup] job=' . $job->id . ' ' . $error->getMessage());
        }
    }

    private function operation(string $jobType): string
    {
        return match ($jobType) {
            JobType::IMPORT_STAGED_PACKAGE => 'import_staged_package',
            JobType::PREPARE_BUCKET_REDIRECT => 'prepare_bucket_redirect',
            JobType::PROCESS_BUCKET_UPLOAD => 'process_bucket_upload',
            JobType::PROCESS_BUCKET_STAGED_PACKAGE => 'process_bucket_staged_package',
            default => 'exclude_unsupported_redirect',
        };
    }
}
