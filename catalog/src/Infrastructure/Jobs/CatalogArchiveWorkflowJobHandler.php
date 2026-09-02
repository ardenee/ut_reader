<?php
/**
 * Coordinates the logical lifetime of an archive import job.
 *
 * CatalogArchiveImportJobHandler owns archive decoding and durable package-child
 * creation. CatalogNestedArchiveJobEnqueuer discovers embedded supported archive
 * containers and queues each as its own durable archive child workflow. This
 * coordinator owns the parent lifecycle and source ownership: ingress bytes are
 * first transferred into job-owned prepared storage. A clean extraction releases
 * that parent source immediately after every selected member has its own durable
 * child staging; only extraction/decoder failures retain the parent bytes.
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
    private const WORKFLOW_VERSION = 2;
    private const WAIT_STAGE = 'archive_wait_children';

    private readonly CatalogArchiveImportJobHandler $extractor;
    private readonly CatalogNestedArchiveJobEnqueuer $nestedArchives;
    private readonly PdoArchiveChildOutcomeQuery $children;
    private readonly CatalogArchiveSourceStore $sources;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->extractor = new CatalogArchiveImportJobHandler($db, $config);
        $this->nestedArchives = new CatalogNestedArchiveJobEnqueuer($db, $config);
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

            // Embedded supported archive containers become archive child workflows
            // rather than being recursively expanded on this worker stack. The
            // established extractor still performs its normal pass afterwards for
            // ordinary Unreal members and keeps all decoder/recovery behaviour.
            $nestedResult = $this->nestedArchives->enqueue($ownedJob, $context);
            $archiveResult = $this->extractor->handle($ownedJob, $context);
            $archiveResult = $this->mergeNestedArchiveResult($archiveResult, $nestedResult);
            $childState = $this->children->fetch($job->id);
        }

        if ($childState['total'] < 1) {
            $result = $this->finalResult($job, $archiveResult, $childState, $context);
            $this->releaseSourceIfDisposable($job, $result);
            return $result;
        }

        if (($childState['queued'] + $childState['running']) > 0) {
            // Keep the parent archive source until every child has reached a
            // terminal outcome. A child can expose a parser/decoder problem only
            // after extraction has already succeeded; releasing the archive here
            // would make later explicit retry/revalidation depend on re-uploading.
            $waiting = $this->waitingProgress($archiveResult, $childState);
            $waiting['source_retained'] = true;
            // The extraction delegate's final checkpoint describes the extraction
            // phase, not the logical parent job. Immediately replace it with the
            // authoritative waiting phase before releasing the worker so event and
            // progress views never leave "complete" as the latest parent state.
            $context->checkpoint($waiting);
            $context->defer(2, $waiting, true);
        }

        $result = $this->finalResult($job, $archiveResult, $childState, $context);
        $this->releaseSourceIfDisposable($job, $result);
        return $result;
    }

    /**
     * The established extractor deliberately reports archive members as skipped.
     * Nested archive members are owned by CatalogNestedArchiveJobEnqueuer, so
     * remove only those classified members from skipped and fold their child
     * jobs/failures into the extraction counters used by reporting and recovery.
     *
     * @param array<string,mixed> $archiveResult
     * @param array<string,mixed> $nestedResult
     * @return array<string,mixed>
     */
    private function mergeNestedArchiveResult(array $archiveResult, array $nestedResult): array
    {
        $handled = max(0, (int)($nestedResult['handled'] ?? 0));
        $queued = max(0, (int)($nestedResult['queued'] ?? 0));
        $reused = max(0, (int)($nestedResult['reused'] ?? 0));
        $failed = max(0, (int)($nestedResult['failed'] ?? 0));
        $nestedChildren = $queued + $reused;

        $archiveResult['nested_archives'] = $nestedResult;
        $archiveResult['nested_archive_jobs'] = $nestedChildren;
        $archiveResult['queued_files'] = max(0, (int)($archiveResult['queued_files'] ?? 0)) + $nestedChildren;
        $archiveResult['skipped_files'] = max(0, (int)($archiveResult['skipped_files'] ?? 0) - $handled);
        $archiveResult['failed_files'] = max(0, (int)($archiveResult['failed_files'] ?? 0)) + $failed;
        $archiveResult['unpacked_bytes'] = max(0, (int)($archiveResult['unpacked_bytes'] ?? 0))
            + max(0, (int)($nestedResult['unpacked_bytes'] ?? 0));

        $errors = is_array($archiveResult['errors'] ?? null) ? array_values($archiveResult['errors']) : [];
        foreach (is_array($nestedResult['errors'] ?? null) ? $nestedResult['errors'] : [] as $error) {
            if (is_array($error)) {
                $errors[] = $error;
            }
        }
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }
        $archiveResult['errors'] = $errors;

        if ($failed > 0) {
            $archiveResult['status'] = 'partial';
            $archiveResult['source_retained'] = true;
        }
        return $archiveResult;
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

        $nestedJobs = max(0, (int)($archiveResult['nested_archive_jobs'] ?? 0));
        if ($nestedJobs > 0) {
            $message .= ' ' . number_format($nestedJobs) . ' nested archive workflow(s).';
        }

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
            'source_retained' => !empty($result['source_retained']),
            'sequential_archive' => !empty($archiveResult['sequential_archive']),
            'archive_format' => (string)($archiveResult['archive_format'] ?? ''),
            'nested_archives' => is_array($archiveResult['nested_archives'] ?? null)
                ? $archiveResult['nested_archives']
                : [],
        ];
    }

    /** @param array<string,mixed> $archiveResult */
    private function releaseSourceIfDisposable(ClaimedJob $job, array $archiveResult): void
    {
        if (!empty($archiveResult['source_retained'])) {
            return;
        }
        $this->sources->clear($job->id);
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
        $extractionSkipped = max(0, (int)($archiveResult['skipped_files'] ?? 0));
        $childSkipped = max(0, (int)($children['skipped'] ?? 0));
        $totalSkipped = $extractionSkipped + $childSkipped;
        $contentNested = max(0, (int)($children['nested_archive'] ?? 0));
        $unverified = max(0, (int)($children['unverified'] ?? 0));
        $invalidUe = max(0, (int)($children['invalid_ue'] ?? 0));
        $totalFailed = $extractionFailed + $childFailed;
        $partial = $totalFailed > 0 || $cancelled > 0;
        $successLabel = $job->type === JobType::IMPORT_STAGED_ARCHIVE ? 'imported' : 'added';

        $message = 'Archive processing complete: '
            . number_format((int)$children['successful']) . ' ' . $successLabel . ', '
            . number_format((int)$children['duplicate']) . ' duplicate, '
            . number_format($totalSkipped) . ' skipped, '
            . number_format($contentNested) . ' nested archive, '
            . number_format($unverified) . ' unverified/profile mismatch, '
            . number_format($invalidUe) . ' invalid UE file' . ($invalidUe === 1 ? '' : 's') . ', '
            . number_format($totalFailed) . ' failed';
        if ($cancelled > 0) {
            $message .= ', ' . number_format($cancelled) . ' cancelled';
        }
        $message .= '.';
        $nestedJobs = max(0, (int)($archiveResult['nested_archive_jobs'] ?? 0));
        if ($nestedJobs > 0) {
            $message .= ' Extension-identified nested archive workflows: ' . number_format($nestedJobs) . '.';
        }

        $result = $archiveResult;
        $result['status'] = $partial ? 'partial' : 'completed';
        $result['message'] = $message;
        // Extraction success alone is not enough to discard the parent source.
        // Keep it when a child needs operator attention so the complete archive
        // tree remains reproducible after reader/decoder fixes without re-upload.
        $result['source_retained'] = !empty($archiveResult['source_retained'])
            || $childFailed > 0
            || $cancelled > 0
            || $invalidUe > 0;
        $result['archive_outcomes'] = [
            'archive_member_skipped' => $extractionSkipped,
            'child_skipped' => $childSkipped,
            'total_skipped' => $totalSkipped,
            'archive_member_failed' => $extractionFailed,
            'child_failed' => $childFailed,
            'invalid_ue_files' => $invalidUe,
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
            'skipped' => $totalSkipped,
            'failed' => $extractionFailed,
            'errors' => is_array($archiveResult['errors'] ?? null) ? $archiveResult['errors'] : [],
            'source_retained' => !empty($archiveResult['source_retained']),
            'nested_archives' => is_array($archiveResult['nested_archives'] ?? null)
                ? $archiveResult['nested_archives']
                : [],
        ]);

        return $result;
    }
}
