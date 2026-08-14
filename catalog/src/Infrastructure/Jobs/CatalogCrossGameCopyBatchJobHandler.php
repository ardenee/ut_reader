<?php
/**
 * Durable cross-game copy preparation workflow.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogCrossGamePackageCopyService;

final class CatalogCrossGameCopyBatchJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 2;
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
        return $jobType === JobType::CROSS_GAME_COPY_BATCH;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ((int)($job->payload['source_file_id'] ?? 0) > 0
            && (int)($job->payload['workflow_parent_job_id'] ?? 0) > 0) {
            return $this->prepareOne($job, $context);
        }
        return $this->coordinate($job, $context);
    }

    /** @return array<string,mixed> */
    private function coordinate(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceIds = $this->sourceIds($job->payload['source_file_ids'] ?? []);
        $destinationGameId = (int)($job->payload['destination_game_id'] ?? 0);
        $userId = (int)($job->payload['user_id'] ?? 0);
        $userId = $userId > 0 ? $userId : null;
        if ($sourceIds === [] || $destinationGameId < 1) {
            throw new \RuntimeException('Cross-game copy batch requires source files and a destination game.');
        }

        $destination = \catalog_one(
            $this->db,
            'SELECT id,name FROM ue_games WHERE id=? LIMIT 1',
            [$destinationGameId]
        );
        if (!$destination) {
            throw new \RuntimeException('Cross-game copy destination no longer exists.');
        }

        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ((int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $stage = 'cross_game_plan';
            $resume = [];
        }
        if ($stage === '' || $stage === 'worker_start') {
            $stage = 'cross_game_plan';
        }

        if ($stage === 'cross_game_plan') {
            $this->plan($job, $context, $sourceIds, $destinationGameId, $userId, $resume);
            $stage = 'cross_game_wait';
        }

        if ($stage === 'cross_game_wait') {
            $children = $this->childState($job->id);
            $total = max(1, $children['total']);
            $percent = (int)floor(($children['completed'] * 95) / $total);
            $problems = $children['failed'] + $children['dead_letter'] + $children['cancelled'];
            if ($problems > 0) {
                $context->defer(30, $this->progress(
                    'cross_game_wait',
                    min(95, $percent),
                    'Cross-game preparation is waiting on ' . $problems . ' failed/cancelled source unit(s). '
                        . 'Restart only those units; successful queue decisions are retained.',
                    ['children' => $children, 'destination_game' => (string)$destination['name']]
                ));
            }
            if (($children['queued'] + $children['running']) > 0) {
                $context->defer(2, $this->progress(
                    'cross_game_wait',
                    min(95, $percent),
                    'Cross-game source units: ' . $children['completed'] . '/' . $children['total']
                        . ' complete, ' . $children['running'] . ' running, ' . $children['queued'] . ' queued.',
                    ['children' => $children, 'destination_game' => (string)$destination['name']]
                ));
            }
            $stage = 'cross_game_finalize';
            $context->checkpoint($this->progress(
                $stage,
                98,
                'All selected source packages have a durable queue-preparation result.',
                ['children' => $children, 'destination_game' => (string)$destination['name']]
            ));
        }

        if ($stage !== 'cross_game_finalize') {
            throw new \RuntimeException('Unknown cross-game preparation workflow stage: ' . $stage);
        }

        $aggregate = $this->aggregate($job->id);
        $message = 'Cross-game queue preparation complete for ' . (string)$destination['name'] . ': '
            . $aggregate['queued'] . ' queued, ' . $aggregate['deduplicated'] . ' already queued, '
            . $aggregate['skipped'] . ' skipped. Destination import jobs continue independently.';
        $context->checkpoint($this->progress('complete', 100, $message, [
            'queued' => $aggregate['queued'],
            'deduplicated' => $aggregate['deduplicated'],
            'skipped' => $aggregate['skipped'],
            'children' => $this->childState($job->id),
        ]));

        return [
            'operation' => 'cross_game_copy_batch',
            'workflow_version' => self::WORKFLOW_VERSION,
            'status' => 'completed',
            'destination_game_id' => $destinationGameId,
            'destination_game' => (string)$destination['name'],
            'selected' => count($sourceIds),
            'queued' => $aggregate['queued'],
            'deduplicated' => $aggregate['deduplicated'],
            'skipped' => $aggregate['skipped'],
            'failed' => 0,
            'child_job_ids' => $aggregate['import_job_ids'],
            'issues' => $aggregate['skips'],
            'message' => $message,
            'children' => $this->childState($job->id),
        ];
    }

    /** @param list<int> $sourceIds @param array<string,mixed> $resume */
    private function plan(
        ClaimedJob $job,
        JobExecutionContext $context,
        array $sourceIds,
        int $destinationGameId,
        ?int $userId,
        array $resume
    ): void {
        $offset = max(0, (int)($resume['plan_offset'] ?? 0));
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        if ((string)($resume['stage'] ?? '') !== 'cross_game_plan') {
            $offset = 0;
            $planned = 0;
        }

        $slice = array_slice($sourceIds, $offset, self::PLAN_BATCH_SIZE);
        $queue = new PdoJobQueue($this->db);
        foreach ($slice as $sourceFileId) {
            $queue->enqueue(
                $job->queue,
                JobType::CROSS_GAME_COPY_BATCH,
                [
                    'source_file_id' => $sourceFileId,
                    'destination_game_id' => $destinationGameId,
                    'user_id' => $userId,
                    'workflow_parent_job_id' => $job->id,
                ],
                20,
                null,
                null,
                $userId,
                3,
                $job->id,
                'source:' . $sourceFileId
            );
            $offset++;
            $planned++;
        }

        $progress = $this->progress(
            'cross_game_plan',
            min(5, (int)floor(($offset * 5) / max(1, count($sourceIds)))),
            'Planned ' . $planned . '/' . count($sourceIds) . ' durable source preparation unit(s).',
            ['plan_offset' => $offset, 'planned_units' => $planned]
        );
        if ($offset < count($sourceIds)) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->progress(
            'cross_game_wait',
            5,
            'All ' . $planned . ' source preparation unit(s) are planned; waiting for workers.',
            ['planned_units' => $planned]
        ));
    }

    /** @return array<string,mixed> */
    private function prepareOne(ClaimedJob $job, JobExecutionContext $context): array
    {
        $sourceFileId = (int)$job->payload['source_file_id'];
        $destinationGameId = (int)($job->payload['destination_game_id'] ?? 0);
        $userId = (int)($job->payload['user_id'] ?? 0);
        $userId = $userId > 0 ? $userId : null;
        if ($sourceFileId < 1 || $destinationGameId < 1) {
            throw new \RuntimeException('Cross-game source unit requires source and destination IDs.');
        }

        $row = \catalog_one(
            $this->db,
            'SELECT original_name FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
            [$sourceFileId]
        );
        $name = trim((string)($row['original_name'] ?? '')) ?: 'file #' . $sourceFileId;
        $context->checkpoint([
            'stage' => 'cross_game_source',
            'done' => 0,
            'total' => 1,
            'percent' => 1,
            'source_file_id' => $sourceFileId,
            'current_file' => $name,
            'message' => 'Revalidating destination need for ' . $name . '.',
        ]);

        try {
            $result = (new CatalogCrossGamePackageCopyService($this->db, $this->config))->queue(
                $sourceFileId,
                $destinationGameId,
                $userId
            );
        } catch (\Throwable $error) {
            $message = trim($error->getMessage());
            if (!$this->isExpectedSkip($message)) {
                throw $error;
            }
            $result = [
                'job_id' => 0,
                'deduplicated' => false,
                'skip_message' => $message !== '' ? $message : 'Package no longer needs to be copied.',
            ];
        }

        $skip = trim((string)($result['skip_message'] ?? ''));
        $deduplicated = !empty($result['deduplicated']);
        $outcome = $skip !== '' ? 'skipped' : ($deduplicated ? 'deduplicated' : 'queued');
        $message = $skip !== ''
            ? $skip
            : ($deduplicated
                ? 'Destination import is already represented by job #' . (int)($result['job_id'] ?? 0) . '.'
                : 'Queued destination import job #' . (int)($result['job_id'] ?? 0) . '.');

        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'status' => $outcome,
            'source_file_id' => $sourceFileId,
            'current_file' => $name,
            'message' => $message,
        ]);
        return [
            'operation' => 'cross_game_copy_source',
            'source_file_id' => $sourceFileId,
            'file' => $name,
            'destination_game_id' => $destinationGameId,
            'outcome' => $outcome,
            'job_id' => (int)($result['job_id'] ?? 0),
            'message' => $message,
        ];
    }

    /** @return list<int> */
    private function sourceIds(mixed $raw): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($raw) ? $raw : []),
            static fn(int $id): bool => $id > 0
        )));
        if (count($ids) > 1000) {
            throw new \RuntimeException('Cross-game copy batch is limited to 1,000 source files.');
        }
        return $ids;
    }

    /** @return array{total:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int} */
    private function childState(int $parentJobId): array
    {
        return (new PdoWorkflowChildStateQuery($this->db))->fetch($parentJobId, 'source:');
    }

    /** @return array{queued:int,deduplicated:int,skipped:int,import_job_ids:list<int>,skips:list<array<string,mixed>>} */
    private function aggregate(int $parentJobId): array
    {
        $queued = 0;
        $deduplicated = 0;
        $skipped = 0;
        $jobIds = [];
        $skips = [];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? '
            . 'AND workflow_unit_key LIKE "source:%" AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            $outcome = (string)($result['outcome'] ?? '');
            if ($outcome === 'queued') {
                $queued++;
            } elseif ($outcome === 'deduplicated') {
                $deduplicated++;
            } else {
                $skipped++;
                if (count($skips) < 100) {
                    $skips[] = [
                        'source_file_id' => (int)($result['source_file_id'] ?? 0),
                        'file' => (string)($result['file'] ?? ''),
                        'status' => 'skipped',
                        'message' => (string)($result['message'] ?? ''),
                    ];
                }
            }
            $importJobId = (int)($result['job_id'] ?? 0);
            if ($importJobId > 0) {
                $jobIds[$importJobId] = $importJobId;
            }
        }
        ksort($jobIds, SORT_NUMERIC);
        return [
            'queued' => $queued,
            'deduplicated' => $deduplicated,
            'skipped' => $skipped,
            'import_job_ids' => array_values($jobIds),
            'skips' => $skips,
        ];
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

    private function isExpectedSkip(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'no longer exports an object required by a missing dependency')
            || str_contains($message, 'already verified in the target game')
            || str_contains($message, 'same package bytes are already verified');
    }
}
