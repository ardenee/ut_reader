<?php
/**
 * Current-policy file-level dependency totals for the public Games page.
 *
 * One file contributes at most one to either count. A file with zero source
 * references is never "resolved". The owning game must already have passed
 * the exact per-engine v14/payload freshness gate before this reader is used.
 *
 * Aggregate results are cached by game and source statistics epoch; any new
 * dependency publication/statistics rebuild changes that epoch. Cache misses
 * are SELECT-only and cannot promote unknown provider mounts to resolved.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoGameDependencyFileCountQuery
{
    private const CACHE_VERSION = 1;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
    }

    /** @return array{unresolved_files:int,resolved_files:int}|null */
    public function forCurrentGame(int $gameId, string $engine, string $statsUpdatedAt): ?array
    {
        $engine = strtoupper(trim($engine));
        if ($gameId < 1 || !in_array($engine, ['UE1','UE2','UE3','UE4','UE5'], true)
            || trim($statsUpdatedAt) === '') {
            return null;
        }

        $signature = hash('sha256', implode('|', [
            (string)self::CACHE_VERSION, (string)$gameId, $engine, $statsUpdatedAt,
        ]));
        $directory = $this->cacheDirectory();
        if ($directory === null) {
            return $this->aggregate($gameId, $engine);
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'game-' . $gameId . '.json';
        $cached = $this->readCache($path, $signature);
        if ($cached !== null) {
            return $cached;
        }

        $lock = @fopen($path . '.lock', 'c+b');
        if (!is_resource($lock)) {
            return $this->aggregate($gameId, $engine);
        }
        try {
            if (!@flock($lock, LOCK_EX)) {
                return $this->aggregate($gameId, $engine);
            }
            $cached = $this->readCache($path, $signature);
            if ($cached !== null) {
                return $cached;
            }
            $counts = $this->aggregate($gameId, $engine);
            $this->writeCache($path, $signature, $counts);
            return $counts;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @return array{unresolved_files:int,resolved_files:int} */
    private function aggregate(int $gameId, string $engine): array
    {
        // Game-level publication freshness is checked in the list read model.
        // Classic package summaries cover UE1-UE4 and UE5 classic packages.
        $sql = 'SELECT COALESCE(SUM(x.unresolved>0),0) unresolved_files,'
            . 'COALESCE(SUM(x.unresolved=0 AND x.missing=0),0) resolved_files '
            . 'FROM (SELECT p.file_id,SUM(p.unresolved_count) unresolved,'
            . 'SUM(p.missing_count) missing '
            . 'FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
            . 'AND f.scan_status="verified" AND f.extension<>"zenpkg" '
            . 'WHERE p.game_id=? AND p.dependency_count>0 '
            . 'GROUP BY p.file_id) x';
        $statement = $this->db->prepare($sql);
        $statement->execute([$gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $counts = [
            'unresolved_files' => max(0, (int)($row['unresolved_files'] ?? 0)),
            'resolved_files' => max(0, (int)($row['resolved_files'] ?? 0)),
        ];

        if ($engine === 'UE5') {
            // Zen ScriptImport references have no required-package summary.
            // Use the full original published dependency edge stream for Zen.
            $zen = $this->db->prepare(
                'SELECT COALESCE(SUM(x.unresolved>0),0) unresolved_files,'
                . 'COALESCE(SUM(x.unresolved=0 AND x.missing=0),0) resolved_files '
                . 'FROM (SELECT e.file_id,SUM(e.outcome=4) unresolved,'
                . 'SUM(e.outcome=0) missing '
                . 'FROM ue_files f '
                . 'JOIN ue_uedb5_dependency_edges e ON e.file_id=f.id '
                . 'WHERE f.game_id=? AND f.scan_status="verified" '
                . 'AND f.extension="zenpkg" '
                . 'GROUP BY e.file_id) x'
            );
            $zen->execute([$gameId]);
            $zenCounts = $zen->fetch(PDO::FETCH_ASSOC) ?: [];
            $counts['unresolved_files'] += max(0, (int)($zenCounts['unresolved_files'] ?? 0));
            $counts['resolved_files'] += max(0, (int)($zenCounts['resolved_files'] ?? 0));
        }

        return $counts;
    }

    private function cacheDirectory(): ?string
    {
        $cache = is_array($this->config['cache'] ?? null) ? $this->config['cache'] : [];
        $base = trim((string)($cache['path'] ?? ''));
        if ($base === '') {
            $storage = trim((string)($this->config['storage_path'] ?? ''));
            if ($storage === '') {
                return null;
            }
            $base = rtrim($storage, '/\\') . DIRECTORY_SEPARATOR . 'cache';
        }
        $directory = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'game-dependency-files';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
        return is_writable($directory) ? $directory : null;
    }

    /** @return array{unresolved_files:int,resolved_files:int}|null */
    private function readCache(string $path, string $signature): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['signature'] ?? null) !== $signature
            || !is_array($decoded['counts'] ?? null)) {
            return null;
        }
        $counts = $decoded['counts'];
        if (!isset($counts['unresolved_files'], $counts['resolved_files'])
            || !is_int($counts['unresolved_files']) || !is_int($counts['resolved_files'])
            || $counts['unresolved_files'] < 0 || $counts['resolved_files'] < 0) {
            return null;
        }
        return [
            'unresolved_files' => $counts['unresolved_files'],
            'resolved_files' => $counts['resolved_files'],
        ];
    }

    /** @param array{unresolved_files:int,resolved_files:int} $counts */
    private function writeCache(string $path, string $signature, array $counts): void
    {
        try {
            $json = json_encode(['signature' => $signature, 'counts' => $counts], JSON_THROW_ON_ERROR);
            $temp = @tempnam(dirname($path), 'gamecounts-');
            if ($temp === false) {
                return;
            }
            try {
                if (@file_put_contents($temp, $json) === false) {
                    return;
                }
                // Windows rename cannot replace an existing destination.
                if (is_file($path)) {
                    @unlink($path);
                }
                @rename($temp, $path);
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
        } catch (Throwable) {
            // Cache failure must not alter the freshly computed SQL counts.
        }
    }
}
