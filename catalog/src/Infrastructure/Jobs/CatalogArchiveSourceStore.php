<?php
/**
 * Transfers an archive workflow source from ingress staging into job-owned durable storage.
 *
 * Browser chunk uploads and jobs/incoming files are transport staging, not the
 * recovery boundary for a multi-job archive workflow. Before extraction starts,
 * the parent job takes ownership of the immutable archive bytes in its prepared
 * workspace. That copy exists only until extraction has either handed every
 * selected member to child staging or produced an unresolved extraction failure.
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

    /**
     * Remove the job-owned archive recovery workspace after a clean extraction
     * handoff. Idempotent so crash recovery may call it again.
     */
    public function clear(int $jobId): void
    {
        if ($jobId < 1) {
            return;
        }
        (new CatalogPreparedJobFileStore($this->config, $jobId, 'archive-source'))->clear();
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

        // Never let CatalogPreparedJobFileStore move/delete the only ingress copy.
        // Create a verified same-volume hardlink when possible (copy fallback),
        // publish that temporary, and release ingress only after publish succeeds.
        $publishSource = $this->copyForPublish($source, $store->directory());
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
            if ($publishSource !== '' && is_file($publishSource)) {
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
    private function copyForPublish(string $source, string $ownerDirectory): string
    {
        if (!is_file($source) || !is_readable($source) || is_link($source)) {
            throw new \RuntimeException('Archive workflow source cannot be copied into durable job storage.');
        }
        if (!is_dir($ownerDirectory)
            && !@mkdir($ownerDirectory, 0750, true)
            && !is_dir($ownerDirectory)) {
            throw new \RuntimeException('Could not create archive source owner workspace.');
        }

        $temporary = $ownerDirectory . DIRECTORY_SEPARATOR . '.ownership-'
            . bin2hex(random_bytes(8)) . '.part';
        $linked = @link($source, $temporary);
        if (!$linked && !@copy($source, $temporary)) {
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
        if (!$linked) {
            $sourceHash = hash_file('sha256', $source);
            $copyHash = hash_file('sha256', $temporary);
            if (!is_string($sourceHash) || !is_string($copyHash) || !hash_equals($sourceHash, $copyHash)) {
                @unlink($temporary);
                throw new \RuntimeException('Durable archive source working copy failed content verification.');
            }
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
        if ($stagedPath === '') {
            return;
        }

        try {
            // The archive bytes are already present in the verified parent job
            // workspace. The transport source may now be removed without affecting
            // workflow retry/recovery.
            if (preg_match('/^chunk-upload:([a-f0-9]{64})$/', $stagedPath, $match) === 1) {
                (new CatalogChunkedUploadCleanup($this->config))->delete($match[1]);
                return;
            }
            $normalized = ltrim(str_replace('\\', '/', $stagedPath), '/');
            if (str_starts_with(strtolower($normalized), 'jobs/incoming/')) {
                (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
            }
        } catch (\Throwable $error) {
            error_log('[UnrealDB archive source ownership] Could not remove transferred ingress: '
                . $error->getMessage());
        }
    }

    private function catalogReference(string $path): string
    {
        $encoded = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
        return 'local-catalog:' . $encoded;
    }
}
