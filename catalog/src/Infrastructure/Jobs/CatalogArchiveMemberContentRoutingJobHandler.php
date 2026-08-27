<?php
/**
 * Routes archive-extracted staged-package jobs according to their actual bytes.
 *
 * This is intentionally a decorator around the existing staged-package handlers.
 * Genuine packages continue through the established importer unchanged. Recognized
 * sidecar/placeholder content is completed as skipped, disguised redirect streams
 * are delegated under a synthetic wrapper suffix, and disguised ZIP/RAR/7z files
 * become durable child archive workflows. A nested archive child is waited on with
 * JobExecutionContext::defer(), so no worker is occupied while deeper levels run.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportOutcome;
use UnrealDb\Catalog\Infrastructure\Import\CatalogImportPathPolicy;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoArchiveChildOutcomeQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogArchiveMemberContentRoutingJobHandler implements JobHandler
{
    private const WAIT_STAGE = 'archive_member_content_wait_child';
    private const ROUTER_VERSION = 2;
    private const DEFAULT_MAX_NESTING_DEPTH = 4;
    private const MAX_CONFIGURED_NESTING_DEPTH = 16;

    private readonly CatalogArchiveMemberContentClassifier $classifier;
    private readonly PdoArchiveChildOutcomeQuery $children;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly JobHandler $inner,
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->classifier = new CatalogArchiveMemberContentClassifier();
        $this->children = new PdoArchiveChildOutcomeQuery($db);
    }

    public function supports(string $jobType): bool
    {
        return $this->inner->supports($jobType);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if (!$this->isArchiveMemberJob($job)) {
            return $this->inner->handle($job, $context);
        }

        $resume = $context->resumeProgress();
        if ((int)($resume['archive_member_router_version'] ?? 0) === self::ROUTER_VERSION
            && (string)($resume['stage'] ?? '') === self::WAIT_STAGE) {
            return $this->waitForNestedArchive($job, $context, $resume);
        }

        $payload = $job->payload;
        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $originalName = CatalogImportPathPolicy::filename((string)($payload['original_name'] ?? ''));
        $entryPath = trim((string)($payload['archive_entry_path'] ?? $originalName));
        if ($stagedPath === '' || $originalName === '') {
            return $this->inner->handle($job, $context);
        }

        $incoming = new CatalogIncomingFileStore($this->config);
        $preparedStore = new CatalogPreparedJobFileStore($this->config, $job->id, 'bucket-archive-member');
        $prepared = $preparedStore->load();
        $sourceFromPrepared = is_array($prepared);
        $effectiveJob = $job;

        if ($sourceFromPrepared) {
            $sourcePath = (string)$prepared['path'];
        } else {
            try {
                $sourcePath = $incoming->resolve($stagedPath);
            } catch (Throwable $stagedError) {
                try {
                    $restored = (new CatalogRetainedArchiveMemberSourceRestorer($this->db, $this->config))->restore(
                        $job,
                        $incoming,
                        $originalName,
                        $entryPath
                    );
                } catch (Throwable $restoreError) {
                    throw new \RuntimeException(
                        'Archive member staged source is unavailable and retained-parent reconstruction failed: '
                        . $this->errorText($restoreError)
                        . ' Original staging error: ' . $this->errorText($stagedError),
                        (int)$restoreError->getCode(),
                        $restoreError
                    );
                }

                $effectiveJob = $this->withStagedFile($job, $restored);
                $stagedPath = (string)$restored['relative_path'];
                $sourcePath = $incoming->resolve($stagedPath);
                $payload = $effectiveJob->payload;
                $context->checkpoint([
                    'stage' => 'archive_member_source_restored',
                    'done' => 1,
                    'total' => 100,
                    'percent' => 1,
                    'message' => 'Restored missing archive-member staging from the retained parent archive.',
                    'restored_bytes' => (int)($restored['size'] ?? 0),
                ]);
            }
        }

        $classification = $this->classifier->classify($sourcePath, $originalName, $entryPath);
        $kind = (string)($classification['kind'] ?? 'unknown');
        $format = strtolower((string)($classification['format'] ?? ''));
        $reason = trim((string)($classification['reason'] ?? ''));

        if ($kind === 'skip') {
            $incoming->delete($stagedPath);
            if ($sourceFromPrepared) {
                $preparedStore->clear();
            }
            $message = 'Skipped archive member ' . $entryPath . ': ' . ($reason !== '' ? $reason : 'non-package metadata.');
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => 'skipped',
                'message' => $message,
                'content_classification' => 'skip',
            ]);
            return [
                'operation' => 'archive_member_content_route',
                'status' => 'skipped',
                'message' => $message,
                'original_name' => $originalName,
                'source_relative_path' => trim((string)($payload['source_relative_path'] ?? $entryPath)),
                'source_retained' => false,
            ];
        }

        if ($kind === 'redirect' && in_array($format, ['uz2', 'uz3'], true)) {
            if ($sourceFromPrepared) {
                $restaged = $incoming->stageLocalFile($sourcePath, $originalName);
                $preparedStore->clear();
                $effectiveJob = $this->withStagedFile($effectiveJob, $restaged);
                $stagedPath = (string)$restaged['relative_path'];
            }
            $syntheticName = $this->syntheticTransportName($originalName, $format);
            $context->checkpoint([
                'stage' => 'content_redirect',
                'done' => 2,
                'total' => 100,
                'percent' => 2,
                'message' => 'Archive member content is ' . strtoupper($format)
                    . ' redirect data despite its filename; routing through the redirect decoder.',
                'detected_format' => $format,
            ]);
            return $this->inner->handle(
                $this->withOriginalName($effectiveJob, $syntheticName, $originalName, $format),
                $context
            );
        }

        if ($kind === 'archive' && in_array($format, ['zip', 'rar', '7z'], true)) {
            if ($sourceFromPrepared) {
                $restaged = $incoming->stageLocalFile($sourcePath, $originalName);
                $preparedStore->clear();
                $effectiveJob = $this->withStagedFile($effectiveJob, $restaged);
                $stagedPath = (string)$restaged['relative_path'];
            }
            $childId = $this->enqueueNestedArchive($effectiveJob, $format, $originalName);
            $waiting = [
                'archive_member_router_version' => self::ROUTER_VERSION,
                'stage' => self::WAIT_STAGE,
                'done' => 0,
                'total' => 1,
                'percent' => 50,
                'status' => 'running',
                'message' => 'Detected embedded ' . strtoupper($format) . ' container in ' . $entryPath
                    . '; waiting for nested archive job #' . $childId . '.',
                'nested_archive_job_id' => $childId,
                'detected_format' => $format,
                'original_name' => $originalName,
                'source_relative_path' => trim((string)($payload['source_relative_path'] ?? $entryPath)),
            ];
            $context->checkpoint($waiting);
            return $this->waitForNestedArchive($effectiveJob, $context, $waiting);
        }

        return $this->inner->handle($effectiveJob, $context);
    }

    /** @param array<string,mixed> $resume @return array<string,mixed> */
    private function waitForNestedArchive(ClaimedJob $job, JobExecutionContext $context, array $resume): array
    {
        $state = $this->children->fetch($job->id);
        if ($state['total'] < 1) {
            throw new \RuntimeException('Nested archive content route has no child archive job.');
        }
        if (($state['queued'] + $state['running']) > 0) {
            $terminal = max(0, (int)$state['terminal']);
            $total = max(1, (int)$state['total']);
            $waiting = $resume;
            $waiting['done'] = $terminal;
            $waiting['total'] = $total;
            $waiting['percent'] = min(99, 50 + (int)floor(($terminal * 49) / $total));
            $waiting['status'] = 'running';
            $waiting['message'] = 'Nested archive content workflow: '
                . number_format($terminal) . '/' . number_format($total) . ' terminal, '
                . number_format((int)$state['running']) . ' running, '
                . number_format((int)$state['queued']) . ' queued.';
            $context->checkpoint($waiting);
            $context->defer(2, $waiting, true);
        }

        $failed = max(0, (int)$state['failed']);
        $cancelled = max(0, (int)$state['cancelled']);
        $invalidUe = max(0, (int)($state['invalid_ue'] ?? 0));
        $partial = $failed > 0 || $cancelled > 0;
        $childDetail = $this->nestedChildDetail($job->id);
        $message = $partial
            ? 'Nested archive content finished with ' . number_format($failed) . ' failed and '
                . number_format($cancelled) . ' cancelled child workflow(s).'
            : ($invalidUe > 0
                ? 'Nested archive extraction completed; '
                    . number_format($invalidUe) . ' invalid UE file' . ($invalidUe === 1 ? '' : 's') . ' found.'
                : 'Nested archive content processed successfully.');
        if ($childDetail !== '') {
            $message .= ' ' . $childDetail;
        }

        $status = $partial
            ? 'partial'
            : ($invalidUe > 0 ? CatalogImportOutcome::ARCHIVE_INVALID_FILES : 'nested_archive');
        $context->checkpoint([
            'archive_member_router_version' => self::ROUTER_VERSION,
            'stage' => 'complete',
            'done' => max(1, (int)$state['total']),
            'total' => max(1, (int)$state['total']),
            'percent' => 100,
            'status' => $status,
            'message' => $message,
            'children' => $state,
            'nested_archive_job_id' => (int)($resume['nested_archive_job_id'] ?? 0),
            'detected_format' => (string)($resume['detected_format'] ?? ''),
        ]);

        return [
            'operation' => 'archive_member_content_route',
            'status' => $status,
            'message' => $message,
            'original_name' => (string)($resume['original_name'] ?? ($job->payload['original_name'] ?? '')),
            'source_relative_path' => (string)($resume['source_relative_path'] ?? ($job->payload['source_relative_path'] ?? '')),
            'nested_archive_job_id' => (int)($resume['nested_archive_job_id'] ?? 0),
            'children' => $state,
            'source_retained' => $partial,
        ];
    }

    private function enqueueNestedArchive(ClaimedJob $job, string $format, string $originalName): int
    {
        $payload = $job->payload;
        $parentArchiveId = $job->parentJobId ?? (int)($payload['archive_parent_job_id'] ?? 0);
        $parentArchive = $this->archiveParentPayload($parentArchiveId);
        $parentDepth = max(0, (int)($parentArchive['archive_depth'] ?? $payload['archive_depth'] ?? 0));
        $maxDepth = $this->maxNestingDepth();
        if ($parentDepth >= $maxDepth) {
            throw new \RuntimeException(
                'Nested archive depth limit of ' . $maxDepth . ' reached while content-routing ' . $originalName . '.'
            );
        }

        $rootJobId = max(0, (int)($parentArchive['archive_root_job_id'] ?? $payload['archive_root_job_id'] ?? 0));
        if ($rootJobId < 1) {
            $rootJobId = $parentArchiveId > 0 ? $parentArchiveId : $job->id;
        }

        $syntheticName = $this->syntheticTransportName($originalName, $format);
        $childPayload = $payload;
        $childPayload['original_name'] = $syntheticName;
        $childPayload['archive_parent_job_id'] = $job->id;
        $childPayload['archive_root_job_id'] = $rootJobId;
        $childPayload['archive_depth'] = $parentDepth + 1;
        $childPayload['nested_archive'] = true;
        $childPayload['content_detected_archive'] = $format;
        $childPayload['content_original_name'] = $originalName;
        $childPayload['source_kind'] = 'archive-entry';

        $childType = $job->type === JobType::IMPORT_STAGED_PACKAGE
            ? JobType::IMPORT_STAGED_ARCHIVE
            : JobType::PROCESS_BUCKET_ARCHIVE;
        $userId = (int)($payload['user_id'] ?? 0);
        $entryPath = trim((string)($payload['archive_entry_path'] ?? $originalName));
        $dedupe = 'archive-content:' . $job->id . ':' . hash('sha256', strtolower($entryPath) . ':' . $format);

        return (new PdoJobQueue($this->db))->enqueue(
            $job->queue,
            $childType,
            $childPayload,
            5,
            null,
            $dedupe,
            $userId > 0 ? $userId : null,
            3,
            $job->id,
            'archive:content-container:' . hash('sha256', strtolower($entryPath))
        );
    }

    /** @return array<string,mixed> */
    private function archiveParentPayload(int $jobId): array
    {
        if ($jobId < 1) {
            return [];
        }
        $statement = $this->db->prepare('SELECT payload_json FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$jobId]);
        $json = $statement->fetchColumn();
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nestedChildDetail(int $jobId): string
    {
        $statement = $this->db->prepare(
            'SELECT id,display_status,result_json,last_error FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key LIKE "archive:%" ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([$jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return '';
        }
        $detail = trim((string)($row['last_error'] ?? ''));
        $result = json_decode((string)($row['result_json'] ?? ''), true);
        if ($detail === '' && is_array($result)) {
            $detail = trim((string)($result['message'] ?? ''));
        }
        if ($detail === '') {
            $detail = trim((string)($row['display_status'] ?? ''));
        }
        if ($detail === '') {
            return 'Nested archive job #' . (int)$row['id'] . ' finished.';
        }
        if (function_exists('mb_substr')) {
            $detail = mb_substr($detail, 0, 800, 'UTF-8');
        } else {
            $detail = substr($detail, 0, 800);
        }
        return 'Nested archive job #' . (int)$row['id'] . ': ' . $detail;
    }

    private function isArchiveMemberJob(ClaimedJob $job): bool
    {
        if (!in_array($job->type, [JobType::IMPORT_STAGED_PACKAGE, JobType::PROCESS_BUCKET_STAGED_PACKAGE], true)) {
            return false;
        }
        return $job->parentJobId !== null
            || (int)($job->payload['archive_parent_job_id'] ?? 0) > 0
            || (string)($job->payload['source_kind'] ?? '') === 'archive-entry';
    }

    private function syntheticTransportName(string $originalName, string $format): string
    {
        $format = strtolower($format);
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === $format) {
            return $originalName;
        }
        return $originalName . '.' . $format;
    }

    private function maxNestingDepth(): int
    {
        $environment = getenv('UNREALDB_ARCHIVE_MAX_NESTING_DEPTH');
        $configured = (int)($this->config['archive']['max_nesting_depth'] ?? (is_string($environment) ? $environment : 0));
        if ($configured < 1) {
            $configured = self::DEFAULT_MAX_NESTING_DEPTH;
        }
        return min(self::MAX_CONFIGURED_NESTING_DEPTH, max(1, $configured));
    }

    /** @param array<string,mixed> $staged */
    private function withStagedFile(ClaimedJob $job, array $staged): ClaimedJob
    {
        $payload = $job->payload;
        $payload['staged_path'] = (string)($staged['relative_path'] ?? '');
        $payload['size'] = max(0, (int)($staged['size'] ?? 0));
        $sha256 = strtolower(trim((string)($staged['sha256'] ?? '')));
        if ($sha256 !== '') {
            $payload['sha256'] = $sha256;
        }
        $payload['archive_member_source_restored'] = true;
        return $this->withPayload($job, $payload);
    }

    private function withOriginalName(
        ClaimedJob $job,
        string $syntheticName,
        string $contentOriginalName,
        string $format
    ): ClaimedJob {
        $payload = $job->payload;
        $payload['original_name'] = $syntheticName;
        $payload['content_original_name'] = $contentOriginalName;
        $payload['content_detected_redirect'] = $format;
        return $this->withPayload($job, $payload);
    }

    /** @param array<string,mixed> $payload */
    private function withPayload(ClaimedJob $job, array $payload): ClaimedJob
    {
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

    private function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? $message : get_class($error);
    }
}
