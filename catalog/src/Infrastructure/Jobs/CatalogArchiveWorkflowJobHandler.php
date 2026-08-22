<?php
/**
 * Coordinates the logical lifetime of an archive import job.
 *
 * CatalogArchiveImportJobHandler owns archive decoding and durable child creation.
 * This coordinator owns the parent lifecycle and source ownership: ingress bytes
 * are first transferred into job-owned prepared storage, then the parent is
 * deferred in archive_wait_children until every child is terminal.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoArchiveChildOutcomeQuery;

final class CatalogArchiveWorkflowJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 1;
    private const WAIT_STAGE = 'archive_wait_children';

    private readonly CatalogArchiveImportJobHandler $extractor;
    private readonly PdoArchiveChildOutcomeQuery $children;
    private readonly CatalogArchiveSourceStore $sources;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->extractor = new CatalogArchiveImportJobHandler($db, $config);
        $this->children = new PdoArchiveChildOutcomeQuery($db);
        $this->sources = new CatalogArchiveSourceStore($config);
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::IMPORT_STAGED_ARCHIVE, JobType::PROCESS_BUCKET_ARCHIVE], true);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $resume = $context->resumeProgress();
        $childState = $this->children->fetch($job->id);
        $archiveResult = $this->resumeArchiveResult($job, $resume, $childState);

        if ($archiveResult === null) {
            // The archive workflow owns its immutable source before any extraction
            // occurs. Browser chunk staging is ingress only and may be cleaned once
            // the parent has atomically published its prepared archive source.
            $ownedJob = $this->sources->prepareJob($job);
            $archiveResult = $this->extractor->handle($ownedJob, $context);
            $childState = $this->children->fetch($job->id);
        }

        if ($childState['total'] < 1) {
            return $this->finalResult($job, $archiveResult, $childState, $context);
        }

        if (($childState['queued'] + $childState['running']) > 0) {
            $waiting = $this->waitingProgress($archiveResult, $childState);
            // The extraction delegate's final checkpoint describes the extraction
            // phase, not the logical parent job. Immediately replace it with the
            // authoritative waiting phase before releasing the worker so event and
            // progress views never leave "complete" as the latest parent state.
            $context->checkpoint($waiting);
            $context->defer(2, $waiting, true);
        }

        return $this->finalResult($job, $archiveResult, $childState, $context);
    }

    /**
     * Recover coordinator state without replaying extraction after a worker dies
     * between the extractor's final checkpoint and this coordinator's defer.
     * Retained-archive manual Restart explicitly clears progress_json, so a real
     * administrator retry still performs a fresh archive walk from the retained
     * job-owned source.
     *
     * @param array<string,mixed> $resume
     * @param array<string,int> $children
     * @return array<string,mixed>|null
     */
    private function resumeArchiveResult(ClaimedJob $job, array $resume, array $children): ?array
    {
        if ((int)($resume['archive_workflow_version'] ?? 0) === self::WORKFLOW_VERSION
            && (string)($resume['stage'] ?? '') === self::WAIT_STAGE
            && is_array($resume['archive_result'] ?? null)) {
            return $resume['archive_result'];
        }

        // CatalogArchiveImportJobHandler persists its extraction result as
        // stage=complete before returning it. If the worker dies in the very small
        // window before this coordinator stores archive_wait_children, recover the
        // extraction counters from that checkpoint rather than replaying the
        // archive. A manual retained-archive restart clears progress deliberately.
        if ((string)($resume['stage'] ?? '') === 'complete'
            && (int)($resume['workflow_version'] ?? 0) > 0
            && (int)($children['total'] ?? 0) > 0) {
            return $this->resultFromExtractionProgress($job, $resume);
        }

        return null;
    }

    /** @param array<string,mixed> $progress @return array<string,mixed> */
    private function resultFromExtractionProgress(ClaimedJob $job, array $progress): array
    {
        $originalName = trim((string)($job->payload['original_name'] ?? 'archive.bin'));
        $sourceRelativePath = trim((string)($job->payload['source_relative_path'] ?? $originalName));
        $failed = max(0, (int)($progress['failed'] ?? 0));

        return [
            'operation' => $job->type === JobType::IMPORT_STAGED_ARCHIVE
                ? 'import_staged_archive'
                : 'process_bucket_archive',
            'status' => $failed > 0 ? 'partial' : 'completed',
            'original_name' => $originalName,
            'source_relative_path' => $sourceRelativePath,
            'archive_entries' => max(0, (int)($progress['entry_cursor'] ?? $progress['total'] ?? 0)),
            'queued_files' => max(0, (int)($progress['queued'] ?? 0)),
            'skipped_files' => max(0, (int)($progress['skipped'] ?? 0)),
            'failed_files' => $failed,
            'unpacked_bytes' => max(0, (int)($progress['unpacked_bytes'] ?? 0)),
            'source_retained' => !empty($progress['source_retained']),
            'sequential_archive' => !empty($progress['sequential_archive']),
            'archive_format' => (string)($progress['archive_format'] ?? ''),
            'errors' => is_array($progress['errors'] ?? null) ? array_values($progress['errors']) : [],
            'message' => trim((string)($progress['message'] ?? 'Archive extraction completed.')),
        ];
    }

    /** @param array<string,mixed> $archiveResult @param array<string,int> $children @return array<string,mixed> */
    private function waitingProgress(array $archiveResult, array $children): array
    {
        $terminal = max(0, (int)$children['terminal']);
        $total = max(1, (int)$children['total']);
        $percent = min(99, 85 + (int)floor(($terminal * 14) / $total));
        $message = 'Archive child jobs: '
            . number_format($terminal) . '/' . number_format((int)$children['total']) . ' terminal, '
            . number_format((int)$children['running']) . ' running, '
            . number_format((int)$children['queued']) . ' queued.';

        return [
            'archive_workflow_version' => self::WORKFLOW_VERSION,
            'stage' => self::WAIT_STAGE,
            'done' => $terminal,
            'total' => $total,
            'percent' => $percent,
            'status' => 'running',
            'message' => $message,
            'archive_result' => $archiveResult,
            'children' => $children,
            // Preserve the extraction counters used by the existing archive
            // reporting/recovery UI while the logical parent remains non-terminal.
            'entry_cursor' => max(0, (int)($archiveResult['archive_entries'] ?? 0)),
            'queued' => max(0, (int)($archiveResult['queued_files'] ?? 0)),
            'skipped' => max(0, (int)($archiveResult['skipped_files'] ?? 0)),
            'failed' => max(0, (int)($archiveResult['failed_files'] ?? 0)),
            'unpacked_bytes' => max(0, (int)($archiveResult['unpacked_bytes'] ?? 0)),
            'errors' => is_array($archiveResult['errors'] ?? null) ? $archiveResult['errors'] : [],
            'source_retained' => !empty($archiveResult['source_retained']),
            'sequential_archive' => !empty($archiveResult['sequential_archive']),
            'archive_format' => (string)($archiveResult['archive_format'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $archiveResult
     * @param array<string,int> $children
     * @return array<string,mixed>
     */
    private function finalResult(
        ClaimedJob $job,
        array $archiveResult,
        array $children,
        JobExecutionContext $context
    ): array {
        $extractionFailed = max(0, (int)($archiveResult['failed_files'] ?? 0));
        $childFailed = max(0, (int)$children['failed']);
        $cancelled = max(0, (int)$children['cancelled']);
        $totalFailed = $extractionFailed + $childFailed;
        $partial = $totalFailed > 0 || $cancelled > 0;
        $successLabel = $job->type === JobType::IMPORT_STAGED_ARCHIVE ? 'imported' : 'added';

        $message = 'Archive processing complete: '
            . number_format((int)$children['successful']) . ' ' . $successLabel . ', '
            . number_format((int)$children['duplicate']) . ' duplicate, '
            . number_format($totalFailed) . ' failed';
        if ($cancelled > 0) {
            $message .= ', ' . number_format($cancelled) . ' cancelled';
        }
        $message .= '.';

        $result = $archiveResult;
        $result['status'] = $partial ? 'partial' : 'completed';
        $result['message'] = $message;
        $result['archive_outcomes'] = [
            'archive_member_failed' => $extractionFailed,
            'child_failed' => $childFailed,
            'total_failed' => $totalFailed,
        ] + $children;

        $context->checkpoint([
            'archive_workflow_version' => self::WORKFLOW_VERSION,
            'stage' => 'complete',
            'done' => max(1, (int)$children['total']),
            'total' => max(1, (int)$children['total']),
            'percent' => 100,
            'status' => $result['status'],
            'message' => $message,
            'children' => $children,
            'queued' => max(0, (int)($archiveResult['queued_files'] ?? 0)),
            'skipped' => max(0, (int)($archiveResult['skipped_files'] ?? 0)),
            'failed' => $extractionFailed,
            'errors' => is_array($archiveResult['errors'] ?? null) ? $archiveResult['errors'] : [],
            'source_retained' => !empty($archiveResult['source_retained']),
        ]);

        return $result;
    }
}
