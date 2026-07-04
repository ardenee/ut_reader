<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

final class CatalogSearchUnavailableException extends RuntimeException
{
}

/**
 * Read-only global catalog search.
 *
 * The legacy query combined broad file predicates with correlated EXISTS
 * subqueries. This service executes each matching domain independently,
 * bounds it to the visible result limit, de-duplicates IDs, then performs one
 * final ordered file lookup. Exact hash candidates are isolated so MySQL can
 * use their dedicated global indexes. The import/export candidate queries group
 * by file so one package containing many matching objects cannot consume the
 * entire candidate limit.
 */
final class CatalogSearchService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findFiles(PDO $db, string $query, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $like = '%' . $query . '%';
        $candidateIds = [];

        self::collectIds(
            $db,
            'hash_md5',
            'SELECT f.id FROM ue_files f WHERE f.md5=? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$query],
            $candidateIds
        );
        self::collectIds(
            $db,
            'hash_sha1',
            'SELECT f.id FROM ue_files f WHERE f.sha1=? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$query],
            $candidateIds
        );
        self::collectIds(
            $db,
            'guid',
            'SELECT f.id FROM ue_files f WHERE f.package_guid LIKE ? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like],
            $candidateIds
        );
        self::collectIds(
            $db,
            'file_metadata',
            'SELECT f.id FROM ue_files f WHERE f.package_name LIKE ? OR f.original_name LIKE ? ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like, $like],
            $candidateIds
        );
        self::collectIds(
            $db,
            'imports',
            'SELECT f.id, f.package_name, f.original_name'
            . ' FROM ue_imports i JOIN ue_files f ON f.id=i.file_id'
            . ' WHERE i.full_path LIKE ? OR i.object_name LIKE ?'
            . ' GROUP BY f.id, f.package_name, f.original_name'
            . ' ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like, $like],
            $candidateIds
        );
        self::collectIds(
            $db,
            'exports',
            'SELECT f.id, f.package_name, f.original_name'
            . ' FROM ue_exports e JOIN ue_files f ON f.id=e.file_id'
            . ' WHERE e.full_path LIKE ? OR e.object_name LIKE ?'
            . ' GROUP BY f.id, f.package_name, f.original_name'
            . ' ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            [$like, $like],
            $candidateIds
        );

        if ($candidateIds === []) {
            return [];
        }

        $ids = array_keys($candidateIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return self::queryRows(
            $db,
            'final_file_lookup',
            'SELECT f.* FROM ue_files f WHERE f.id IN (' . $placeholders . ') ORDER BY f.package_name, f.original_name LIMIT ' . $limit,
            $ids
        );
    }

    /**
     * @param list<mixed> $args
     * @param array<int, true> $candidateIds
     */
    private static function collectIds(PDO $db, string $stage, string $sql, array $args, array &$candidateIds): void
    {
        foreach (self::queryRows($db, $stage, $sql, $args) as $row) {
            $candidateIds[(int)$row['id']] = true;
        }
    }

    /**
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private static function queryRows(PDO $db, string $stage, string $sql, array $args): array
    {
        $startedAt = hrtime(true);
        try {
            $rows = catalog_all($db, $sql, $args);
        } catch (PDOException $e) {
            self::logFailure($stage, $startedAt, $e);
            throw new CatalogSearchUnavailableException('The search service is temporarily unavailable.', 0, $e);
        }

        $elapsedMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
        if ($elapsedMs >= 1000) {
            error_log('[UnrealDB search] stage=' . $stage . ' elapsed_ms=' . $elapsedMs . ' result_rows=' . count($rows));
        }

        return $rows;
    }

    private static function logFailure(string $stage, int $startedAt, PDOException $e): void
    {
        $elapsedMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
        error_log(
            '[UnrealDB search] stage=' . $stage
            . ' elapsed_ms=' . $elapsedMs
            . ' sqlstate=' . (string)$e->getCode()
            . ' message=' . $e->getMessage()
        );
    }
}
