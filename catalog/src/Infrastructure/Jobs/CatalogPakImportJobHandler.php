<?php
/**
 * Durable UE4/UE5 PAK import workflow.
 *
 * The parent performs archive extraction/index selection once and publishes the
 * extracted tree into a per-job durable workspace. Each PAK index entry is then
 * an independent child job. A failed child can be restarted without extracting
 * the archive again or replaying completed sibling entries. The final game
 * dependency rebuild is itself a nested resumable workflow.
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
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

final class CatalogPakImportJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 500;
    private const MAX_RESULT_MESSAGES = 200;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::IMPORT_STAGED_PAK, JobType::IMPORT_STAGED_PAK_ENTRY], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
        require_once __DIR__ . '/../../../lib/CatalogPakArchive.php';
        require_once __DIR__ . '/../../../lib/GameProfiles.php';

        return $job->type === JobType::IMPORT_STAGED_PAK_ENTRY
            ? $this->importEntry($job, $context)
            : $this->coordinate($job, $context);
    }

    /** @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context): array
    {
        $payload = $job->payload;
        $gameId = $this->positiveInt($payload, 'game_id');
        $stagedPath = $this->requiredString($payload, 'staged_path');
        $originalName = $this->requiredString($payload, 'original_name');
        $strict = !array_key_exists('strict_profile', $payload) || (bool)$payload['strict_profile'];
        $userId = isset($payload['user_id']) && (int)$payload['user_id'] > 0 ? (int)$payload['user_id'] : null;
        $workspace = new CatalogPakImportWorkspace($this->config, $job->id);
        $archiveStore = new CatalogPakArchiveStore($this->config);
        $resume = $context->resumeProgress();

        if ((string)($resume['stage'] ?? '') === 'pak_finished' && (int)($resume['pak_id'] ?? 0) > 0) {
            return $this->finalizeCleanup($job, $workspace, $resume, $context);
        }

        if (!$workspace->available()) {
            try {
                $this->prepareWorkspace(
                    $job,
                    $context,
                    $workspace,
                    $archiveStore,
                    $gameId,
                    $stagedPath,
                    $originalName,
                    $strict,
                    $userId
                );
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (PDOException|\InvalidArgumentException|\Error $error) {
                throw $error;
            } catch (Throwable $error) {
                if ($this->isInfrastructureFailure($error)) {
                    throw $error;
                }
                $message = $this->shortError($error);
                $context->checkpoint([
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'stage' => 'complete',
                    'done' => 100,
                    'total' => 100,
                    'percent' => 100,
                    'status' => 'failed',
                    'message' => $message,
                ]);
                return [
                    'operation' => 'import_staged_pak',
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'status' => 'failed',
                    'pak_id' => 0,
                    'message' => $message,
                    'original_name' => $originalName,
                    'messages' => [[
                        'status' => 'failed',
                        'file' => $originalName,
                        'message' => $message,
                        'file_id' => 0,
                        'pak_entry_id' => 0,
                    ]],
                ];
            }
            $resume = $context->resumeProgress();
        }

        $state = $workspace->state();
        $this->validateWorkspaceState($state, $gameId);
        $pakId = (int)$state['pak_id'];
        $index = is_array($state['index'] ?? null) ? $state['index'] : [];
        $entries = is_array($index['entries'] ?? null) ? array_values($index['entries']) : [];
        $total = count($entries);
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start' || $stage === 'pak_prepared') {
            $stage = 'pak_entry_plan';
            $resume = [];
        }

        if ($stage === 'pak_entry_plan') {
            $this->planEntries($job, $context, $total, $gameId, $pakId, $strict, $userId, $resume);
            $stage = 'pak_entry_wait';
        }

        if ($stage === 'pak_entry_wait') {
            $children = $this->childState($job->id, 'pak-entry:');
            $denominator = max(1, $children['total']);
            $percent = 5 + (int)floor(($children['completed'] * 80) / $denominator);
            $problem = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
            if ($problem > 0) {
                $context->defer(30, $this->progress(
                    'pak_entry_wait',
                    min(85, $percent),
                    'PAK import is waiting on ' . $problem . ' failed/cancelled entry unit(s). '
                        . 'Restart only those child jobs; ' . $children['completed'] . ' successful entry unit(s) are retained.',
                    ['pak_id' => $pakId, 'children' => $children]
                ));
            }
            if (($children['queued'] + $children['running']) > 0) {
                $context->defer(2, $this->progress(
                    'pak_entry_wait',
                    min(85, $percent),
                    'PAK entry units: ' . $children['completed'] . '/' . $children['total']
                        . ' complete, ' . $children['running'] . ' running, ' . $children['queued'] . ' queued.',
                    ['pak_id' => $pakId, 'children' => $children]
                ));
            }

            $aggregate = $this->aggregateEntryResults($job->id);
            $nextStage = ($aggregate['imported'] + $aggregate['aliases']) > 0
                ? 'pak_dependency_plan'
                : 'pak_finalize';
            $context->checkpoint($this->progress(
                $nextStage,
                $nextStage === 'pak_dependency_plan' ? 87 : 98,
                $nextStage === 'pak_dependency_plan'
                    ? 'All PAK entry units completed; queueing the resumable game dependency rebuild.'
                    : 'All PAK entry units completed; no dependency rebuild is required.',
                ['pak_id' => $pakId, 'entry_results' => $aggregate]
            ));
            $stage = $nextStage;
        }

        if ($stage === 'pak_dependency_plan') {
            $dependencyJobId = (new PdoJobQueue($this->db))->enqueue(
                $job->queue,
                JobType::REBUILD_GAME_DEPENDENCIES,
                [
                    'game_id' => $gameId,
                    'offset' => 0,
                    'requested_by' => $userId,
                    'workflow_parent_job_id' => $job->id,
                ],
                20,
                null,
                null,
                $userId,
                3,
                $job->id,
                'dependencies'
            );
            $context->checkpoint($this->progress(
                'pak_dependency_wait',
                88,
                'Dependency workflow #' . $dependencyJobId . ' queued.',
                ['pak_id' => $pakId, 'dependency_job_id' => $dependencyJobId]
            ));
            $stage = 'pak_dependency_wait';
        }

        if ($stage === 'pak_dependency_wait') {
            $dependency = $this->workflowChild($job->id, 'dependencies');
            if ($dependency === null) {
                $context->checkpoint($this->progress(
                    'pak_dependency_plan',
                    87,
                    'Dependency workflow child was not found; replanning it.',
                    ['pak_id' => $pakId]
                ));
                $context->defer(1);
            }
            $dependencyStatus = (string)($dependency['status'] ?? 'queued');
            if (in_array($dependencyStatus, ['failed', 'dead_letter', 'cancelled'], true)) {
                $context->defer(30, $this->progress(
                    'pak_dependency_wait',
                    92,
                    'Dependency child job #' . (int)$dependency['id'] . ' requires attention. '
                        . 'Restart that child only; completed PAK entries and the extracted workspace are retained.',
                    [
                        'pak_id' => $pakId,
                        'dependency_job_id' => (int)$dependency['id'],
                        'dependency_status' => $dependencyStatus,
                    ]
                ));
            }
            if ($dependencyStatus !== 'completed') {
                $dependencyProgress = json_decode((string)($dependency['progress_json'] ?? ''), true);
                $innerPercent = is_array($dependencyProgress)
                    ? max(0, min(100, (int)($dependencyProgress['percent'] ?? 0)))
                    : 0;
                $context->defer(2, $this->progress(
                    'pak_dependency_wait',
                    88 + (int)floor(($innerPercent * 9) / 100),
                    'Dependency workflow #' . (int)$dependency['id'] . ' is ' . $dependencyStatus . '.',
                    ['pak_id' => $pakId, 'dependency_job_id' => (int)$dependency['id']]
                ));
            }
            $context->checkpoint($this->progress(
                'pak_finalize',
                98,
                'Dependency workflow completed; finalizing retained PAK metadata.',
                ['pak_id' => $pakId]
            ));
            $stage = 'pak_finalize';
        }

        if ($stage !== 'pak_finalize') {
            throw new \RuntimeException('Unknown PAK import workflow stage: ' . $stage);
        }

        $aggregate = $this->aggregateEntryResults($job->id);
        $extractedFiles = is_array($state['extracted_files'] ?? null) ? $state['extracted_files'] : [];
        $archiveStore->finish(
            $this->db,
            $pakId,
            count($extractedFiles),
            $aggregate['skipped'] + $aggregate['not_extracted'],
            (string)($state['extract_log'] ?? '')
        );

        $finished = $this->progress(
            'pak_finished',
            99,
            'PAK metadata is finalized; cleaning durable import workspace.',
            [
                'pak_id' => $pakId,
                'game_id' => $gameId,
                'game_name' => (string)($state['game_name'] ?? ''),
                'engine_major' => (int)($state['engine_major'] ?? 0),
                'source_name' => (string)($state['original_name'] ?? $originalName),
                'entry_count' => $total,
                'extracted_files' => count($extractedFiles),
                'imported' => $aggregate['imported'],
                'duplicates' => $aggregate['duplicates'],
                'aliases' => $aggregate['aliases'],
                'failed' => $aggregate['failed'],
                'skipped' => $aggregate['skipped'],
                'not_extracted' => $aggregate['not_extracted'],
                'messages' => $aggregate['messages'],
                'messages_truncated' => $aggregate['messages_truncated'],
                'extract_log' => substr((string)($state['extract_log'] ?? ''), 0, 20000),
            ]
        );
        $context->checkpoint($finished);
        return $this->finalizeCleanup($job, $workspace, $finished, $context);
    }

    private function prepareWorkspace(
        ClaimedJob $job,
        JobExecutionContext $context,
        CatalogPakImportWorkspace $workspace,
        CatalogPakArchiveStore $archiveStore,
        int $gameId,
        string $stagedPath,
        string $originalName,
        bool $strict,
        ?int $userId
    ): void {
        $incoming = new CatalogIncomingFileStore($this->config);
        $sourcePath = $incoming->resolve($stagedPath);
        $this->verifyIdentity($sourcePath, $job->payload);
        if (!\catalog_pak_archive_is_supported_filename($originalName)) {
            throw new \RuntimeException('Staged file is not a supported PAK archive.');
        }
        if (!CatalogPakArchiveStore::schemaInstalled($this->db)) {
            throw new \RuntimeException('PAK archive tables are missing. Run the database migrations first.');
        }

        $game = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,p.engine_key profile_engine FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
            [$gameId]
        );
        if (!$game) {
            throw new \RuntimeException('Target game no longer exists: ' . $gameId);
        }
        $engineMajor = $this->engineMajor((string)($game['profile_engine'] ?? ''));
        if (!in_array($engineMajor, [4, 5], true)) {
            throw new \RuntimeException('Original PAK archive management is available only for UE4 or UE5 game profiles.');
        }

        $eventLog = new CatalogJobEventLog($this->config);
        try {
            $eventLog->reset($job->id);
        } catch (Throwable $error) {
            error_log('[UnrealDB PAK events] Could not reset job #' . $job->id . ': ' . $error->getMessage());
        }

        $context->checkpoint($this->progress(
            'pak_extract',
            1,
            'Extracting and validating UE' . $engineMajor . ' PAK: ' . basename($originalName)
        ));
        $footers = \catalog_pak_footer_candidates($sourcePath);
        if ($footers === []) {
            throw new \RuntimeException('Unsupported PAK file: no Unreal PAK footer was found.');
        }

        $extracted = null;
        try {
            $extracted = \catalog_pak_archive_extract_to_temp($this->config, $sourcePath, $originalName);
            $extractedFiles = is_array($extracted['files'] ?? null) ? array_values($extracted['files']) : [];
            $extractedByPath = [];
            foreach ($extractedFiles as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $relative = $this->normalizeEntryPath((string)($file['relative'] ?? ''));
                if ($relative !== '') {
                    $extractedByPath[strtolower($relative)] = $file;
                }
            }
            [$footer, $index] = $this->selectIndexForExtractedFiles(
                $sourcePath,
                $footers,
                $extractedByPath,
                (string)($extracted['log'] ?? '')
            );
            $pakId = $archiveStore->createOrReset(
                $this->db,
                $game,
                $sourcePath,
                $originalName,
                $footer,
                $index,
                $userId
            );

            $workspace->publish(
                (string)$extracted['dir'],
                [
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'parent_job_id' => $job->id,
                    'pak_id' => $pakId,
                    'game_id' => $gameId,
                    'game_name' => (string)$game['name'],
                    'engine_major' => $engineMajor,
                    'original_name' => $originalName,
                    'staged_path' => $stagedPath,
                    'strict_profile' => $strict,
                    'user_id' => $userId,
                    'footer' => $footer,
                    'index' => $index,
                    'extract_log' => (string)($extracted['log'] ?? ''),
                ],
                $extractedFiles
            );
            // The extraction directory was moved/copied into workspace ownership.
            $extracted = null;
            $context->checkpoint($this->progress(
                'pak_prepared',
                4,
                'PAK extraction and parsed index are durable; planning independent entry jobs.',
                [
                    'pak_id' => $pakId,
                    'entry_count' => count(is_array($index['entries'] ?? null) ? $index['entries'] : []),
                    'extracted_files' => count($extractedFiles),
                ]
            ));
        } finally {
            if (is_array($extracted) && isset($extracted['dir'])) {
                \catalog_pak_archive_delete_tree((string)$extracted['dir']);
            }
        }
    }

    /** @param array<string,mixed> $resume */
    private function planEntries(
        ClaimedJob $job,
        JobExecutionContext $context,
        int $total,
        int $gameId,
        int $pakId,
        bool $strict,
        ?int $userId,
        array $resume
    ): void {
        $next = max(0, (int)($resume['plan_next_entry'] ?? 0));
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        if ((string)($resume['stage'] ?? '') !== 'pak_entry_plan') {
            $next = 0;
            $planned = 0;
        }

        $queue = new PdoJobQueue($this->db);
        $end = min($total, $next + self::PLAN_BATCH_SIZE);
        for ($entryIndex = $next; $entryIndex < $end; $entryIndex++) {
            $queue->enqueue(
                $job->queue,
                JobType::IMPORT_STAGED_PAK_ENTRY,
                [
                    'game_id' => $gameId,
                    'pak_id' => $pakId,
                    'entry_index' => $entryIndex,
                    'strict_profile' => $strict,
                    'user_id' => $userId,
                    'workflow_parent_job_id' => $job->id,
                ],
                50,
                null,
                null,
                $userId,
                3,
                $job->id,
                'pak-entry:' . $entryIndex
            );
            $planned++;
        }
        $next = $end;
        $progress = $this->progress(
            'pak_entry_plan',
            4,
            'Planned ' . $planned . '/' . $total . ' durable PAK entry unit(s).',
            ['pak_id' => $pakId, 'plan_next_entry' => $next, 'planned_units' => $planned]
        );
        if ($next < $total) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->progress(
            'pak_entry_wait',
            5,
            'Planned ' . $planned . ' durable PAK entry unit(s); waiting for workers.',
            ['pak_id' => $pakId, 'planned_units' => $planned]
        ));
    }

    /** @return array<string,mixed> */
    private function importEntry(ClaimedJob $job, JobExecutionContext $context): array
    {
        $parentJobId = $this->positiveInt($job->payload, 'workflow_parent_job_id');
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $pakId = $this->positiveInt($job->payload, 'pak_id');
        $entryIndex = (int)($job->payload['entry_index'] ?? -1);
        if ($entryIndex < 0) {
            throw new \InvalidArgumentException('PAK entry job requires a non-negative entry_index.');
        }
        $strict = !array_key_exists('strict_profile', $job->payload) || (bool)$job->payload['strict_profile'];
        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;
        $workspace = new CatalogPakImportWorkspace($this->config, $parentJobId);
        if (!$workspace->available()) {
            throw new \RuntimeException('Durable PAK import workspace is unavailable for parent job #' . $parentJobId . '.');
        }
        $state = $workspace->state();
        $this->validateWorkspaceState($state, $gameId, $pakId);
        $entry = $workspace->entry($entryIndex);
        $display = $this->normalizeEntryPath((string)($entry['filename'] ?? ''));
        $extractedPath = $display !== '' ? $workspace->extractedPath($display) : null;
        $archiveStore = new CatalogPakArchiveStore($this->config);
        $entryId = $archiveStore->ensureEntry(
            $this->db,
            $pakId,
            $entryIndex,
            $entry,
            $extractedPath !== null
        );

        $context->checkpoint([
            'stage' => 'pak_entry',
            'done' => 0,
            'total' => 1,
            'percent' => 5,
            'pak_id' => $pakId,
            'pak_entry_id' => $entryId,
            'entry_index' => $entryIndex,
            'message' => 'Cataloging PAK entry ' . ($entryIndex + 1) . ': '
                . ($display !== '' ? $display : 'unnamed entry'),
        ]);

        if ($extractedPath === null) {
            $status = !empty($entry['encrypted']) ? 'encrypted' : 'not_extracted';
            $message = !empty($entry['encrypted'])
                ? 'Entry is encrypted.'
                : 'Entry uses an unsupported compression method or could not be extracted.';
            $archiveStore->updateEntry($this->db, $entryId, $status, null, $message);
            $result = $this->entryResult($parentJobId, $pakId, $entryId, $entryIndex, $display, 'not_extracted', $status, 0, $message);
            $this->recordParentEvent($parentJobId, $result);
            $context->checkpoint($this->entryCompleteProgress($result));
            return $result;
        }

        $profile = \gp_required_profile_for_game($this->db, $gameId);
        $allowed = \scanner_profile_extensions($profile, $this->config);
        $name = \catalog_clean_unreal_filename(basename($display));
        $extension = \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === ''
            || in_array($extension, ['uexp', 'ubulk', 'uptnl', 'm_ubulk'], true)
            || !in_array($extension, $allowed, true)) {
            $message = 'Extracted entry is not a standalone package accepted by the selected game profile.';
            $archiveStore->updateEntry($this->db, $entryId, 'skipped', null, $message);
            $result = $this->entryResult($parentJobId, $pakId, $entryId, $entryIndex, $display, 'skipped', 'skipped', 0, $message);
            $this->recordParentEvent($parentJobId, $result);
            $context->checkpoint($this->entryCompleteProgress($result));
            return $result;
        }

        $workingPath = $workspace->workingCopy($extractedPath, $entryIndex);
        try {
            try {
                $scan = \scanner_scan_uploaded_file(
                    $this->db,
                    $this->config,
                    $gameId,
                    $workingPath,
                    $name,
                    $userId,
                    $strict,
                    static function (array $progress) use ($context, $pakId, $entryId, $entryIndex): void {
                        $progress['pak_id'] = $pakId;
                        $progress['pak_entry_id'] = $entryId;
                        $progress['entry_index'] = $entryIndex;
                        $context->heartbeatIfDue($progress);
                    },
                    false,
                    [
                        'source_relative_path' => $display,
                        'source_pak_id' => $pakId,
                        'source_pak_entry_id' => $entryId,
                        'defer_dependency_rebuild' => true,
                    ]
                );
                $status = (string)($scan[0] ?? 'verified');
                $fileId = (int)($scan[1] ?? 0);
                $outcome = $status === 'duplicate' ? 'duplicate' : ($status === 'alias' ? 'alias' : 'imported');
                $message = (string)($scan[2] ?? '');
                $archiveStore->updateEntry($this->db, $entryId, $status, $fileId > 0 ? $fileId : null, $message);
                $workingPath = '';
                $result = $this->entryResult(
                    $parentJobId,
                    $pakId,
                    $entryId,
                    $entryIndex,
                    $display,
                    $outcome,
                    $status,
                    $fileId,
                    $message
                );
                $this->recordParentEvent($parentJobId, $result);
                $context->checkpoint($this->entryCompleteProgress($result));
                return $result;
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (PDOException|\InvalidArgumentException|\Error $error) {
                throw $error;
            } catch (Throwable $error) {
                if ($this->isInfrastructureFailure($error)) {
                    throw $error;
                }
                $stager = new LegacyUnverifiedFileStager($this->db, $this->config);
                $staged = $stager->stageFailedUpload(
                    $gameId,
                    $workingPath,
                    $name,
                    'PAK entry ' . $display . ': ' . $error->getMessage(),
                    $userId,
                    $display
                );
                $workingPath = '';
                $fileId = (int)($staged['file_id'] ?? 0);
                $status = $staged !== null ? 'unverified' : 'rejected';
                $message = $this->shortError($error);
                $archiveStore->updateEntry($this->db, $entryId, $status, $fileId > 0 ? $fileId : null, $message);
                $result = $this->entryResult(
                    $parentJobId,
                    $pakId,
                    $entryId,
                    $entryIndex,
                    $display,
                    'failed',
                    $status,
                    $fileId,
                    $message
                );
                $this->recordParentEvent($parentJobId, $result);
                $context->checkpoint($this->entryCompleteProgress($result));
                return $result;
            }
        } finally {
            if ($workingPath !== '' && is_file($workingPath)) {
                @unlink($workingPath);
            }
        }
    }

    /** @param array<string,mixed> $resume @return array<string,mixed> */
    private function finalizeCleanup(
        ClaimedJob $job,
        CatalogPakImportWorkspace $workspace,
        array $resume,
        JobExecutionContext $context
    ): array {
        $stagedPath = trim((string)($job->payload['staged_path'] ?? ''));
        if ($stagedPath !== '') {
            try {
                (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
            } catch (Throwable $error) {
                // Cleanup is idempotent across the pak_finished checkpoint. A
                // missing incoming source means a previous finalization attempt
                // already removed it; any other error remains visible in server
                // logs but must not force expensive archive work to replay.
                if (!str_contains(strtolower($error->getMessage()), 'unavailable')) {
                    error_log('[UnrealDB PAK cleanup] Job #' . $job->id . ': ' . $error->getMessage());
                }
            }
        }
        $workspace->clear();

        $complete = $resume;
        $complete['stage'] = 'complete';
        $complete['done'] = 100;
        $complete['total'] = 100;
        $complete['percent'] = 100;
        $complete['status'] = 'completed';
        $complete['message'] = 'Original UE' . (int)($resume['engine_major'] ?? 0)
            . ' PAK retained; durable archive entry workflow completed.';
        $context->checkpoint($complete);

        return [
            'operation' => 'import_staged_pak',
            'workflow_version' => self::WORKFLOW_VERSION,
            'status' => 'completed',
            'pak_id' => (int)($resume['pak_id'] ?? 0),
            'game_id' => (int)($resume['game_id'] ?? 0),
            'game_name' => (string)($resume['game_name'] ?? ''),
            'engine_major' => (int)($resume['engine_major'] ?? 0),
            'source_name' => (string)($resume['source_name'] ?? ''),
            'entry_count' => (int)($resume['entry_count'] ?? 0),
            'extracted_files' => (int)($resume['extracted_files'] ?? 0),
            'imported' => (int)($resume['imported'] ?? 0),
            'duplicates' => (int)($resume['duplicates'] ?? 0),
            'aliases' => (int)($resume['aliases'] ?? 0),
            'failed' => (int)($resume['failed'] ?? 0),
            'skipped' => (int)($resume['skipped'] ?? 0),
            'not_extracted' => (int)($resume['not_extracted'] ?? 0),
            'messages' => is_array($resume['messages'] ?? null) ? $resume['messages'] : [],
            'messages_truncated' => !empty($resume['messages_truncated']),
            'extract_log' => (string)($resume['extract_log'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $state */
    private function validateWorkspaceState(array $state, int $gameId, ?int $pakId = null): void
    {
        if ((int)($state['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION
            || (int)($state['game_id'] ?? 0) !== $gameId
            || (int)($state['pak_id'] ?? 0) < 1
            || ($pakId !== null && (int)$state['pak_id'] !== $pakId)
            || !is_array($state['index'] ?? null)) {
            throw new \RuntimeException('Durable PAK import workspace metadata is invalid.');
        }
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId, string $prefix): array
    {
        $state = ['total' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0, 'cancelled' => 0];
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=? AND workflow_unit_key LIKE ? GROUP BY status'
        );
        $statement->execute([$parentJobId, $prefix . '%']);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            $count = (int)$row['c'];
            $state['total'] += $count;
            if (array_key_exists($status, $state)) {
                $state[$status] += $count;
            }
        }
        return $state;
    }

    /** @return array<string,mixed>|null */
    private function workflowChild(int $parentJobId, string $unitKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,status,progress_json,result_json,last_error FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key=? LIMIT 1'
        );
        $statement->execute([$parentJobId, $unitKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{imported:int,duplicates:int,aliases:int,failed:int,skipped:int,not_extracted:int,messages:list<array<string,mixed>>,messages_truncated:bool} */
    private function aggregateEntryResults(int $parentJobId): array
    {
        $aggregate = [
            'imported' => 0,
            'duplicates' => 0,
            'aliases' => 0,
            'failed' => 0,
            'skipped' => 0,
            'not_extracted' => 0,
            'messages' => [],
            'messages_truncated' => false,
        ];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "pak-entry:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        $seen = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            $outcome = (string)($result['outcome'] ?? 'failed');
            if (array_key_exists($outcome === 'duplicate' ? 'duplicates' : ($outcome === 'alias' ? 'aliases' : $outcome), $aggregate)) {
                $key = $outcome === 'duplicate' ? 'duplicates' : ($outcome === 'alias' ? 'aliases' : $outcome);
                if (is_int($aggregate[$key])) {
                    $aggregate[$key]++;
                }
            }
            $seen++;
            if (count($aggregate['messages']) < self::MAX_RESULT_MESSAGES) {
                $aggregate['messages'][] = [
                    'status' => (string)($result['entry_status'] ?? $outcome),
                    'file' => (string)($result['file'] ?? ''),
                    'message' => (string)($result['message'] ?? ''),
                    'file_id' => (int)($result['file_id'] ?? 0),
                    'pak_entry_id' => (int)($result['pak_entry_id'] ?? 0),
                ];
            }
        }
        $aggregate['messages_truncated'] = $seen > count($aggregate['messages']);
        return $aggregate;
    }

    /** @return array<string,mixed> */
    private function entryResult(
        int $parentJobId,
        int $pakId,
        int $entryId,
        int $entryIndex,
        string $display,
        string $outcome,
        string $entryStatus,
        int $fileId,
        string $message
    ): array {
        return [
            'operation' => 'import_staged_pak_entry',
            'workflow_parent_job_id' => $parentJobId,
            'pak_id' => $pakId,
            'pak_entry_id' => $entryId,
            'entry_index' => $entryIndex,
            'file' => $display,
            'outcome' => $outcome,
            'entry_status' => $entryStatus,
            'file_id' => $fileId,
            'message' => $message,
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function entryCompleteProgress(array $result): array
    {
        return [
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'pak_id' => (int)($result['pak_id'] ?? 0),
            'pak_entry_id' => (int)($result['pak_entry_id'] ?? 0),
            'entry_index' => (int)($result['entry_index'] ?? 0),
            'status' => (string)($result['entry_status'] ?? 'completed'),
            'message' => (string)($result['message'] ?? 'PAK entry completed.'),
        ];
    }

    /** @param array<string,mixed> $result */
    private function recordParentEvent(int $parentJobId, array $result): void
    {
        try {
            (new CatalogJobEventLog($this->config))->append($parentJobId, [
                'status' => (string)($result['entry_status'] ?? $result['outcome'] ?? 'info'),
                'file' => (string)($result['file'] ?? ''),
                'message' => (string)($result['message'] ?? ''),
                'file_id' => (int)($result['file_id'] ?? 0),
                'pak_entry_id' => (int)($result['pak_entry_id'] ?? 0),
            ]);
        } catch (Throwable $error) {
            error_log('[UnrealDB PAK events] Parent job #' . $parentJobId . ': ' . $error->getMessage());
        }
    }

    /**
     * @param list<array<string,mixed>> $footers
     * @param array<string,array<string,mixed>> $extractedByPath
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function selectIndexForExtractedFiles(
        string $sourcePath,
        array $footers,
        array $extractedByPath,
        string $extractLog
    ): array {
        $expectedVersion = null;
        $expectedLayout = '';
        $expectedMagicOffset = null;
        if (preg_match('/version=([0-9]+); layout=([^;]+); magic_offset=(-?[0-9]+)/', $extractLog, $match) === 1) {
            $expectedVersion = (int)$match[1];
            $expectedLayout = trim((string)$match[2]);
            $expectedMagicOffset = (int)$match[3];
        }
        $bestFooter = null;
        $bestIndex = null;
        $bestMatches = -1;
        $lastError = '';
        foreach ($footers as $candidate) {
            try {
                $candidateIndex = \catalog_pak_parse_index($sourcePath, $candidate);
            } catch (Throwable $error) {
                $lastError = $error->getMessage();
                continue;
            }
            $matches = 0;
            $entries = is_array($candidateIndex['entries'] ?? null) ? $candidateIndex['entries'] : [];
            foreach ($entries as $entry) {
                $path = $this->normalizeEntryPath((string)($entry['filename'] ?? ''));
                if ($path !== '' && isset($extractedByPath[strtolower($path)])) {
                    $matches++;
                }
            }
            $metadataMatches = $expectedVersion !== null
                && (int)($candidate['version'] ?? -1) === $expectedVersion
                && (string)($candidate['layout'] ?? '') === $expectedLayout
                && (int)($candidate['magic_offset'] ?? -2) === $expectedMagicOffset;
            if ($metadataMatches) {
                return [$candidate, $candidateIndex];
            }
            if ($matches > $bestMatches) {
                $bestMatches = $matches;
                $bestFooter = $candidate;
                $bestIndex = $candidateIndex;
            }
        }
        if (is_array($bestFooter) && is_array($bestIndex) && $bestMatches > 0) {
            return [$bestFooter, $bestIndex];
        }
        throw new \RuntimeException(
            'Could not match the successfully extracted PAK files to a parsed index.'
            . ($lastError !== '' ? ' Last index error: ' . $lastError : '')
        );
    }

    /** @param array<string,mixed> $payload */
    private function verifyIdentity(string $path, array $payload): void
    {
        $expected = strtolower(trim((string)($payload['sha256'] ?? '')));
        if ($expected === '') {
            return;
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new \RuntimeException('Staged import file identity changed before execution.');
        }
    }

    private function normalizeEntryPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function engineMajor(string $engineKey): int
    {
        return preg_match('/UE\s*([0-9]+)/i', $engineKey, $match) === 1 ? (int)$match[1] : 0;
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('PAK import payload requires positive ' . $field . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException('PAK import payload requires ' . $field . '.');
        }
        return $value;
    }

    private function isInfrastructureFailure(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        foreach ([
            'pak archive tables are missing',
            'staged import file is unavailable',
            'staged import file identity changed',
            'target game no longer exists',
            'could not copy the original pak',
            'original pak copy verification failed',
            'could not publish the original pak',
            'durable pak import workspace',
            'pak import workspace metadata',
            'disposable pak entry working copy',
            'sqlstate[',
            'job lease no longer belongs',
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
        return trim($message) !== '' ? trim($message) : 'PAK import failed.';
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(string $stage, int $percent, string $message, array $extra = []): array
    {
        return [
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ] + $extra;
    }
}
