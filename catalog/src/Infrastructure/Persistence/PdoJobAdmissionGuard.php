<?php
/**
 * Serializes only the resource/concurrency decision for a candidate job.
 *
 * The durable queue row remains protected by FOR UPDATE SKIP LOCKED. These
 * short-lived named locks prevent two workers that selected different rows from
 * simultaneously observing the same free resource slot or concurrency key.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoJobAdmissionGuard
{
    private const LOCK_WAIT_SECONDS = 2;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<string>|null Lock names that must be released by release().
     */
    public function acquire(string $queue, string $resourceClass, ?string $concurrencyKey): ?array
    {
        $resourceClass = trim($resourceClass) !== '' ? trim($resourceClass) : 'default';
        $locks = [
            $this->lockName('resource', $queue . "\0" . $resourceClass),
        ];
        $concurrencyKey = trim((string)$concurrencyKey);
        if ($concurrencyKey !== '') {
            $locks[] = $this->lockName('key', $queue . "\0" . $concurrencyKey);
        }

        sort($locks, SORT_STRING);
        $held = [];
        foreach ($locks as $lock) {
            if (!$this->acquireNamedLock($lock)) {
                $this->release($held);
                return null;
            }
            $held[] = $lock;
        }
        return $held;
    }

    /** @param list<string> $locks */
    public function release(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$lock]);
            } catch (Throwable $error) {
                error_log('[UnrealDB jobs] Could not release admission lock: ' . $error->getMessage());
            }
        }
    }

    public function currentLimit(string $resourceClass, int $fallback): int
    {
        $fallback = self::limit($fallback);
        $resourceClass = trim($resourceClass) !== '' ? trim($resourceClass) : 'default';
        try {
            $statement = $this->db->prepare(
                'SELECT limit_value FROM ue_job_resource_limits WHERE resource_class=? LIMIT 1'
            );
            $statement->execute([$resourceClass]);
            $value = $statement->fetchColumn();
            return $value === false ? $fallback : self::limit((int)$value);
        } catch (Throwable) {
            // Fresh/legacy databases may not have the settings table yet. The
            // persisted queue-row value remains the compatibility fallback.
            return $fallback;
        }
    }

    public function canRun(
        string $queue,
        string $resourceClass,
        int $resourceLimit,
        ?string $concurrencyKey
    ): bool {
        $resourceClass = trim($resourceClass) !== '' ? trim($resourceClass) : 'default';
        $resourceLimit = $this->currentLimit($resourceClass, $resourceLimit);

        $running = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" '
            . 'AND COALESCE(NULLIF(resource_class,""),"default")=?'
        );
        $running->execute([$queue, $resourceClass]);
        if ((int)$running->fetchColumn() >= $resourceLimit) {
            return false;
        }

        $concurrencyKey = trim((string)$concurrencyKey);
        if ($concurrencyKey === '') {
            return true;
        }

        $key = $this->db->prepare(
            'SELECT 1 FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" AND concurrency_key=? LIMIT 1'
        );
        $key->execute([$queue, $concurrencyKey]);
        return $key->fetchColumn() === false;
    }

    private function acquireNamedLock(string $lock): bool
    {
        try {
            $statement = $this->db->prepare('SELECT GET_LOCK(?,?)');
            $statement->execute([$lock, self::LOCK_WAIT_SECONDS]);
            return (int)$statement->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function lockName(string $kind, string $identity): string
    {
        return 'unrealdb:admit:' . $kind . ':' . substr(hash('sha256', $identity), 0, 32);
    }

    private static function limit(int $value): int
    {
        return max(1, min(100, $value));
    }
}
