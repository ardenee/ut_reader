<?php
/**
 * Coalesces expensive cached game-stat rebuilds produced by bursty import work.
 *
 * Dependency correctness remains synchronous. Cached dashboard/game counters are
 * refreshed by one low-priority game-level job after a short quiet window rather
 * than rescanning the whole game once for every PAK that completes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogGameStatsRefreshCoordinator
{
    private const QUIET_SECONDS = 20;
    private const MAX_DEBOUNCE_SECONDS = 90;
    private const PRIORITY = 90;

    public static function request(
        PDO $db,
        string $queue,
        int $gameId,
        ?int $requestedBy = null
    ): int {
        $queue = trim($queue);
        if ($queue === '' || $gameId < 1) {
            throw new RuntimeException('Game-stat refresh requires a queue and positive game id.');
        }

        $slot0 = self::dedupeKey($gameId, 0);
        $slot1 = self::dedupeKey($gameId, 1);
        $lockName = 'unrealdb_stats_request_' . substr(hash('sha256', $queue . ':' . $gameId), 0, 32);
        $lock = $db->prepare('SELECT GET_LOCK(?,5)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the game-stat refresh scheduling lock.');
        }

        try {
            $statement = $db->prepare(
                'SELECT id,status,dedupe_key,created_at,available_at FROM ue_background_jobs '
                . 'WHERE queue_name=? AND job_type=? AND dedupe_key IN (?,?) '
                . 'AND status IN ("queued","running") '
                . 'ORDER BY (status="queued") DESC,id DESC'
            );
            $statement->execute([$queue, JobType::REBUILD_GAME_DEPENDENCIES, $slot0, $slot1]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                if ((string)($row['status'] ?? '') === 'queued') {
                    self::postponeQueued($db, $row);
                    return (int)$row['id'];
                }
            }

            $runningKey = '';
            foreach ($rows as $row) {
                if ((string)($row['status'] ?? '') === 'running') {
                    $runningKey = (string)($row['dedupe_key'] ?? '');
                    break;
                }
            }

            $dedupeKey = $runningKey === $slot0 ? $slot1 : $slot0;
            $availableAt = self::utcNow()->modify('+' . self::QUIET_SECONDS . ' seconds');
            return (new PdoJobQueue($db))->enqueue(
                $queue,
                JobType::REBUILD_GAME_DEPENDENCIES,
                [
                    'game_id' => $gameId,
                    'game_stats_only' => true,
                    'requested_by' => $requestedBy,
                ],
                self::PRIORITY,
                $availableAt,
                $dedupeKey,
                $requestedBy,
                3
            );
        } finally {
            try {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (\Throwable) {
                // Connection close also releases the advisory lock.
            }
        }
    }

    /** @param array<string,mixed> $row */
    private static function postponeQueued(PDO $db, array $row): void
    {
        $id = (int)($row['id'] ?? 0);
        $createdText = trim((string)($row['created_at'] ?? ''));
        if ($id < 1 || $createdText === '') {
            return;
        }

        try {
            $created = new DateTimeImmutable($createdText, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return;
        }

        $now = self::utcNow();
        $quietTarget = $now->modify('+' . self::QUIET_SECONDS . ' seconds');
        $latestTarget = $created->modify('+' . self::MAX_DEBOUNCE_SECONDS . ' seconds');
        $target = $quietTarget < $latestTarget ? $quietTarget : $latestTarget;
        if ($target <= $now) {
            return;
        }

        $update = $db->prepare(
            'UPDATE ue_background_jobs SET available_at=?,updated_at=UTC_TIMESTAMP() '
            . 'WHERE id=? AND status="queued"'
        );
        $update->execute([$target->format('Y-m-d H:i:s'), $id]);
    }

    private static function dedupeKey(int $gameId, int $slot): string
    {
        return 'game-stats:' . $gameId . ':' . ($slot === 1 ? '1' : '0');
    }

    private static function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
