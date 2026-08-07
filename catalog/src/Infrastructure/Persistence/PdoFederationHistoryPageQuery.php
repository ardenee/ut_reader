<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes bounded federation history page queries through PDO.
 * Why: Database execution belongs in Infrastructure while Application owns cursor and page-window policy.
 * Role: PDO adapter for federation history pagination used by pages and signed federation APIs.
 * Audit: Preserve SQL, argument order and result shape; pagination policy belongs in CatalogFederationHistoryPageService.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Federation\CatalogFederationHistoryPageService;

final class PdoFederationHistoryPageQuery
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array<string,mixed> $config
     * @param list<mixed> $args
     * @param list<string> $sortColumns
     * @param list<string> $cursorKeys
     * @param list<string> $directions
     * @return array{rows:list<array<string,mixed>>,page_size:int,has_previous:bool,has_next:bool,previous_cursor:string,next_cursor:string,move:string}
     */
    public function fetch(
        array $config,
        string $context,
        string $selectFromSql,
        string $whereSql,
        array $args,
        array $sortColumns,
        array $cursorKeys,
        array $directions,
        int $pageSize,
        string $cursor,
        string $move
    ): array {
        $plan = CatalogFederationHistoryPageService::plan(
  $config,
  $context,
  $whereSql,
  $args,
  $sortColumns,
  $cursorKeys,
  $directions,
  $pageSize,
  $cursor,
  $move
        );

        $sql = $selectFromSql
  . ($plan['where_sql'] !== '' ? ' WHERE ' . $plan['where_sql'] : '')
  . ' ORDER BY ' . $plan['order_sql']
  . ' LIMIT ' . ($plan['page_size'] + 1);
        $statement = $this->db->prepare($sql);
        $statement->execute($plan['args']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];

        return CatalogFederationHistoryPageService::finish($config, $plan, $rows);
    }
}
