<?php
/**
 * Transfers an archive workflow source from ingress staging into job-owned durable storage.
 *
 * Browser chunk uploads and jobs/incoming files are transport staging, not the
 * recovery boundary for a multi-job archive workflow. Before extraction starts,
 * the parent job takes ownership of the immutable archive bytes in its prepared
 * workspace and all later attempts read that owned copy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

final class CatalogArchiveSourceStore
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Return the same logical job with staged_path redirected to its durable,
     * job-owned archive copy. Publishing is atomic and idempotent across retries.
     */
    public function prepareJob(ClaimedJob $job): ClaimedJob
    {
        $store = new CatalogPreparedJobFileStore($this->config, $job->id, 'archive-source');
        $prepared = $store->load();
        if (!is_array($prepared)) {
            $prepared = $this->publish($job, $store);
        }

        $this->cleanupTransferredIngress($prepared);

        $path = trim((string)($prepared['path'] ?? ''));
        if ($path === '' || !is_file($path) || !is_readable($path) || is_link($path)) {
            throw new \RuntimeException('Durable archive workflow source is unavailable.');
        }

        $payload = $job->payload;
        $payload['archive_original_staged_path'] = (string)($prepared['original_staged_path']
            ?? $payload['archive_original_staged_path']
            ?? $payload['staged_path']
            ?? '');
        $payload['staged_path'] = $this->catalogReference($path);
        $payload['archive_prepared_source'] = true;

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
            $job->resumeProgress,
            $job->parentJobId,
            $job->workflowUnitKey
        );
    }

    /** @return array<string,mixed> */
    private function publish(ClaimedJob $job, CatalogPreparedJobFileStore $store): array
    {
        $payload = $job->payload;
        $stagedPath = trim((string)($payload['archive_original_staged_path'] ?? $payload['staged_path'] ?? ''));
        $originalName = trim((string)($payload['original_name'] ?? 'archive.bin'));
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? $originalName));
        if ($stagedPath === '') {
            throw new \InvalidArgumentException('Archive workflow source path is missing.');
        }

        $source = (new CatalogIncomingFileStore($this->config))->resolve($stagedPath);
        $transferIngress = $this->isOwnedIngressReference($stagedPath);
        $publishSource = $transferIngress ? $source : $this->copyForPublish($source, $job->id);

        try {
            return $store->publish(
                $publishSource,
                $originalName,
                [
                    'source_relative_path' => $sourceRelativePath,
                    'original_staged_path' => $stagedPath,
                    'ingress_transferred' => $transferIngress,
                    'archive_source_owned' => true,
                ]
            );
        } finally {
            if (!$transferIngress && $publishSource !== '' && is_file($publishSource)) {
                @unlink($publishSource);
            }
        }
    }

    private function isOwnedIngressReference(string $stagedPath): bool
    {
        if (str_starts_with($stagedPath, 'chunk-upload:')) {
            return true;
        }
        $normalized = ltrim(str_replace('\\', '/', $stagedPath), '/');
        return str_starts_with(strtolower($normalized), 'jobs/incoming/');
    }

    /** @return string temporary source which CatalogPreparedJobFileStore may move */
    private function copyForPublish(string $source, int $jobId): string
    {
        if (!is_file($source) || !is_readable($source) || is_link($source)) {
            throw new \RuntimeException('Archive workflow source cannot be copied into durable job storage.');
        }

        $storageRoot = rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($storageRoot === '') {
            throw new \RuntimeException('Catalog storage path is unavailable for archive source ownership.');
        }
        $directory = $storageRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'archive-source-staging';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create archive source staging directory.');
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . 'job-' . $jobId . '-'
            . bin2hex(random_bytes(8)) . '.part';
        if (!@link($source, $temporary) && !@copy($source, $temporary)) {
            throw new \RuntimeException('Could not create a durable archive source working copy.');
        }

        clearstatcache(true, $source);
        clearstatcache(true, $temporary);
        $sourceSize = filesize($source);
        $copySize = filesize($temporary);
        if ($sourceSize === false || $copySize === false || (int)$sourceSize !== (int)$copySize) {
            @unlink($temporary);
            throw new \RuntimeException('Durable archive source working copy is incomplete.');
        }
        return $temporary;
    }

    /** @param array<string,mixed> $prepared */
    private function cleanupTransferredIngress(array $prepared): void
    {
        if (empty($prepared['ingress_transferred'])) {
            return;
        }
        $stagedPath = trim((string)($prepared['original_staged_path'] ?? ''));
        if (preg_match('/^chunk-upload:([a-f0-9]{64})$/', $stagedPath, $match) !== 1) {
            return;
        }

        try {
            // The payload bytes have already been atomically published into the
            // parent job workspace. At this point only the obsolete chunk-upload
            // manifest/directory is being discarded.
            (new CatalogChunkedUploadCleanup($this->config))->delete($match[1]);
        } catch (\Throwable $error) {
            error_log('[UnrealDB archive source ownership] Could not remove transferred chunk ingress: '
                . $error->getMessage());
        }
    }

    private function catalogReference(string $path): string
    {
        $encoded = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
        return 'local-catalog:' . $encoded;
    }
}
