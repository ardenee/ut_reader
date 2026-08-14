<?php
/**
 * Process-lifetime worker ownership backed by a MySQL connection-scoped lock.
 *
 * Unlike elapsed lease timestamps, the lock proves process/database-session
 * liveness. MySQL releases it automatically when a worker process, container,
 * pod or host loses its connection.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoWorkerOwnership
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function acquire(string $queue, string $workerId): string
    {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $workerId = PdoJobQueueSupport::requiredIdentifier($workerId, 'worker id');
        $lock = self::lockName($queue, $workerId);

        $statement = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $statement->execute([$lock]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Worker identity is already active: ' . $workerId);
        }
        return $lock;
    }

    public function release(string $lock): void
    {
        if ($lock === '') {
            return;
        }
        try {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lock]);
        } catch (Throwable $error) {
            error_log('[UnrealDB jobs] Could not release worker ownership lock: ' . $error->getMessage());
        }
    }

    public function isAlive(string $queue, string $workerId): bool
    {
        $queue = PdoJobQueueSupport::requiredIdentifier($queue, 'queue');
        $workerId = PdoJobQueueSupport::requiredIdentifier($workerId, 'worker id');
        try {
            $statement = $this->db->prepare('SELECT IS_USED_LOCK(?)');
            $statement->execute([self::lockName($queue, $workerId)]);
            return $statement->fetchColumn() !== null;
        } catch (Throwable) {
            // Recovery must fail closed if the database cannot prove that an
            // owner disappeared. Never steal work because a liveness probe failed.
            return true;
        }
    }

    public static function lockName(string $queue, string $workerId): string
    {
        return 'unrealdb:worker:' . substr(hash('sha256', $queue . "\0" . $workerId), 0, 40);
    }
}
