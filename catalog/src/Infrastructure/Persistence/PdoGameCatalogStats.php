<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `PdoGameCatalogStats` for PDO game catalog stats.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Maintains compact per-game counters used by dashboard, library and game lists.
 * Authoritative file and dependency tables remain the source of truth.
 */
final class PdoGameCatalogStats
{
    /** @var array<int,bool> */
    private static array $availability = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function available(): bool
    {
        $connectionId = spl_object_id($this->db);
        if (array_key_exists($connectionId, self::$availability)) {
            return self::$availability[$connectionId];
        }

        try {
            $statement = $this->db->query('SELECT 1 FROM ue_game_catalog_stats LIMIT 0');
            self::$availability[$connectionId] = $statement !== false;
        } catch (Throwable) {
            self::$availability[$connectionId] = false;
        }

        return self::$availability[$connectionId];
    }

    /** @return array<string,int>|null */
    public function rebuildGame(int $gameId): ?array
    {
        if ($gameId < 1 || !$this->available()) {
            return null;
        }

        $lockName = 'unrealdb_game_stats_' . $gameId;
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return null;
        }

        try {
            $exists = $this->db->prepare('SELECT id FROM ue_games WHERE id=?');
            $exists->execute([$gameId]);
            if ($exists->fetchColumn() === false) {
                return null;
            }

            $fileStatement = $this->db->prepare(
                'SELECT COUNT(*) file_count,'
                . 'COALESCE(SUM(scan_status="verified"),0) verified_count,'
                . 'COALESCE(SUM(scan_status="failed"),0) failed_count,'
                . 'COALESCE(SUM(scan_status="duplicate"),0) duplicate_count,'
                . 'COALESCE(SUM(scan_status="unverified"),0) unverified_count,'
                . 'COALESCE(SUM(file_size),0) total_size,'
                . 'COALESCE(SUM(CASE WHEN scan_status="verified" THEN file_size ELSE 0 END),0) verified_size '
                . 'FROM ue_files WHERE game_id=?'
            );
            $fileStatement->execute([$gameId]);
            $files = $fileStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $dependencyStatement = $this->db->prepare(
                'SELECT COALESCE(SUM(dependency_count),0) dependency_count,'
                . 'COALESCE(SUM(missing_count),0) missing_dependency_count,'
                . 'COALESCE(SUM(resolved_count),0) resolved_dependency_count,'
                . 'COALESCE(SUM(package_only_count),0) package_only_dependency_count,'
                . 'COALESCE(SUM(common_count),0) common_dependency_count,'
                . 'COUNT(DISTINCT CASE WHEN missing_count>0 THEN required_package END) missing_package_count '
                . 'FROM ue_dependency_package_summaries WHERE game_id=?'
            );
            $dependencyStatement->execute([$gameId]);
            $dependencies = $dependencyStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $baseGameStatement = $this->db->prepare(
                'SELECT COALESCE(SUM(s.missing_count),0) missing_base_game_dependency_count '
                . 'FROM ue_dependency_package_summaries s '
                . 'WHERE s.game_id=? AND s.missing_count>0 AND EXISTS ('
                . 'SELECT 1 FROM ue_base_game_files bg '
                . 'LEFT JOIN ue_files src ON src.id=bg.source_file_id '
                . 'WHERE bg.game_id=s.game_id AND ('
                . 'LOWER(TRIM(COALESCE(bg.package_name,"")))=LOWER(TRIM(s.required_package)) '
                . 'OR LOWER(TRIM(CASE WHEN LOCATE(".",COALESCE(bg.original_name,""))>0 '
                . 'THEN LEFT(bg.original_name,CHAR_LENGTH(bg.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(bg.original_name,".",-1))-1) '
                . 'ELSE COALESCE(bg.original_name,"") END))=LOWER(TRIM(s.required_package)) '
                . 'OR LOWER(TRIM(COALESCE(src.package_name,"")))=LOWER(TRIM(s.required_package)) '
                . 'OR LOWER(TRIM(CASE WHEN LOCATE(".",COALESCE(src.original_name,""))>0 '
                . 'THEN LEFT(src.original_name,CHAR_LENGTH(src.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(src.original_name,".",-1))-1) '
                . 'ELSE COALESCE(src.original_name,"") END))=LOWER(TRIM(s.required_package))'
                . '))'
            );
            $baseGameStatement->execute([$gameId]);
            $baseGame = $baseGameStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $row = [
                'game_id' => $gameId,
                'file_count' => (int)($files['file_count'] ?? 0),
                'verified_count' => (int)($files['verified_count'] ?? 0),
                'failed_count' => (int)($files['failed_count'] ?? 0),
                'duplicate_count' => (int)($files['duplicate_count'] ?? 0),
                'unverified_count' => (int)($files['unverified_count'] ?? 0),
                'total_size' => (int)($files['total_size'] ?? 0),
                'verified_size' => (int)($files['verified_size'] ?? 0),
                'dependency_count' => (int)($dependencies['dependency_count'] ?? 0),
                'missing_dependency_count' => (int)($dependencies['missing_dependency_count'] ?? 0),
                'resolved_dependency_count' => (int)($dependencies['resolved_dependency_count'] ?? 0),
                'package_only_dependency_count' => (int)($dependencies['package_only_dependency_count'] ?? 0),
                'common_dependency_count' => (int)($dependencies['common_dependency_count'] ?? 0),
                'missing_package_count' => (int)($dependencies['missing_package_count'] ?? 0),
                'missing_base_game_dependency_count' => (int)($baseGame['missing_base_game_dependency_count'] ?? 0),
            ];

            $statement = $this->db->prepare(
                'INSERT INTO ue_game_catalog_stats('
                . 'game_id,file_count,verified_count,failed_count,duplicate_count,unverified_count,total_size,verified_size,'
                . 'dependency_count,missing_dependency_count,resolved_dependency_count,package_only_dependency_count,'
                . 'common_dependency_count,missing_package_count,missing_base_game_dependency_count,updated_at'
                . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'file_count=VALUES(file_count),verified_count=VALUES(verified_count),failed_count=VALUES(failed_count),'
                . 'duplicate_count=VALUES(duplicate_count),unverified_count=VALUES(unverified_count),'
                . 'total_size=VALUES(total_size),verified_size=VALUES(verified_size),'
                . 'dependency_count=VALUES(dependency_count),missing_dependency_count=VALUES(missing_dependency_count),'
                . 'resolved_dependency_count=VALUES(resolved_dependency_count),'
                . 'package_only_dependency_count=VALUES(package_only_dependency_count),'
                . 'common_dependency_count=VALUES(common_dependency_count),missing_package_count=VALUES(missing_package_count),'
                . 'missing_base_game_dependency_count=VALUES(missing_base_game_dependency_count),updated_at=NOW()'
            );
            $statement->execute(array_values($row));
            return $row;
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // The connection closing also releases the advisory lock.
            }
        }
    }

    public function rebuildAll(): int
    {
        if (!$this->available()) {
            return 0;
        }
        $ids = $this->db->query('SELECT id FROM ue_games ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $rebuilt = 0;
        foreach ($ids as $id) {
            if ($this->rebuildGame((int)$id) !== null) {
                $rebuilt++;
            }
        }
        return $rebuilt;
    }

    public function refreshStale(int $maxAgeSeconds = 300): int
    {
        if (!$this->available()) {
            return 0;
        }
        $threshold = (new DateTimeImmutable())->modify('-' . max(30, $maxAgeSeconds) . ' seconds')->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'SELECT g.id FROM ue_games g LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id '
            . 'WHERE s.game_id IS NULL OR s.updated_at<? ORDER BY g.id'
        );
        $statement->execute([$threshold]);
        $rebuilt = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $gameId) {
            if ($this->rebuildGame((int)$gameId) !== null) {
                $rebuilt++;
            }
        }
        return $rebuilt;
    }

    /** @return array<string,int> */
    public function global(): array
    {
        if (!$this->available()) {
            return [];
        }

        $cached = $this->db->query(
            'SELECT COALESCE(SUM(file_count),0) file_count,COALESCE(SUM(verified_count),0) verified_count,'
            . 'COALESCE(SUM(failed_count),0) failed_count,COALESCE(SUM(duplicate_count),0) duplicate_count,'
            . 'COALESCE(SUM(unverified_count),0) unverified_count,COALESCE(SUM(total_size),0) total_size,'
            . 'COALESCE(SUM(verified_size),0) verified_size,COALESCE(SUM(dependency_count),0) dependency_count,'
            . 'COALESCE(SUM(missing_dependency_count),0) missing_dependency_count,'
            . 'COALESCE(SUM(resolved_dependency_count),0) resolved_dependency_count,'
            . 'COALESCE(SUM(package_only_dependency_count),0) package_only_dependency_count,'
            . 'COALESCE(SUM(common_dependency_count),0) common_dependency_count,'
            . 'COALESCE(SUM(missing_package_count),0) missing_package_count,'
            . 'COALESCE(SUM(missing_base_game_dependency_count),0) missing_base_game_dependency_count '
            . 'FROM ue_game_catalog_stats'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $unassigned = $this->db->query(
            'SELECT COUNT(*) file_count,COALESCE(SUM(scan_status="verified"),0) verified_count,'
            . 'COALESCE(SUM(scan_status="failed"),0) failed_count,COALESCE(SUM(scan_status="duplicate"),0) duplicate_count,'
            . 'COALESCE(SUM(scan_status="unverified"),0) unverified_count,COALESCE(SUM(file_size),0) total_size,'
            . 'COALESCE(SUM(CASE WHEN scan_status="verified" THEN file_size ELSE 0 END),0) verified_size '
            . 'FROM ue_files WHERE game_id IS NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach (['file_count','verified_count','failed_count','duplicate_count','unverified_count','total_size','verified_size'] as $key) {
            $cached[$key] = (int)($cached[$key] ?? 0) + (int)($unassigned[$key] ?? 0);
        }
        foreach ($cached as $key => $value) {
            $cached[$key] = (int)$value;
        }
        return $cached;
    }
}
