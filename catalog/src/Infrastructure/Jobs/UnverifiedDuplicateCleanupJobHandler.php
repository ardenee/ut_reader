<?php
/**
 * Durable exact-duplicate cleanup workflow for physical unverified queues.
 *
 * Hashing and deletion are both per-file durable units. The parent only performs
 * inexpensive inventory/group planning and waits. Therefore a crash or bad file
 * never forces already-hashed candidates or already-deleted duplicates to replay.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Filesystem\NativeUnverifiedFileSystem;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUnverifiedRecordStore;
use UnrealDb\Catalog\Infrastructure\Unverified\LegacyUnverifiedQueueInventory;

final class UnverifiedDuplicateCleanupJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 500;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [
            JobType::CLEAN_UNVERIFIED_DUPLICATES,
            JobType::HASH_UNVERIFIED_DUPLICATE,
            JobType::DELETE_UNVERIFIED_DUPLICATE,
        ], true);
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return match ($job->type) {
            JobType::HASH_UNVERIFIED_DUPLICATE => $this->hashOne($job, $context),
            JobType::DELETE_UNVERIFIED_DUPLICATE => $this->deleteOne($job, $context),
            JobType::CLEAN_UNVERIFIED_DUPLICATES => $this->coordinate($job, $context),
            default => throw new \RuntimeException('Unsupported duplicate cleanup job: ' . $job->type),
        };
    }

    /** @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context): array
    {
        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'duplicate_hash_plan';
            $resume = [];
        }
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'duplicate_hash_plan';
        }

        if ($stage === 'duplicate_hash_plan') {
            $this->planHashes($job, $context, $resume);
            $stage = 'duplicate_hash_wait';
        }

        if ($stage === 'duplicate_hash_wait') {
            $state = $this->childState($job->id, 'hash:');
            if (!$this->childrenReady($context, $state, 'duplicate_hash_wait', 5, 65, 'duplicate hash')) {
                throw new \LogicException('Unreachable after duplicate hash defer.');
            }
            $context->checkpoint($this->progress(
                'duplicate_delete_plan',
                68,
                'All same-size candidates are durably hashed; planning exact duplicate deletions.',
                ['hash_children' => $state, 'delete_plan_offset' => 0]
            ));
            $resume = $context->resumeProgress();
            $stage = 'duplicate_delete_plan';
        }

        if ($stage === 'duplicate_delete_plan') {
            $this->planDeletes($job, $context, $resume);
            $stage = 'duplicate_delete_wait';
        }

        if ($stage === 'duplicate_delete_wait') {
            $state = $this->childState($job->id, 'delete:');
            if (!$this->childrenReady($context, $state, 'duplicate_delete_wait', 72, 98, 'duplicate delete')) {
                throw new \LogicException('Unreachable after duplicate delete defer.');
            }
            $context->checkpoint($this->progress(
                'duplicate_finalize',
                99,
                'All duplicate deletion units completed; finalizing cleanup summary.',
                ['delete_children' => $state]
            ));
            $stage = 'duplicate_finalize';
        }

        if ($stage !== 'duplicate_finalize') {
            throw new \RuntimeException('Unknown unverified duplicate workflow stage: ' . $stage);
        }

        $hashSummary = $this->hashSummary($job->id);
        $deleteSummary = $this->deleteSummary($job->id);
        $message = 'Duplicate cleanup complete: ' . $hashSummary['duplicate_groups'] . ' exact group(s), '
            . $deleteSummary['deleted_files'] . ' duplicate file(s) deleted, '
            . $deleteSummary['already_missing'] . ' already absent.';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'physical_files' => $hashSummary['physical_files'],
            'hashed_files' => $hashSummary['hashed_files'],
            'duplicate_groups' => $hashSummary['duplicate_groups'],
            'deleted_files' => $deleteSummary['deleted_files'],
            'deleted_bytes' => $deleteSummary['deleted_bytes'],
        ]));

        return [
            'operation' => 'clean_unverified_duplicates',
            'workflow_version' => self::WORKFLOW_VERSION,
            'physical_files' => $hashSummary['physical_files'],
            'hashed_files' => $hashSummary['hashed_files'],
            'duplicate_groups' => $hashSummary['duplicate_groups'],
            'duplicate_files_found' => $hashSummary['duplicate_files'],
            'deleted_files' => $deleteSummary['deleted_files'],
            'already_missing_files' => $deleteSummary['already_missing'],
            'deleted_bytes' => $deleteSummary['deleted_bytes'],
            'deleted_bytes_text' => \catalog_bytes($deleteSummary['deleted_bytes']),
            'deleted' => $deleteSummary['deleted'],
            'deleted_list_truncated' => $deleteSummary['deleted_files'] > count($deleteSummary['deleted']),
            'error_count' => 0,
            'errors' => [],
            'errors_truncated' => false,
            'hash_children' => $this->childState($job->id, 'hash:'),
            'delete_children' => $this->childState($job->id, 'delete:'),
        ];
    }

    /** @param array<string,mixed> $resume */
    private function planHashes(ClaimedJob $job, JobExecutionContext $context, array $resume): void
    {
        $inventory = new LegacyUnverifiedQueueInventory($this->db, $this->config);
        $records = new PdoUnverifiedRecordStore($this->db);
        $indexed = $records->indexedQueueKeys();
        $all = $inventory->all();
        $bySize = [];
        foreach ($all as $item) {
            $bySize[(string)(int)$item['size']][] = $item;
        }

        $candidates = [];
        foreach ($bySize as $sameSize) {
            if (count($sameSize) < 2) {
                continue;
            }
            foreach ($sameSize as $item) {
                $key = (string)$item['queue_key'];
                $item['indexed'] = isset($indexed[$key]);
                $candidates[$key] = $item;
            }
        }
        ksort($candidates, SORT_STRING);

        $lastKey = (string)($resume['hash_plan_last_key'] ?? '');
        $planned = max(0, (int)($resume['planned_hash_units'] ?? 0));
        if ((string)($resume['stage'] ?? '') !== 'duplicate_hash_plan') {
            $lastKey = '';
            $planned = 0;
        }

        $queue = new PdoJobQueue($this->db);
        $page = 0;
        foreach ($candidates as $key => $item) {
            if ($lastKey !== '' && strcmp($key, $lastKey) <= 0) {
                continue;
            }
            $queue->enqueue(
                $job->queue,
                JobType::HASH_UNVERIFIED_DUPLICATE,
                [
                    'queue_game_id' => (int)$item['queue_game_id'],
                    'queue_name' => (string)$item['queue_name'],
                    'queue_key' => $key,
                    'indexed' => !empty($item['indexed']),
                    'queue_name_label' => (string)$item['queue_name_label'],
                    'original_name' => (string)$item['original_name'],
                    'workflow_parent_job_id' => $job->id,
                ],
                30,
                null,
                null,
                null,
                3,
                $job->id,
                'hash:' . $key
            );
            $lastKey = $key;
            $planned++;
            $page++;
            if ($page >= self::PLAN_BATCH_SIZE) {
                break;
            }
        }

        $hasMore = false;
        foreach (array_keys($candidates) as $key) {
            if ($lastKey === '' || strcmp($key, $lastKey) > 0) {
                $hasMore = true;
                break;
            }
        }
        $progress = $this->progress(
            'duplicate_hash_plan',
            3,
            'Planned ' . $planned . '/' . count($candidates) . ' same-size hash unit(s).',
            [
                'physical_files' => count($all),
                'hash_candidates' => count($candidates),
                'hash_plan_last_key' => $lastKey,
                'planned_hash_units' => $planned,
            ]
        );
        if ($hasMore) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->progress(
            'duplicate_hash_wait',
            5,
            count($candidates) > 0
                ? 'All ' . $planned . ' same-size hash units are planned; waiting for workers.'
                : 'No same-size duplicate candidates were found.',
            [
                'physical_files' => count($all),
                'hash_candidates' => count($candidates),
                'planned_hash_units' => $planned,
            ]
        ));
    }

    /** @param array<string,mixed> $resume */
    private function planDeletes(ClaimedJob $job, JobExecutionContext $context, array $resume): void
    {
        $duplicates = $this->duplicatePlan($job->id);
        $offset = max(0, min(count($duplicates), (int)($resume['delete_plan_offset'] ?? 0)));
        if ((string)($resume['stage'] ?? '') !== 'duplicate_delete_plan') {
            $offset = 0;
        }

        $queue = new PdoJobQueue($this->db);
        $slice = array_slice($duplicates, $offset, self::PLAN_BATCH_SIZE);
        foreach ($slice as $duplicate) {
            $queueKey = (string)$duplicate['queue_key'];
            $queue->enqueue(
                $job->queue,
                JobType::DELETE_UNVERIFIED_DUPLICATE,
                [
                    'queue_game_id' => (int)$duplicate['queue_game_id'],
                    'queue_name' => (string)$duplicate['queue_name'],
                    'queue_key' => $queueKey,
                    'original_name' => (string)$duplicate['original_name'],
                    'queue_name_label' => (string)$duplicate['queue_name_label'],
                    'expected_size' => (int)$duplicate['size'],
                    'expected_md5' => (string)$duplicate['md5'],
                    'keeper_name' => (string)$duplicate['keeper_name'],
                    'keeper_queue' => (string)$duplicate['keeper_queue'],
                    'workflow_parent_job_id' => $job->id,
                ],
                20,
                null,
                null,
                null,
                3,
                $job->id,
                'delete:' . $queueKey
            );
            $offset++;
        }

        $progress = $this->progress(
            'duplicate_delete_plan',
            70,
            'Planned ' . $offset . '/' . count($duplicates) . ' exact duplicate delete unit(s).',
            ['delete_plan_offset' => $offset, 'duplicate_files' => count($duplicates)]
        );
        if ($offset < count($duplicates)) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->progress(
            'duplicate_delete_wait',
            72,
            count($duplicates) > 0
                ? 'All exact duplicate delete units are planned; waiting for workers.'
                : 'No exact duplicate files require deletion.',
            ['delete_plan_offset' => $offset, 'duplicate_files' => count($duplicates)]
        ));
    }

    /** @return array<string,mixed> */
    private function hashOne(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = (int)($job->payload['queue_game_id'] ?? -1);
        $queueName = trim((string)($job->payload['queue_name'] ?? ''));
        $queueKey = trim((string)($job->payload['queue_key'] ?? ''));
        if ($gameId < 0 || $queueName === '' || $queueKey === '') {
            throw new \InvalidArgumentException('Duplicate hash unit has an invalid queue identity.');
        }

        $inventory = new LegacyUnverifiedQueueInventory($this->db, $this->config);
        $item = $inventory->one($gameId, $queueName);
        if ($item === null) {
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'status' => 'skipped',
                'message' => 'Duplicate hash candidate disappeared before hashing: ' . $queueName . '.',
            ]);
            return [
                'operation' => 'hash_unverified_duplicate',
                'outcome' => 'missing',
                'queue_game_id' => $gameId,
                'queue_name' => $queueName,
                'queue_key' => $queueKey,
            ];
        }

        $filesystem = new NativeUnverifiedFileSystem();
        $size = (int)$item['size'];
        $context->checkpoint([
            'stage' => 'hashing',
            'done' => 0,
            'total' => max(1, $size),
            'percent' => 0,
            'message' => 'Hashing duplicate candidate: ' . (string)$item['original_name'],
        ]);
        $md5 = $filesystem->md5(
            (string)$item['path'],
            static function (int $done, int $total) use ($context, $item): void {
                $context->heartbeatIfDue([
                    'stage' => 'hashing',
                    'done' => $done,
                    'total' => max(1, $total),
                    'percent' => (int)floor(($done * 100) / max(1, $total)),
                    'message' => 'Hashing duplicate candidate: ' . (string)$item['original_name'],
                ]);
            }
        );
        if ($md5 === null || preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            throw new \RuntimeException('Could not hash unverified duplicate candidate: ' . (string)$item['original_name']);
        }

        $result = [
            'operation' => 'hash_unverified_duplicate',
            'outcome' => 'hashed',
            'queue_game_id' => $gameId,
            'queue_name' => (string)$item['queue_name'],
            'queue_key' => (string)$item['queue_key'],
            'queue_name_label' => (string)$item['queue_name_label'],
            'original_name' => (string)$item['original_name'],
            'size' => $size,
            'modified_at' => (int)$item['modified_at'],
            'md5' => strtolower($md5),
            'indexed' => !empty($job->payload['indexed']),
        ];
        $context->checkpoint([
            'stage' => 'complete',
            'done' => max(1, $size),
            'total' => max(1, $size),
            'percent' => 100,
            'status' => 'completed',
            'message' => 'Duplicate candidate hash complete: ' . (string)$item['original_name'] . '.',
        ]);
        return $result;
    }

    /** @return array<string,mixed> */
    private function deleteOne(ClaimedJob $job, JobExecutionContext $context): array
    {
        $gameId = (int)($job->payload['queue_game_id'] ?? -1);
        $queueName = trim((string)($job->payload['queue_name'] ?? ''));
        $expectedSize = (int)($job->payload['expected_size'] ?? -1);
        $expectedMd5 = strtolower(trim((string)($job->payload['expected_md5'] ?? '')));
        if ($gameId < 0 || $queueName === '' || $expectedSize < 0 || preg_match('/^[a-f0-9]{32}$/', $expectedMd5) !== 1) {
            throw new \InvalidArgumentException('Duplicate delete unit has invalid expected identity.');
        }

        $inventory = new LegacyUnverifiedQueueInventory($this->db, $this->config);
        $filesystem = new NativeUnverifiedFileSystem();
        $records = new PdoUnverifiedRecordStore($this->db);
        $paths = $inventory->paths($gameId, $queueName);
        if ($paths === null) {
            throw new \RuntimeException('Unverified duplicate queue location is no longer resolvable: ' . $queueName);
        }
        $item = $inventory->one($gameId, $queueName);
        $alreadyMissing = $item === null;

        if ($item !== null) {
            $context->checkpoint([
                'stage' => 'verify_delete',
                'done' => 0,
                'total' => max(1, $expectedSize),
                'percent' => 1,
                'message' => 'Rechecking exact duplicate identity before deleting ' . (string)$item['original_name'] . '.',
            ]);
            if ((int)$item['size'] !== $expectedSize) {
                throw new \RuntimeException('Duplicate candidate changed size before deletion: ' . (string)$item['original_name']);
            }
            $currentMd5 = $filesystem->md5(
                (string)$item['path'],
                static function (int $done, int $total) use ($context, $item): void {
                    $context->heartbeatIfDue([
                        'stage' => 'verify_delete',
                        'done' => $done,
                        'total' => max(1, $total),
                        'percent' => min(90, (int)floor(($done * 90) / max(1, $total))),
                        'message' => 'Rechecking duplicate before deletion: ' . (string)$item['original_name'],
                    ]);
                }
            );
            if ($currentMd5 === null || !hash_equals($expectedMd5, strtolower($currentMd5))) {
                throw new \RuntimeException('Duplicate candidate changed MD5 before deletion: ' . (string)$item['original_name']);
            }
            if (!$filesystem->delete((string)$item['path']) && is_file((string)$item['path'])) {
                throw new \RuntimeException('Could not delete exact duplicate queue file: ' . (string)$item['original_name']);
            }
        }

        // These steps are deliberately idempotent. If a worker died immediately
        // after unlinking the data file, Restart lands here and completes the
        // note/database cleanup without needing the deleted bytes again.
        $reasonPath = (string)$paths['reason_path'];
        if (is_file($reasonPath) && !$filesystem->delete($reasonPath) && is_file($reasonPath)) {
            throw new \RuntimeException('Duplicate data file is gone, but its queue-note file could not be removed: ' . $queueName);
        }
        $records->deleteByQueue($gameId, $queueName);

        $result = [
            'operation' => 'delete_unverified_duplicate',
            'outcome' => $alreadyMissing ? 'already_missing' : 'deleted',
            'queue_game_id' => $gameId,
            'queue_name' => $queueName,
            'queue_key' => (string)($job->payload['queue_key'] ?? ''),
            'original_name' => (string)($job->payload['original_name'] ?? $queueName),
            'queue_name_label' => (string)($job->payload['queue_name_label'] ?? ''),
            'keeper_name' => (string)($job->payload['keeper_name'] ?? ''),
            'keeper_queue' => (string)($job->payload['keeper_queue'] ?? ''),
            'size' => $expectedSize,
            'md5' => $expectedMd5,
        ];
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'status' => 'completed',
            'message' => $alreadyMissing
                ? 'Duplicate queue file was already absent; remaining metadata cleanup completed.'
                : 'Exact duplicate queue file deleted and metadata cleaned.',
        ]);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function duplicatePlan(int $parentJobId): array
    {
        $groups = $this->hashGroups($parentJobId);
        $duplicates = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static function (array $left, array $right): int {
                return ((int)!empty($right['indexed']) <=> (int)!empty($left['indexed']))
                    ?: ((int)$left['modified_at'] <=> (int)$right['modified_at'])
                    ?: ((int)$left['queue_game_id'] <=> (int)$right['queue_game_id'])
                    ?: strcmp((string)$left['queue_name'], (string)$right['queue_name']);
            });
            $keeper = array_shift($group);
            if (!is_array($keeper)) {
                continue;
            }
            foreach ($group as $duplicate) {
                $duplicate['keeper_name'] = (string)$keeper['original_name'];
                $duplicate['keeper_queue'] = (string)$keeper['queue_name_label'];
                $duplicates[] = $duplicate;
            }
        }
        usort($duplicates, static fn(array $a, array $b): int => strcmp((string)$a['queue_key'], (string)$b['queue_key']));
        return $duplicates;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function hashGroups(int $parentJobId): array
    {
        $groups = [];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "hash:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result) || (string)($result['outcome'] ?? '') !== 'hashed') {
                continue;
            }
            $size = (int)($result['size'] ?? -1);
            $md5 = strtolower((string)($result['md5'] ?? ''));
            if ($size < 0 || preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
                continue;
            }
            $groups[$size . ':' . $md5][] = $result;
        }
        return $groups;
    }

    /** @return array{physical_files:int,hashed_files:int,duplicate_groups:int,duplicate_files:int} */
    private function hashSummary(int $parentJobId): array
    {
        $groups = $this->hashGroups($parentJobId);
        $hashed = 0;
        $duplicateGroups = 0;
        $duplicateFiles = 0;
        foreach ($groups as $group) {
            $hashed += count($group);
            if (count($group) > 1) {
                $duplicateGroups++;
                $duplicateFiles += count($group) - 1;
            }
        }
        $progress = $this->parentProgress($parentJobId);
        return [
            'physical_files' => max($hashed, (int)($progress['physical_files'] ?? 0)),
            'hashed_files' => $hashed,
            'duplicate_groups' => $duplicateGroups,
            'duplicate_files' => $duplicateFiles,
        ];
    }

    /** @return array{deleted_files:int,already_missing:int,deleted_bytes:int,deleted:list<array<string,mixed>>} */
    private function deleteSummary(int $parentJobId): array
    {
        $deletedFiles = 0;
        $alreadyMissing = 0;
        $deletedBytes = 0;
        $deleted = [];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "delete:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            if ((string)($result['outcome'] ?? '') === 'deleted') {
                $deletedFiles++;
                $deletedBytes += max(0, (int)($result['size'] ?? 0));
                if (count($deleted) < 100) {
                    $deleted[] = [
                        'name' => (string)($result['original_name'] ?? ''),
                        'queue' => (string)($result['queue_name_label'] ?? ''),
                        'kept_name' => (string)($result['keeper_name'] ?? ''),
                        'kept_queue' => (string)($result['keeper_queue'] ?? ''),
                        'size' => (int)($result['size'] ?? 0),
                        'md5' => (string)($result['md5'] ?? ''),
                    ];
                }
            } else {
                $alreadyMissing++;
            }
        }
        return [
            'deleted_files' => $deletedFiles,
            'already_missing' => $alreadyMissing,
            'deleted_bytes' => $deletedBytes,
            'deleted' => $deleted,
        ];
    }

    /** @return array<string,mixed> */
    private function parentProgress(int $parentJobId): array
    {
        $statement = $this->db->prepare('SELECT progress_json FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$parentJobId]);
        $decoded = json_decode((string)($statement->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,int> $state */
    private function childrenReady(
        JobExecutionContext $context,
        array $state,
        string $stage,
        int $startPercent,
        int $endPercent,
        string $label
    ): bool {
        $total = max(1, $state['total']);
        $percent = $startPercent + (int)floor((($endPercent - $startPercent) * $state['completed']) / $total);
        $problems = $state['failed'] + $state['dead_letter'] + $state['cancelled'];
        if ($problems > 0) {
            $context->defer(30, $this->progress(
                $stage,
                $percent,
                ucfirst($label) . ' workflow is waiting on ' . $problems . ' failed/cancelled unit(s). '
                    . 'Restart only those units; successful units are retained.',
                [$label . '_children' => $state]
            ));
        }
        if (($state['queued'] + $state['running']) > 0) {
            $context->defer(2, $this->progress(
                $stage,
                $percent,
                ucfirst($label) . ' units: ' . $state['completed'] . '/' . $state['total']
                    . ' completed, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                [$label . '_children' => $state]
            ));
        }
        return $state['total'] === 0 || $state['completed'] === $state['total'];
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId, string $prefix): array
    {
        $state = ['total' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0, 'cancelled' => 0];
        $statement = $this->db->prepare(
            'SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE ? GROUP BY status'
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

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function progress(string $stage, int $percent, string $message, array $extra = []): array
    {
        $percent = max(0, min(100, $percent));
        return $extra + [
            'workflow_version' => self::WORKFLOW_VERSION,
            'stage' => $stage,
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'message' => $message,
        ];
    }
}
