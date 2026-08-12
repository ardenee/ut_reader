<?php
/**
 * Resumable game-backup restore coordinator and one-entry worker.
 *
 * Canonical entries complete before aliases, preserving the historical restore
 * ordering. Each manifest entry is a durable child job, so a failure blocks
 * only that entry and successful files are never replayed.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;

final class GameBackupImportJobHandler implements JobHandler
{
    private const MANIFEST_VERSION = 1;
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 250;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::IMPORT_GAME_BACKUP, JobType::IMPORT_GAME_BACKUP_ENTRY], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';

        return $job->type === JobType::IMPORT_GAME_BACKUP_ENTRY
            ? $this->importEntry($job, $context)
            : $this->coordinate($job, $context);
    }

    /** @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;
        $strict = !array_key_exists('strict_profile', $job->payload) || (bool)$job->payload['strict_profile'];
        $rebuildDependencies = !array_key_exists('rebuild_dependencies', $job->payload)
            || (bool)$job->payload['rebuild_dependencies'];

        $store = new GameBackupStore($this->config);
        $manifest = $this->manifest($store, $backupKey);
        $entries = $this->entries($manifest);
        $game = \catalog_one($this->db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new \RuntimeException('Target game no longer exists: ' . $gameId);
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start' || (int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'backup_import_plan_canonical';
            $resume = [];
        }

        if ($stage === 'backup_import_plan_canonical') {
            $this->planEntries($job, $context, $entries, false, $gameId, $backupKey, $userId, $strict, $resume);
            $stage = 'backup_import_wait_canonical';
        }
        if ($stage === 'backup_import_wait_canonical') {
            $state = $this->childState($job->id, 'canonical:');
            $this->waitForChildren($context, $state, 'backup_import_wait_canonical', 5, 44, 'canonical backup');
            $context->checkpoint($this->progress(
                'backup_import_plan_aliases',
                45,
                'All canonical backup entries completed; planning aliases.'
            ));
            $resume = [];
            $stage = 'backup_import_plan_aliases';
        }
        if ($stage === 'backup_import_plan_aliases') {
            $this->planEntries($job, $context, $entries, true, $gameId, $backupKey, $userId, $strict, $resume);
            $stage = 'backup_import_wait_aliases';
        }
        if ($stage === 'backup_import_wait_aliases') {
            $state = $this->childState($job->id, 'alias:');
            $this->waitForChildren($context, $state, 'backup_import_wait_aliases', 45, 84, 'alias backup');
            $nextStage = $rebuildDependencies ? 'backup_import_dependency_plan' : 'backup_import_report';
            $context->checkpoint($this->progress(
                $nextStage,
                $rebuildDependencies ? 85 : 99,
                $rebuildDependencies
                    ? 'Every backup entry is restored; queueing the game dependency workflow.'
                    : 'Every backup entry is restored; dependency rebuild was disabled.'
            ));
            $stage = $nextStage;
        }

        if ($stage === 'backup_import_dependency_plan') {
            $dependencyJobId = (new PdoJobQueue($this->db))->enqueue(
                $job->queue,
                JobType::REBUILD_GAME_DEPENDENCIES,
                [
                    'game_id' => $gameId,
                    'offset' => 0,
                    'workflow_parent_job_id' => $job->id,
                    'requested_by' => $userId,
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
                86,
                'Dependency workflow #' . $dependencyJobId . ' queued.',
                ['dependency_job_id' => $dependencyJobId]
            ));
            $stage = 'backup_import_dependency_wait';
        }
        if ($stage === 'backup_import_dependency_wait') {
            $dependency = $this->workflowChild($job->id, 'dependencies');
            if ($dependency === null) {
                $context->checkpoint($this->progress(
                    'backup_import_dependency_plan',
                    85,
                    'Dependency workflow child was not found; replanning it.'
                ));
                $context->defer(1);
            }
            $status = (string)($dependency['status'] ?? 'queued');
            if (in_array($status, ['failed', 'dead_letter', 'cancelled'], true)) {
                $context->defer(30, $this->progress(
                    'backup_import_dependency_wait',
                    90,
                    'Dependency child job #' . (int)$dependency['id'] . ' requires attention. Restart only that child; restored backup entries are retained.',
                    ['dependency_job_id' => (int)$dependency['id'], 'dependency_status' => $status]
                ));
            }
            if ($status !== 'completed') {
                $inner = json_decode((string)($dependency['progress_json'] ?? ''), true);
                $innerPercent = is_array($inner) ? max(0, min(100, (int)($inner['percent'] ?? 0))) : 0;
                $context->defer(2, $this->progress(
                    'backup_import_dependency_wait',
                    86 + (int)floor(($innerPercent * 12) / 100),
                    'Dependency workflow #' . (int)$dependency['id'] . ' is ' . $status . '.',
                    ['dependency_job_id' => (int)$dependency['id'], 'dependency_status' => $status]
                ));
            }
            $context->checkpoint($this->progress('backup_import_report', 99, 'Dependency workflow completed; writing restore report.'));
            $stage = 'backup_import_report';
        }

        if ($stage !== 'backup_import_report') {
            throw new \RuntimeException('Unknown game-backup import workflow stage: ' . $stage);
        }

        $aggregate = $this->aggregateEntryResults($job->id);
        if ($aggregate['completed'] !== count($entries)) {
            throw new \RuntimeException(
                'Backup import workflow accounting is incomplete: '
                . $aggregate['completed'] . '/' . count($entries) . ' entry results are durable.'
            );
        }

        $reportFilename = 'import-job-' . $job->id . '.json';
        $report = [
            'operation' => 'import_game_backup',
            'workflow_version' => self::WORKFLOW_VERSION,
            'job_id' => $job->id,
            'backup_key' => $backupKey,
            'target_game_id' => $gameId,
            'target_game_name' => (string)$game['name'],
            'completed_at' => gmdate('c'),
            'entries' => count($entries),
            'validated' => count($entries),
            'validation_complete' => true,
            'imported' => $aggregate['imported'],
            'duplicates' => $aggregate['duplicates'],
            'aliases' => $aggregate['aliases'],
            'failed' => 0,
            'dependency_rebuild' => $rebuildDependencies,
            'errors' => [],
            'errors_complete' => true,
            'errors_truncated' => false,
            'report_filename' => $reportFilename,
            'message' => 'Game backup import completed: ' . count($entries)
                . ' durable entry unit(s); imported=' . $aggregate['imported']
                . ', duplicates=' . $aggregate['duplicates']
                . ', aliases=' . $aggregate['aliases'] . '.',
        ];

        $reportPath = $store->backupPath($backupKey) . DIRECTORY_SEPARATOR . $reportFilename;
        $json = json_encode(
            $report,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($reportPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the complete game-backup import report.');
        }
        @chmod($reportPath, 0640);

        $context->checkpoint($this->progress('complete', 100, (string)$report['message'], $report));
        return $report;
    }

    /** @return array<string,mixed> */
    private function importEntry(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $position = (int)($job->payload['entry_position'] ?? -1);
        if ($position < 0) {
            throw new \InvalidArgumentException('Game backup entry job requires entry_position.');
        }
        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;
        $strict = !array_key_exists('strict_profile', $job->payload) || (bool)$job->payload['strict_profile'];

        $store = new GameBackupStore($this->config);
        $entries = $this->entries($this->manifest($store, $backupKey));
        if (!isset($entries[$position])) {
            throw new \RuntimeException('Backup manifest entry position is no longer available: ' . $position);
        }
        $entry = $entries[$position];
        $originalName = (string)($entry['original_name'] ?? 'package.bin');
        $relative = (string)($entry['exported_relative_path'] ?? '');
        $sourceRelative = trim(str_replace('\\', '/', (string)($entry['source_relative_path'] ?? '')), '/');
        if ($sourceRelative === '') {
            $sourceRelative = $relative;
        } else {
            $slash = strrpos($sourceRelative, '/');
            $sourceRelative = ($slash === false ? '' : substr($sourceRelative, 0, $slash + 1)) . $originalName;
        }

        $context->checkpoint([
            'stage' => 'backup_entry_import',
            'done' => 0,
            'total' => 1,
            'percent' => 2,
            'message' => 'Restoring backup entry ' . ($position + 1) . ': ' . $originalName,
            'entry_position' => $position,
            'file_name' => $originalName,
        ]);

        $tempDirectory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'game-backup-import';
        if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0750, true) && !is_dir($tempDirectory)) {
            throw new \RuntimeException('Could not create game-backup import workspace.');
        }
        $temporary = '';
        try {
            $source = $store->resolveBackupFile($backupKey, $relative);
            $this->verifySource($source, (int)($entry['file_size'] ?? 0), (string)($entry['md5'] ?? ''));
            $temporary = tempnam($tempDirectory, 'restore-' . $job->id . '-');
            if ($temporary === false || !copy($source, $temporary)) {
                throw new \RuntimeException('Could not create an import working copy.');
            }

            $result = \scanner_scan_uploaded_file(
                $this->db,
                $this->config,
                $gameId,
                $temporary,
                $originalName,
                $userId,
                $strict,
                static function (array $progress) use ($context, $position, $originalName): void {
                    $progress['entry_position'] = $position;
                    $progress['file_name'] = $originalName;
                    $context->heartbeatIfDue($progress);
                },
                false,
                [
                    'source_relative_path' => $sourceRelative,
                    'defer_dependency_rebuild' => true,
                ]
            );
            $status = (string)($result[0] ?? 'imported');
            if (!in_array($status, ['duplicate', 'alias'], true)) {
                $status = 'imported';
            }

            $resultRow = [
                'operation' => 'import_game_backup_entry',
                'game_id' => $gameId,
                'backup_key' => $backupKey,
                'entry_position' => $position,
                'file' => $originalName,
                'exported_relative_path' => $relative,
                'source_relative_path' => $sourceRelative,
                'entry_is_alias' => !empty($entry['is_alias']),
                'status' => $status,
                'message' => 'Backup entry ' . ($position + 1) . ' completed as ' . $status . ': ' . $originalName,
            ];
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'entry_position' => $position,
                'file_name' => $originalName,
                'message' => (string)$resultRow['message'],
            ]);
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
