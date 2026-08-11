<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Persists and reads cached exact per-game dependency evidence for unverified files.
 * Why: Unverified Files must not calculate dependency/object-path matches synchronously on every page request.
 * Role: Infrastructure projection store populated by background jobs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use JsonException;
use PDO;
use Throwable;

final class PdoUnverifiedGameMatchCache
{
    public const VERSION = 1;

    private ?bool $available = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        try {
            $statement = $this->db->query('SELECT 1 FROM ue_unverified_game_match_cache LIMIT 0');
            return $this->available = $statement !== false;
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    /**
     * @param list<int> $fileIds
     * @return array{matches:array<int,list<array<string,mixed>>>,states:array<int,array<string,mixed>>}
     */
    public function read(array $fileIds): array
    {
        $ids = $this->ids($fileIds);
        $matches = [];
        $states = [];
        foreach ($ids as $fileId) {
            $matches[$fileId] = [];
            $states[$fileId] = [
                'status' => 'missing',
                'calculated_at' => null,
                'updated_at' => null,
                'last_error' => null,
                'match_count' => 0,
                'exact_compatible_game_count' => 0,
                'cache_version' => 0,
            ];
        }
        if ($ids === [] || !$this->available()) {
            return ['matches' => $matches, 'states' => $states];
        }

        $statement = $this->db->prepare(
            'SELECT file_id,cache_version,status,matches_json,match_count,exact_compatible_game_count,'
            . 'last_error,calculated_at,updated_at FROM ue_unverified_game_match_cache '
            . 'WHERE file_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $fileId = (int)$row['file_id'];
            $decoded = [];
            $json = trim((string)($row['matches_json'] ?? ''));
            if ($json !== '') {
                try {
                    $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($value)) {
                        $decoded = array_values(array_filter($value, 'is_array'));
                    }
                } catch (JsonException) {
                    $decoded = [];
                }
            }
            $matches[$fileId] = $decoded;
            $states[$fileId] = [
                'status' => (string)$row['status'],
                'calculated_at' => $row['calculated_at'] !== null ? (string)$row['calculated_at'] : null,
                'updated_at' => (string)$row['updated_at'],
                'last_error' => $row['last_error'] !== null ? (string)$row['last_error'] : null,
                'match_count' => (int)$row['match_count'],
                'exact_compatible_game_count' => (int)$row['exact_compatible_game_count'],
                'cache_version' => (int)$row['cache_version'],
            ];
        }
        return ['matches' => $matches, 'states' => $states];
    }

    public function markPending(int $fileId): void
    {
        if ($fileId < 1 || !$this->available()) {
            return;
        }
        $statement = $this->db->prepare(
            'INSERT INTO ue_unverified_game_match_cache '
            . '(file_id,cache_version,status,matches_json,match_count,exact_compatible_game_count,last_error,calculated_at) '
            . 'VALUES (?, ?, "pending", NULL, 0, 0, NULL, NULL) '
            . 'ON DUPLICATE KEY UPDATE cache_version=VALUES(cache_version),status="pending",last_error=NULL'
        );
        $statement->execute([$fileId, self::VERSION]);
    }

    /** @param list<array<string,mixed>> $matches */
    public function storeReady(int $fileId, array $matches): void
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('A positive unverified file ID is required.');
        }
        if (!$this->available()) {
            throw new \RuntimeException(
                'Unverified game-match cache table is unavailable. Run catalog/bin/migrate.php migrate.'
            );
        }

        $visible = [];
        $exactCompatible = 0;
        foreach ($matches as $match) {
            if (!is_array($match) || (int)($match['import_count'] ?? 0) < 1) {
                continue;
            }
            $visible[] = $match;
            if (!empty($match['compatible']) && (int)($match['exact_object_matches'] ?? 0) > 0) {
                $exactCompatible++;
            }
        }
        try {
            $json = json_encode(
                $visible,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (JsonException $error) {
            throw new \RuntimeException('Could not encode cached unverified game matches: ' . $error->getMessage(), 0, $error);
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_unverified_game_match_cache '
            . '(file_id,cache_version,status,matches_json,match_count,exact_compatible_game_count,last_error,calculated_at) '
            . 'VALUES (?, ?, "ready", ?, ?, ?, NULL, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE cache_version=VALUES(cache_version),status="ready",matches_json=VALUES(matches_json),'
            . 'match_count=VALUES(match_count),exact_compatible_game_count=VALUES(exact_compatible_game_count),'
            . 'last_error=NULL,calculated_at=VALUES(calculated_at)'
        );
        $statement->execute([$fileId, self::VERSION, $json, count($visible), $exactCompatible]);
    }

    public function storeFailed(int $fileId, Throwable|string $error): void
    {
        if ($fileId < 1 || !$this->available()) {
            return;
        }
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if (function_exists('mb_substr')) {
            $message = mb_substr($message, 0, 1000, 'UTF-8');
        } else {
            $message = substr($message, 0, 1000);
        }
        $statement = $this->db->prepare(
            'INSERT INTO ue_unverified_game_match_cache '
            . '(file_id,cache_version,status,matches_json,match_count,exact_compatible_game_count,last_error,calculated_at) '
            . 'VALUES (?, ?, "failed", NULL, 0, 0, ?, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE cache_version=VALUES(cache_version),status="failed",last_error=VALUES(last_error),'
            . 'calculated_at=VALUES(calculated_at)'
        );
        $statement->execute([$fileId, self::VERSION, $message !== '' ? $message : 'Unknown match calculation error']);
    }

    public function purgeNonUnverified(): int
    {
        if (!$this->available()) {
            return 0;
        }
        $statement = $this->db->prepare(
            'DELETE c FROM ue_unverified_game_match_cache c '
            . 'JOIN ue_files f ON f.id=c.file_id WHERE f.scan_status<>"unverified"'
        );
        $statement->execute();
        return $statement->rowCount();
    }

    /** @return array{ready:int,pending:int,failed:int,missing:int,total:int} */
    public function bucketSummary(): array
    {
        $totalRow = $this->db->query(
            'SELECT COUNT(*) c FROM ue_files WHERE scan_status="unverified" AND unverified_queue_game_id=0'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($totalRow['c'] ?? 0);
        if (!$this->available()) {
            return ['ready' => 0, 'pending' => 0, 'failed' => 0, 'missing' => $total, 'total' => $total];
        }
        $row = $this->db->query(
            'SELECT '
            . 'SUM(c.status="ready") ready_count,SUM(c.status="pending") pending_count,SUM(c.status="failed") failed_count,'
            . 'COUNT(c.file_id) cached_count '
            . 'FROM ue_files f LEFT JOIN ue_unverified_game_match_cache c ON c.file_id=f.id '
            . 'WHERE f.scan_status="unverified" AND f.unverified_queue_game_id=0'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $cached = (int)($row['cached_count'] ?? 0);
        return [
            'ready' => (int)($row['ready_count'] ?? 0),
            'pending' => (int)($row['pending_count'] ?? 0),
            'failed' => (int)($row['failed_count'] ?? 0),
            'missing' => max(0, $total - $cached),
            'total' => $total,
        ];
    }

    /** @param list<int> $fileIds @return list<int> */
    private function ids(array $fileIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0
        )));
    }
}
