<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds cached exact game/dependency evidence for unverified packages outside the HTTP request path.
 * Why: Export-path matching against catalog dependencies can be expensive even for a small visible bucket page.
 * Role: Durable background projection handler for per-file staging and full Upload Bucket refreshes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchCache;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoUnverifiedGameMatchQuery;

final class CatalogUnverifiedGameMatchRefreshJobHandler implements JobHandler
{
    private const BATCH_SIZE = 20;

    private readonly PdoUnverifiedGameMatchCache $cache;
    private readonly PdoUnverifiedGameMatchQuery $matcher;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->cache = new PdoUnverifiedGameMatchCache($db);
        $this->matcher = new PdoUnverifiedGameMatchQuery($db);
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
        return $this->refreshBucket($context);
    }

    /** @return array<string,mixed> */
    private function refreshOne(int $fileId, JobExecutionContext $context): array
    {
        $row = \catalog_one(
            $this->db,
            'SELECT id,original_name FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1',
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
    private function refreshBucket(JobExecutionContext $context): array
    {
        $this->cache->purgeNonUnverified();
        $row = \catalog_one(
            $this->db,
            'SELECT COUNT(*) c,COALESCE(MAX(id),0) max_id FROM ue_files '
            . 'WHERE scan_status="unverified" AND unverified_queue_game_id=0'
        ) ?: [];
        $total = (int)($row['c'] ?? 0);
        $snapshotMaxId = (int)($row['max_id'] ?? 0);
        $context->checkpoint([
            'stage' => 'match_refresh',
            'done' => 0,
            'total' => max(1, $total),
            'percent' => $total > 0 ? 0 : 100,
            'message' => 'Refreshing cached game/dependency evidence for ' . $total . ' Upload Bucket file(s).',
        ]);

        if ($total === 0 || $snapshotMaxId < 1) {
            return [
                'operation' => 'refresh_unverified_game_matches',
                'scope' => 'bucket',
                'status' => 'completed',
                'processed' => 0,
                'failed' => 0,
                'message' => 'Upload Bucket contains no unverified files to refresh.',
            ];
        }

        $processed = 0;
        $failed = 0;
        $lastId = 0;
        while ($lastId < $snapshotMaxId) {
            $statement = $this->db->prepare(
                'SELECT id FROM ue_files WHERE scan_status="unverified" AND unverified_queue_game_id=0 '
                . 'AND id>? AND id<=? ORDER BY id LIMIT ' . self::BATCH_SIZE
            );
            $statement->execute([$lastId, $snapshotMaxId]);
            $ids = array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));
            if ($ids === []) {
                break;
            }
            $lastId = max($ids);
            foreach ($ids as $id) {
                $this->cache->markPending($id);
            }

            try {
                $matchesByFile = $this->matcher->bulk($ids);
                foreach ($ids as $id) {
                    $this->cache->storeReady($id, $matchesByFile[$id] ?? []);
                    $processed++;
                }
            } catch (Throwable $batchError) {
                // Keep one damaged metadata snapshot from blocking every other
                // bucket file. The normal path remains batched; fallback isolates
                // failures only when the batch calculation itself cannot complete.
                foreach ($ids as $id) {
                    try {
                        $this->cache->storeReady($id, $this->matcher->one($id));
                        $processed++;
                    } catch (Throwable $fileError) {
                        $this->cache->storeFailed($id, $fileError);
                        $failed++;
                    }
                }
            }

            $done = min($total, $processed + $failed);
            $context->checkpoint([
                'stage' => 'match_refresh',
                'done' => $done,
                'total' => $total,
                'percent' => (int)floor($done * 100 / max(1, $total)),
                'processed' => $processed,
                'failed' => $failed,
                'message' => 'Refreshed game/dependency evidence for ' . $done . ' of ' . $total
                    . ' Upload Bucket file(s).',
            ]);
        }

        $message = 'Upload Bucket match cache refresh complete: ' . $processed . ' ready, ' . $failed . ' failed.';
        $context->checkpoint([
            'stage' => 'complete',
            'done' => $total,
            'total' => $total,
            'percent' => 100,
            'processed' => $processed,
            'failed' => $failed,
            'message' => $message,
        ]);

        return [
            'operation' => 'refresh_unverified_game_matches',
            'scope' => 'bucket',
            'status' => 'completed',
            'processed' => $processed,
            'failed' => $failed,
            'message' => $message,
        ];
    }
}
