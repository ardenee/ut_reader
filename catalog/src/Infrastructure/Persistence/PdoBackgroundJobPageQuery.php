<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes cursor-based background-job page reads through PDO.
 * Why: It keeps SQL execution and PDO ownership in Infrastructure while reusing the Application pagination policy.
 * Role: Persistence adapter for the background-job cursor status API.
 * Audit: Preserve query semantics and delegate pagination decisions to CatalogBackgroundJobPageService.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Jobs\CatalogBackgroundJobPageService;

/** Executes stable background-job pages without OFFSET scans. */
final class PdoBackgroundJobPageQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<mixed> $params
     * @param list<mixed>|null $cursor
     * @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array}
     */
    public function fetch(
        string $selectSql,
        string $whereSql,
        array $params,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $window = CatalogBackgroundJobPageService::window($limit, $move);
        $limit = $window['limit'];
        $move = $window['move'];
        $reverse = $window['reverse'];
        $conditions = trim($whereSql);
        $args = $params;

        // A reversed LIMIT normally returns a full final window. When the total
        // is not divisible by the selected page size that would overlap the
        // preceding page. Count only for an explicit Last request and reduce
        // the read to the exact remainder.
        if ($move === 'last') {
            $countSql = 'SELECT COUNT(*) FROM (' . $selectSql
                . ($conditions !== '' ? ' WHERE ' . $conditions : '')
                . ') background_job_cursor_count';
            $count = $this->db->prepare($countSql);
            $count->execute($args);
            $limit = CatalogBackgroundJobPageService::lastPageLimit(
                $limit,
                (int)$count->fetchColumn(),
                $move
            );
        }

        $comparison = CatalogBackgroundJobPageService::cursorComparison($cursor, $move);
        if ($comparison !== null) {
            $conditions = $conditions === ''
                ? $comparison['sql']
                : '(' . $conditions . ') AND ' . $comparison['sql'];
            array_push($args, ...$comparison['args']);
        }

        $sql = $selectSql;
        if ($conditions !== '') {
            $sql .= ' WHERE ' . $conditions;
        }
        $sql .= ' ORDER BY ' . CatalogBackgroundJobPageService::order($reverse)
            . ' LIMIT ' . ($limit + 1);

        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return CatalogBackgroundJobPageService::finish(
            is_array($rows) ? array_values($rows) : [],
            $limit,
            $move,
            $reverse
        );
    }
}
