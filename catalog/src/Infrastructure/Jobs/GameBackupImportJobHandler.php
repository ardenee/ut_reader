<?php
/**
 * Durable game-backup restore workflow.
 *
 * Canonical files are imported before aliases. Every manifest entry is its own
 * durable child unit, so successful files are not replayed after interruption.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Backup\GameBackupStore;
use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;

final class GameBackupImportJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 3;
    private const MANIFEST_VERSION = 1;
    private const PLAN_BATCH_SIZE = 500;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::IMPORT_GAME_BACKUP, JobType::IMPORT_GAME_BACKUP_ENTRY], true);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return $job->type === JobType::IMPORT_GAME_BACKUP_ENTRY
            ? $this->importEntry($job, $context)
            : $this->coordinate($job, $context);
    }

    /** @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $userId = (int)($job->payload['user_id'] ?? 0);
        $userId = $userId > 0 ? $userId : null;
        $strict = !array_key_exists('strict_profile', $job->payload) || !empty($job->payload['strict_profile']);
        $store = new GameBackupStore((string)$this->config['storage_path']);
        $manifest = $this->manifest($store, $backupKey);
        $entries = $this->entries($manifest);

        $game = \catalog_one($this->db, 'SELECT id,name FROM ue_games WHERE id=? LIMIT 1', [$gameId]);
        if (!$game) {
            throw new \RuntimeException('Game backup target no longer exists: ' . $gameId);
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'backup_import_plan_canonical';
            $resume = [];
        }
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'backup_import_plan_canonical';
        }

        if ($stage === 'backup_import_plan_canonical') {
            $this->planEntries($job, $context, $entries, false, $gameId, $backupKey, $userId, $strict, $resume);
            $stage = 'backup_import_wait_canonical';
        }

        if ($stage === 'backup_import_wait_canonical') {
            $state = $this->childState($job->id, 'canonical:');
            $this->waitForChildren($context, $state, 'backup_import_wait_canonical', 5, 45, 'canonical backup');
            $context->checkpoint($this->progress(
                'backup_import_plan_aliases',
                46,
                'All canonical backup entries completed; planning alias entries.',
                ['canonical_children' => $state]
            ));
            $resume = $context->resumeProgress();
            $stage = 'backup_import_plan_aliases';
        }

        if ($stage === 'backup_import_plan_aliases') {
            $this->planEntries($job, $context, $entries, true, $gameId, $backupKey, $userId, $strict, $resume);
            $stage = 'backup_import_wait_aliases';
        }

        if ($stage === 'backup_import_wait_aliases') {
            $state = $this->childState($job->id, 'alias:');
            $this->waitForChildren($context, $state, 'backup_import_wait_aliases', 48, 95, 'alias backup');
            $context->checkpoint($this->progress(
                'backup_import_dependency_plan',
                96,
                'All backup entries completed; queueing the authoritative game dependency rebuild.',
                ['alias_children' => $state]
            ));
            $stage = 'backup_import_dependency_plan';
        }

        if ($stage === 'backup_import_dependency_plan') {
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
                'backup_import_dependency_wait',
                97,
                'Dependency workflow #' . $dependencyJobId . ' queued after backup restore.',
                ['dependency_job_id' => $dependencyJobId]
            ));
            $stage = 'backup_import_dependency_wait';
        }

        if ($stage === 'backup_import_dependency_wait') {
            $dependency = $this->workflowChild($job->id, 'dependencies');
            if ($dependency === null) {
                $context->checkpoint($this->progress(
                    'backup_import_dependency_plan',
                    96,
                    'Dependency workflow child was not found; replanning it.'
                ));
                $context->defer(1);
            }

            $dependencyStatus = strtolower(trim((string)($dependency['status'] ?? 'queued')));
            if (in_array($dependencyStatus, ['failed', 'dead_letter', 'cancelled'], true)) {
                $context->defer(30, $this->progress(
                    'backup_import_dependency_wait',
                    98,
                    'Backup dependency workflow #' . (int)$dependency['id']
                        . ' requires attention. Restart that dependency child; imported backup entries are retained.',
                    [
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
                    'backup_import_dependency_wait',
                    97 + (int)floor(($innerPercent * 2) / 100),
                    'Backup dependency workflow #' . (int)$dependency['id'] . ' is ' . $dependencyStatus . '.',
                    [
                        'dependency_job_id' => (int)$dependency['id'],
                        'dependency_status' => $dependencyStatus,
                    ]
                ));
            }

            $context->checkpoint($this->progress(
                'backup_import_finalize',
                99,
                'Backup dependency rebuild completed; finalizing restore summary.',
                ['dependency_job_id' => (int)$dependency['id']]
            ));
            $stage = 'backup_import_finalize';
        }

        if ($stage !== 'backup_import_finalize') {
            throw new \RuntimeException('Unknown game backup import workflow stage: ' . $stage);
        }

        $aggregate = $this->aggregateEntryResults($job->id);
        $canonical = $this->childState($job->id, 'canonical:');
        $aliases = $this->childState($job->id, 'alias:');
        $dependency = $this->workflowChild($job->id, 'dependencies');
        $message = 'Game backup restore complete for ' . (string)$game['name'] . ': '
            . $aggregate['imported'] . ' imported, ' . $aggregate['duplicates'] . ' duplicate, '
            . $aggregate['aliases'] . ' alias result(s); game dependencies rebuilt.';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'canonical_children' => $canonical,
            'alias_children' => $aliases,
        ]));
        return [
            'operation' => 'import_game_backup',
            'workflow_version' => self::WORKFLOW_VERSION,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'backup_key' => $backupKey,
            'manifest_entries' => count($entries),
            'completed_entries' => $aggregate['completed'],
            'imported' => $aggregate['imported'],
            'duplicates' => $aggregate['duplicates'],
            'aliases' => $aggregate['aliases'],
            'canonical_children' => $canonical,
            'alias_children' => $aliases,
            'dependency_job_id' => (int)($dependency['id'] ?? 0),
            'dependency_status' => (string)($dependency['status'] ?? ''),
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function importEntry(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $position = max(0, (int)($job->payload['entry_position'] ?? -1));
        $userId = (int)($job->payload['user_id'] ?? 0);
        $userId = $userId > 0 ? $userId : null;
        $strict = !array_key_exists('strict_profile', $job->payload) || !empty($job->payload['strict_profile']);
        $store = new GameBackupStore((string)$this->config['storage_path']);
        $manifest = $this->manifest($store, $backupKey);
        $entries = $this->entries($manifest);
        $entry = $entries[$position] ?? null;
        if (!is_array($entry)) {
            throw new \RuntimeException('Game backup entry position is no longer present: ' . $position);
        }

        $exportedRelative = trim(str_replace('\\', '/', (string)($entry['exported_relative_path'] ?? '')));
        if ($exportedRelative === '') {
            throw new \RuntimeException('Game backup entry has no exported file path.');
        }
        $source = $store->entryPath($backupKey, $exportedRelative);
        if (!is_file($source)) {
            throw new \RuntimeException('Game backup file is missing: ' . $exportedRelative);
        }
        $expectedSize = max(-1, (int)($entry['file_size'] ?? -1));
        $expectedMd5 = strtolower(trim((string)($entry['md5'] ?? '')));
        $this->verifySource($source, $expectedSize, $expectedMd5);

        $originalName = \catalog_clean_unreal_filename((string)($entry['original_name'] ?? basename($source)));
        $sourceRelative = \scanner_normalize_source_relative_path(
            (string)($entry['source_relative_path'] ?? '')
        );
        $isAlias = !empty($entry['is_alias']);
        $context->checkpoint([
            'stage' => 'backup_entry_import',
            'done' => 0,
            'total' => 1,
            'percent' => 1,
            'entry_position' => $position,
            'current_file' => $originalName,
            'message' => 'Restoring backup entry ' . ($position + 1) . '/' . count($entries) . ': ' . $originalName . '.',
        ]);

        $temporary = tempnam(sys_get_temp_dir(), 'uedb-backup-');
        if ($temporary === false || !@copy($source, $temporary)) {
            if (is_string($temporary) && $temporary !== '') {
                @unlink($temporary);
            }
            throw new \RuntimeException('Could not prepare game backup entry for import.');
        }

        try {
            $result = (new PdoCatalogPackageImporter($this->db, $this->config))->importUploadedFile(
                $gameId,
                $temporary,
                $originalName,
                $userId,
                $strict,
                static function (array $progress) use ($context, $position, $entries, $originalName): void {
                    $progress['entry_position'] = $position;
                    $progress['current_file'] = $originalName;
                    $progress['message'] = (string)($progress['message'] ?? 'Restoring backup entry')
                        . ' [' . ($position + 1) . '/' . count($entries) . ']';
                    $context->heartbeatIfDue($progress);
                },
                !$strict,
                [
                    'source_relative_path' => $sourceRelative,
                    'defer_dependency_rebuild' => true,
                ]
            );
            $result = VerifiedFileCompactMetadataFinalizer::finalize(
                $this->db,
                $this->config,
                $result
            );
            $status = (string)($result[0] ?? 'verified');
            $fileId = (int)($result[1] ?? 0);
            $message = (string)($result[2] ?? 'Restored backup entry.');
            if ($isAlias && $status === 'duplicate') {
                $status = 'alias';
            }
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'entry_position' => $position,
                'current_file' => $originalName,
                'status' => $status,
                'message' => $message,
            ]);
            $resultRow = [
                'operation' => 'import_game_backup_entry',
                'entry_position' => $position,
                'file_id' => $fileId,
                'original_name' => $originalName,
                'is_alias' => $isAlias,
                'status' => $status,
                'message' => $message,
            ];
            return $resultRow;
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed> $resume
     */
    private function planEntries(
        ClaimedJob $job,
        JobExecutionContext $context,
        array $entries,
        bool $aliases,
        int $gameId,
        string $backupKey,
        ?int $userId,
        bool $strict,
        array $resume
    ): void {
        $stage = $aliases ? 'backup_import_plan_aliases' : 'backup_import_plan_canonical';
        $prefix = $aliases ? 'alias:' : 'canonical:';
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        $next = max(0, (int)($resume['plan_next_position'] ?? 0));
        if ((string)($resume['stage'] ?? '') !== $stage) {
            $planned = 0;
            $next = 0;
        }

        $queue = new PdoJobQueue($this->db);
        $added = 0;
        for ($position = $next, $count = count($entries); $position < $count; $position++) {
            $entry = $entries[$position];
            if ((bool)!empty($entry['is_alias']) !== $aliases) {
                $next = $position + 1;
                continue;
            }
            $queue->enqueue(
                $job->queue,
                JobType::IMPORT_GAME_BACKUP_ENTRY,
                [
                    'game_id' => $gameId,
                    'backup_key' => $backupKey,
                    'entry_position' => $position,
                    'user_id' => $userId,
                    'strict_profile' => $strict,
                    'workflow_parent_job_id' => $job->id,
                ],
                50,
                null,
                null,
                $userId,
                3,
                $job->id,
                $prefix . $position
            );
            $planned++;
            $added++;
            $next = $position + 1;
            if ($added >= self::PLAN_BATCH_SIZE) {
                break;
            }
        }

        $hasMore = false;
        for ($position = $next, $count = count($entries); $position < $count; $position++) {
            if ((bool)!empty($entries[$position]['is_alias']) === $aliases) {
                $hasMore = true;
                break;
            }
        }
        $progress = $this->progress(
            $stage,
            $aliases ? 47 : 3,
            'Planned ' . $planned . ' durable ' . ($aliases ? 'alias' : 'canonical') . ' backup entry unit(s).',
            ['plan_next_position' => $next, 'planned_units' => $planned]
        );
        if ($hasMore) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->progress(
            $aliases ? 'backup_import_wait_aliases' : 'backup_import_wait_canonical',
            $aliases ? 48 : 5,
            'Planned ' . $planned . ' durable ' . ($aliases ? 'alias' : 'canonical') . ' entry unit(s); waiting for workers.',
            ['planned_units' => $planned]
        ));
    }

    /** @param array<string,int> $state */
    private function waitForChildren(
        JobExecutionContext $context,
        array $state,
        string $stage,
        int $startPercent,
        int $endPercent,
        string $label
    ): void {
        $total = max(1, $state['total']);
        $percent = $startPercent + (int)floor((($endPercent - $startPercent) * $state['completed']) / $total);
        $problem = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
        if ($problem > 0) {
            $context->defer(30, $this->progress(
                $stage,
                min($endPercent, $percent),
                ucfirst($label) . ' restore is waiting on ' . $problem
                    . ' failed/cancelled entry unit(s). Restart only those entries; '
                    . $state['completed'] . ' successful entries are retained.',
                ['children' => $state]
            ));
        }
        if (($state['queued'] + $state['running']) > 0) {
            $context->defer(2, $this->progress(
                $stage,
                min($endPercent, $percent),
                ucfirst($label) . ' entries: ' . $state['completed'] . '/' . $state['total']
                    . ' complete, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                ['children' => $state]
            ));
        }
    }

    /** @return array<string,mixed> */
    private function manifest(GameBackupStore $store, string $backupKey): array
    {
        $manifest = $store->readManifest($backupKey);
        if ((string)($manifest['format'] ?? '') !== 'unrealdb-game-backup'
            || (int)($manifest['format_version'] ?? 0) !== self::MANIFEST_VERSION
            || (string)($manifest['status'] ?? '') !== 'complete') {
            throw new \RuntimeException('The selected backup is incomplete or uses an unsupported manifest format.');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @return list<array<string,mixed>> */
    private function entries(array $manifest): array
    {
        $entries = is_array($manifest['files'] ?? null) ? array_values($manifest['files']) : [];
        usort($entries, static function (array $a, array $b): int {
            $aliasOrder = ((int)!empty($a['is_alias'])) <=> ((int)!empty($b['is_alias']));
            if ($aliasOrder !== 0) {
                return $aliasOrder;
            }
            $fileOrder = (int)($a['file_id'] ?? 0) <=> (int)($b['file_id'] ?? 0);
            if ($fileOrder !== 0) {
                return $fileOrder;
            }
            return strcasecmp((string)($a['exported_relative_path'] ?? ''), (string)($b['exported_relative_path'] ?? ''));
        });
        if ($entries === []) {
            throw new \RuntimeException('The selected backup manifest contains no files.');
        }
        return $entries;
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId, string $prefix): array
    {
        return (new PdoWorkflowChildStateQuery($this->db))->fetch($parentJobId, $prefix);
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

    /** @return array{completed:int,imported:int,duplicates:int,aliases:int} */
    private function aggregateEntryResults(int $parentJobId): array
    {
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND (workflow_unit_key LIKE "canonical:%" OR workflow_unit_key LIKE "alias:%") AND status="completed"'
        );
        $statement->execute([$parentJobId]);
        $aggregate = ['completed' => 0, 'imported' => 0, 'duplicates' => 0, 'aliases' => 0];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            $aggregate['completed']++;
            $status = (string)($result['status'] ?? 'imported');
            if ($status === 'duplicate') {
                $aggregate['duplicates']++;
            } elseif ($status === 'alias') {
                $aggregate['aliases']++;
            } else {
                $aggregate['imported']++;
            }
        }
        return $aggregate;
    }

    private function verifySource(string $path, int $expectedSize, string $expectedMd5): void
    {
        $actualSize = filesize($path);
        if ($actualSize === false || ($expectedSize >= 0 && (int)$actualSize !== $expectedSize)) {
            throw new \RuntimeException('File size verification failed for ' . basename($path) . '.');
        }
        if ($expectedMd5 !== '') {
            $actualMd5 = md5_file($path);
            if (!is_string($actualMd5) || !hash_equals(strtolower($expectedMd5), strtolower($actualMd5))) {
                throw new \RuntimeException('MD5 verification failed for ' . basename($path) . '.');
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Game backup requires positive ' . $field . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException('Game backup requires ' . $field . '.');
        }
        return $value;
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
