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
use PDOException;
use RuntimeException;
use Throwable;

final class PdoJobAdmissionGuard
{
    private const LOCK_WAIT_SECONDS = 2;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Compatibility wrapper for callers that only need the acquired locks.
     *
     * @return list<string>|null Lock names that must be released by release().
     */
    public function acquire(string $queue, string $resourceClass, ?string $concurrencyKey): ?array
    {
        return $this->acquireWithBlocker($queue, $resourceClass, $concurrencyKey)['locks'];
    }

    /**
     * Acquire the short-lived admission locks and identify the dimension that
     * prevented acquisition. The claimer uses this to skip the whole blocked
     * resource class/key instead of repeatedly probing rows that cannot run.
     *
     * @return array{locks:?list<string>,blocked_dimension:?string}
     */
    public function acquireWithBlocker(
        string $queue,
        string $resourceClass,
        ?string $concurrencyKey
    ): array {
        $resourceClass = trim($resourceClass) !== '' ? trim($resourceClass) : 'default';
        $entries = [[
            'name' => $this->lockName('resource', $queue . "\0" . $resourceClass),
            'dimension' => 'resource',
        ]];
        $concurrencyKey = trim((string)$concurrencyKey);
        if ($concurrencyKey !== '') {
            $entries[] = [
                'name' => $this->lockName('key', $queue . "\0" . $concurrencyKey),
                'dimension' => 'concurrency',
            ];
        }

        usort(
            $entries,
            static fn(array $left, array $right): int => strcmp((string)$left['name'], (string)$right['name'])
        );
        $held = [];
        try {
            foreach ($entries as $entry) {
                $lock = (string)$entry['name'];
                if (!$this->acquireNamedLock($lock)) {
                    $this->release($held);
                    return [
                        'locks' => null,
                        'blocked_dimension' => (string)$entry['dimension'],
                    ];
                }
                $held[] = $lock;
            }
        } catch (Throwable $error) {
            // GET_LOCK can fail after an earlier dimension was acquired. Never
            // leak a connection-scoped lock when surfacing the database fault.
            $this->release($held);
            throw $error;
        }
        return ['locks' => $held, 'blocked_dimension' => null];
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
        $resourceClass = trim($resourceClass) !== '' ? trim($resourceClass) : 'default';
        $fallback = $this->environmentLimit($resourceClass, $fallback);
        try {
            $statement = $this->db->prepare(
                'SELECT limit_value FROM ue_job_resource_limits WHERE resource_class=? LIMIT 1'
            );
            $statement->execute([$resourceClass]);
            $value = $statement->fetchColumn();
            return $value === false ? $fallback : self::limit((int)$value);
        } catch (PDOException $error) {
            $sqlState = strtoupper((string)$error->getCode());
            $driverCode = is_array($error->errorInfo ?? null)
                ? (int)($error->errorInfo[1] ?? 0)
                : 0;
            if ($sqlState === '42S02' || $driverCode === 1146) {
                // Compatibility only: a pre-settings-schema database may not
                // have ue_job_resource_limits yet. Every other DB fault must
                // surface so workers do not silently run with the wrong limits.
                return $fallback;
            }
            throw $error;
        }
    }

    /**
     * Evaluate admission while the caller holds the resource/key locks.
     *
     * @return array{allowed:bool,resource_limit:int,blocked_dimension:?string}
     */
    public function decision(
        string $queue,
        string $resourceClass,
        int $resourceLimit,
        ?string $concurrencyKey
    ): array {
        $resourceClass = trim($resourceClass) !== '' ? trim($resourceClass) : 'default';
        $resourceLimit = $this->currentLimit($resourceClass, $resourceLimit);

        $running = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status="running" '
            . 'AND COALESCE(NULLIF(resource_class,""),"default")=?'
        );
        $running->execute([$queue, $resourceClass]);
        if ((int)$running->fetchColumn() >= $resourceLimit) {
            return [
                'allowed' => false,
                'resource_limit' => $resourceLimit,
                'blocked_dimension' => 'resource',
            ];
        }

        $concurrencyKey = trim((string)$concurrencyKey);
        if ($concurrencyKey !== '') {
            $key = $this->db->prepare(
                'SELECT 1 FROM ue_background_jobs '
                . 'WHERE queue_name=? AND status="running" AND concurrency_key=? LIMIT 1'
            );
            $key->execute([$queue, $concurrencyKey]);
            if ($key->fetchColumn() !== false) {
                return [
                    'allowed' => false,
                    'resource_limit' => $resourceLimit,
                    'blocked_dimension' => 'concurrency',
                ];
            }
        }

        return [
            'allowed' => true,
            'resource_limit' => $resourceLimit,
            'blocked_dimension' => null,
        ];
    }

    public function canRun(
        string $queue,
        string $resourceClass,
        int $resourceLimit,
        ?string $concurrencyKey
    ): bool {
        return $this->decision($queue, $resourceClass, $resourceLimit, $concurrencyKey)['allowed'];
    }

    private function environmentLimit(string $resourceClass, int $fallback): int
    {
        $fallback = self::limit($fallback);
        $name = 'UNREALDB_JOB_RESOURCE_LIMIT_' . strtoupper(str_replace('-', '_', $resourceClass));
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $fallback;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        return $value === false ? $fallback : self::limit((int)$value);
    }

    private function acquireNamedLock(string $lock): bool
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?,?)');
        $statement->execute([$lock, self::LOCK_WAIT_SECONDS]);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            throw new RuntimeException('MySQL did not return a valid admission-lock result.');
        }
        // GET_LOCK() returns 0 only for an ordinary timeout/contention case.
        // SQL/driver failures must propagate instead of masquerading as a block.
        return (int)$value === 1;
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
