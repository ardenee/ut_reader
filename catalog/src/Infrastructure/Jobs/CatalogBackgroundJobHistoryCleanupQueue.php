<?php
/**
 * Creates bounded terminal-job cleanup snapshots and enqueues their durable
 * cleanup worker. Retention cleanup keeps one fixed cutoff and the worker can
 * request subsequent snapshots until all eligible history before that cutoff is
 * drained.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogBackgroundJobHistoryCleanupQueue
{
    public const SNAPSHOT_LIMIT = 10000;

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
        string $label = 'Background job history cleanup',
        string $retentionCutoff = ''
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
        if ($ids === []) {
            return [
                'job_id' => 0,
                'scheduled' => 0,
                'requested' => $requested,
                'limited' => false,
            ];
        }

        $retentionCutoff = $this->cutoff($retentionCutoff, false);
        $payload = [
            'target_queue' => $queueName,
            'job_ids' => $ids,
            'requested' => $requested,
            'limited' => $limited,
            'source_relative_path' => $label . ' · ' . count($ids) . ' snapshot job(s)',
            'requested_by' => $userId,
        ];
        if ($retentionCutoff !== '') {
            $payload['retention_cutoff'] = $retentionCutoff;
            $payload['retention_auto_continue'] = true;
        }

        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::CLEAN_BACKGROUND_JOB_HISTORY,
            $payload,
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
        $retentionDays = max(1, min($retentionDays, 3650));
        return $this->snapshotBefore(
            $queueName,
            gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400))
        );
    }

    /** @return array{ids:list<int>,requested:int,limited:bool,cutoff:string} */
    public function snapshotBefore(string $queueName, string $cutoff): array
    {
        $queueName = $this->queueName($queueName);
        $cutoff = $this->cutoff($cutoff, true);

        /*
         * Automatic retention removes resolved history only. Unresolved failed,
         * dead-letter, rejected, unverified, partial and error roots remain for
         * deliberate operator action. Successful/cancelled roots are deleted with
         * their entire historical subtree.
         */
        $eligible = 'queue_name=? AND parent_job_id IS NULL AND ('
            . 'status="cancelled" OR '
            . '(status="completed" AND COALESCE(display_status,"completed") '
            . 'NOT IN ("failed","rejected","unverified","partial","error"))'
            . ') AND COALESCE(completed_at,updated_at,created_at)<?';

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE ' . $eligible
        );
        $count->execute([$queueName, $cutoff]);
        $requested = max(0, (int)$count->fetchColumn());

        $select = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE ' . $eligible
            . ' ORDER BY id ASC LIMIT ' . self::SNAPSHOT_LIMIT
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

    private function cutoff(string $value, bool $required): string
    {
        $value = trim($value);
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException('A retention cutoff is required.');
            }
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \InvalidArgumentException('Retention cutoff must use UTC Y-m-d H:i:s format.');
        }
        return $value;
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
