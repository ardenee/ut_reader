<?php
/**
 * Builds cached exact game/dependency evidence. Bucket scope is a durable
 * coordinator over the same authoritative one-file matcher used elsewhere.
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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoWorkflowChildStateQuery;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchCache;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchQuery;

final class CatalogUnverifiedGameMatchRefreshJobHandler implements JobHandler
{
    private const WORKFLOW_VERSION = 2;
    private const PLAN_BATCH_SIZE = 500;

    private readonly PdoUnverifiedGameMatchCache $cache;
    private readonly PdoUnverifiedGameMatchQuery $matcher;
    private readonly PdoWorkflowChildStateQuery $childStates;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->cache = new PdoUnverifiedGameMatchCache($db);
        $this->matcher = new PdoUnverifiedGameMatchQuery($db);
        $this->childStates = new PdoWorkflowChildStateQuery($db);
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REFRESH_UNVERIFIED_GAME_MATCHES;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if (!$this->cache->available()) {
            throw new \RuntimeException(
                'Unverified game-match cache table is unavailable. Run catalog/bin/migrate.php migrate.'
            );
        }

        $fileId = (int)($job->payload['file_id'] ?? 0);
        if ($fileId > 0) {
            return $this->refreshOne($fileId, $context);
        }

        $scope = strtolower(trim((string)($job->payload['scope'] ?? 'bucket')));
        if ($scope !== 'bucket') {
            throw new \RuntimeException('Unsupported unverified game-match refresh scope: ' . $scope);
        }
        return $this->refreshBucketWorkflow($job, $context);
    }

    /** @return array<string,mixed> */
    private function refreshOne(int $fileId, JobExecutionContext $context): array
    {
        $row = \catalog_one(
            $this->db,
            'SELECT id,original_name,extension,scan_notes FROM ue_files '
            . 'WHERE id=? AND scan_status="unverified" LIMIT 1',
            [$fileId]
        );
        if (!$row) {
            return [
                'operation' => 'refresh_unverified_game_matches',
                'scope' => 'file',
                'file_id' => $fileId,
                'status' => 'skipped',
                'message' => 'Unverified file no longer exists.',
            ];
        }

        $name = (string)$row['original_name'];
        if (strtolower(trim((string)($row['extension'] ?? ''))) === 'pak') {
            $message = 'PAK container dependency evidence is provided by its extracted package children, not the container itself.';
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'file_id' => $fileId,
                'status' => 'container',
                'message' => $message,
            ]);
            return [
                'operation' => 'refresh_unverified_game_matches',
                'scope' => 'file',
                'file_id' => $fileId,
                'status' => 'container',
                'game_evidence_count' => 0,
                'exact_compatible_game_count' => 0,
                'message' => $message,
            ];
        }

        $parseError = $this->packageParseError((string)($row['scan_notes'] ?? ''));
        if ($parseError !== '') {
            $message = 'Dependency evidence unavailable for ' . $name
                . ': package tables could not be read: ' . $parseError;
            $this->cache->storeFailed($fileId, $message);
            $context->checkpoint([
                'stage' => 'complete',
                'done' => 1,
                'total' => 1,
                'percent' => 100,
                'file_id' => $fileId,
                'status' => 'unavailable',
                'message' => $message,
            ]);
            return [
                'operation' => 'refresh_unverified_game_matches',
                'scope' => 'file',
                'file_id' => $fileId,
                'status' => 'unavailable',
                'game_evidence_count' => 0,
                'exact_compatible_game_count' => 0,
                'message' => $message,
            ];
        }

        $context->checkpoint([
            'stage' => 'match_refresh',
            'done' => 0,
            'total' => 1,
            'percent' => 0,
            'file_id' => $fileId,
            'message' => 'Calculating exact dependency evidence for ' . $name . '.',
        ]);
        $this->cache->markPending($fileId);

        try {
            $matches = $this->matcher->one($fileId);
            $this->cache->storeReady($fileId, $matches);
        } catch (Throwable $error) {
            $this->cache->storeFailed($fileId, $error);
            throw $error;
        }

        $visible = array_values(array_filter(
            $matches,
            static fn(array $match): bool => (int)($match['import_count'] ?? 0) > 0
        ));
        $exact = count(array_filter(
            $visible,
            static fn(array $match): bool => !empty($match['compatible'])
                && (int)($match['exact_object_matches'] ?? 0) > 0
        ));
        $message = 'Cached ' . count($visible) . ' game evidence result(s) for ' . $name
            . '; ' . $exact . ' exact compatible game(s).';
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 1,
            'total' => 1,
            'percent' => 100,
            'file_id' => $fileId,
            'message' => $message,
        ]);

        return [
            'operation' => 'refresh_unverified_game_matches',
            'scope' => 'file',
            'file_id' => $fileId,
            'status' => 'completed',
            'game_evidence_count' => count($visible),
            'exact_compatible_game_count' => $exact,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function refreshBucketWorkflow(ClaimedJob $job, JobExecutionContext $context): array
    {
        $resume = $context->resumeProgress();
        $stage = trim((string)($resume['stage'] ?? ''));
        if ($stage === '' || $stage === 'worker_start' || (int)($resume['workflow_version'] ?? 0) < self::WORKFLOW_VERSION) {
            $this->cache->purgeNonUnverified();
            $stage = 'bucket_match_plan';
            $resume = [];
        }

        if ($stage === 'bucket_match_plan') {
            $this->planBucketUnits($job, $context, $resume);
            $stage = 'bucket_match_wait';
        }

        if ($stage !== 'bucket_match_wait') {
            throw new \RuntimeException('Unknown Upload Bucket match workflow stage: ' . $stage);
        }

        $state = $this->childStates->fetch($job->id, 'match:');
        $total = max(1, $state['total']);
        $percent = 5 + (int)floor(($state['completed'] * 94) / $total);
        if (($state['failed'] + $state['dead_letter'] + $state['cancelled']) > 0) {
            $context->defer(30, $this->workflowProgress(
                'bucket_match_wait',
                min(99, $percent),
                'Upload Bucket matching is waiting on '
                    . ($state['failed'] + $state['dead_letter'] + $state['cancelled'])
                    . ' failed/cancelled file unit(s). Restart only those child jobs; successful cached matches are retained.',
                ['children' => $state]
            ));
        }
        if (($state['queued'] + $state['running']) > 0) {
            $context->defer(2, $this->workflowProgress(
                'bucket_match_wait',
                min(99, $percent),
                'Upload Bucket match units: ' . $state['completed'] . '/' . $state['total']
                    . ' complete, ' . $state['running'] . ' running, ' . $state['queued'] . ' queued.',
                ['children' => $state]
            ));
        }

        $message = 'Upload Bucket match cache refresh complete: ' . $state['completed'] . ' durable file unit(s).';
        $result = [
            'operation' => 'refresh_unverified_game_matches',
            'workflow_version' => self::WORKFLOW_VERSION,
            'scope' => 'bucket',
            'status' => 'completed',
            'processed' => $state['completed'],
            'failed' => 0,
            'children' => $state,
            'message' => $message,
        ];
        $context->checkpoint($this->workflowProgress('complete', 100, $message, $result));
        return $result;
    }

    /** @param array<string,mixed> $resume */
    private function planBucketUnits(ClaimedJob $job, JobExecutionContext $context, array $resume): void
    {
        $snapshotMaxId = max(0, (int)($resume['snapshot_max_file_id'] ?? 0));
        $lastId = max(0, (int)($resume['plan_last_file_id'] ?? 0));
        $planned = max(0, (int)($resume['planned_units'] ?? 0));
        if ($snapshotMaxId < 1) {
            $row = \catalog_one(
                $this->db,
                'SELECT COUNT(*) c,COALESCE(MAX(id),0) max_id FROM ue_files '
                . 'WHERE scan_status="unverified" AND unverified_queue_game_id=0 '
                . 'AND LOWER(COALESCE(extension,""))<>"pak"'
            ) ?: [];
            $snapshotMaxId = (int)($row['max_id'] ?? 0);
        }
        if ($snapshotMaxId < 1) {
            $context->checkpoint($this->workflowProgress('bucket_match_wait', 5, 'Upload Bucket contains no package files to refresh.'));
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id FROM ue_files WHERE scan_status="unverified" AND unverified_queue_game_id=0 '
            . 'AND LOWER(COALESCE(extension,""))<>"pak" '
            . 'AND id>? AND id<=? ORDER BY id LIMIT ' . self::PLAN_BATCH_SIZE
        );
        $statement->execute([$lastId, $snapshotMaxId]);
        $ids = array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));
        $queue = new PdoJobQueue($this->db);
        foreach ($ids as $fileId) {
            $queue->enqueue(
                $job->queue,
                JobType::REFRESH_UNVERIFIED_GAME_MATCHES,
                ['file_id' => $fileId, 'workflow_parent_job_id' => $job->id],
                90,
                null,
                null,
                null,
                3,
                $job->id,
                'match:' . $fileId
            );
            $lastId = $fileId;
            $planned++;
        }

        $progress = $this->workflowProgress('bucket_match_plan', 3,
            'Planned ' . $planned . ' durable Upload Bucket match unit(s).', [
                'snapshot_max_file_id' => $snapshotMaxId,
                'plan_last_file_id' => $lastId,
                'planned_units' => $planned,
            ]);
        if ($ids !== [] && $lastId < $snapshotMaxId) {
            $context->defer(1, $progress);
        }
        $context->checkpoint($this->workflowProgress(
            'bucket_match_wait',
            5,
            'Planned ' . $planned . ' durable Upload Bucket match unit(s); waiting for workers.',
            ['planned_units' => $planned]
        ));
    }

    private function packageParseError(string $notes): string
    {
        $notes = str_replace(["\r\n", "\r"], "\n", $notes);
        $marker = 'Unverified table parse failed:';
        $position = strpos($notes, $marker);
        if ($position === false) {
            return '';
        }
        $error = trim(substr($notes, $position + strlen($marker)));
        $parts = preg_split('/\n(?:Queue reason:|Metadata repair attempted:)/', $error, 2);
        $error = trim((string)($parts[0] ?? $error));
        return trim(preg_replace('/\s+/', ' ', $error) ?? $error);
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function workflowProgress(string $stage, int $percent, string $message, array $extra = []): array
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
