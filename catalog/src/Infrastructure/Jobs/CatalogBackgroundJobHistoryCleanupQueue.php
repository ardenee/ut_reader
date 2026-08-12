<?php
/**
 * Creates immutable terminal-job cleanup snapshots and enqueues their durable
 * cleanup worker. Snapshot selection is intentionally cheap HTTP-time database
 * work; filesystem deletion happens only in the worker.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogBackgroundJobHistoryCleanupQueue
{
    private const SNAPSHOT_LIMIT = 10000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /**
     * @param list<int> $jobIds
     * @return array{job_id:int,scheduled:int,requested:int,limited:bool}
     */
    public function enqueueSnapshot(
        string $queueName,
        array $jobIds,
        int $requested,
        bool $limited,
        ?int $userId,
        string $label = 'Background job history cleanup'
    ): array {
        $queueName = $this->queueName($queueName);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $jobIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        if (count($ids) > self::SNAPSHOT_LIMIT) {
            throw new \InvalidArgumentException('Background-job cleanup snapshot exceeds 10,000 IDs.');
        }

        $requested = max(count($ids), $requested);
        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::CLEAN_BACKGROUND_JOB_HISTORY,
            [
                'target_queue' => $queueName,
                'job_ids' => $ids,
                'requested' => $requested,
                'limited' => $limited,
                'source_relative_path' => $label . ' · ' . count($ids) . ' snapshot job(s)',
                'requested_by' => $userId,
            ],
            50,
            null,
            null,
            $userId,
            5
        );

        return [
            'job_id' => $jobId,
            'scheduled' => count($ids),
            'requested' => $requested,
            'limited' => $limited,
        ];
    }

    /** @return array{ids:list<int>,requested:int,limited:bool,cutoff:string} */
    public function snapshotOlderThan(string $queueName, int $retentionDays): array
    {
        $queueName = $this->queueName($queueName);
        $retentionDays = max(1, min($retentionDays, 3650));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status IN ("completed","failed","dead_letter","cancelled") '
            . 'AND COALESCE(completed_at,updated_at,created_at)<?'
        );
        $count->execute([$queueName, $cutoff]);
        $requested = max(0, (int)$count->fetchColumn());

        $select = $this->db->prepare(
            'SELECT id FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status IN ("completed","failed","dead_letter","cancelled") '
            . 'AND COALESCE(completed_at,updated_at,created_at)<? '
            . 'ORDER BY id ASC LIMIT ' . self::SNAPSHOT_LIMIT
        );
        $select->execute([$queueName, $cutoff]);
        $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return [
            'ids' => $ids,
            'requested' => $requested,
            'limited' => $requested > count($ids),
            'cutoff' => $cutoff,
        ];
    }

    private function queueName(string $queueName): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \InvalidArgumentException('A valid background-job queue name is required.');
        }
        return $queueName;
    }
}
