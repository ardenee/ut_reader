<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds and executes the live Background Jobs list/count query with keyset pagination.
 * Why: HTTP endpoints should validate/serialize requests rather than construct persistence SQL.
 * Role: Infrastructure read model for the Background Jobs browser.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobCountCache;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

final class PdoBackgroundJobBrowserQuery
{
    private readonly PdoBackgroundJobSearchScope $searchScope;
    private readonly PdoBackgroundJobDisplayCountQuery $countQuery;
    private readonly PdoBackgroundJobPageQuery $pageQuery;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->searchScope = new PdoBackgroundJobSearchScope($db);
        $this->countQuery = new PdoBackgroundJobDisplayCountQuery($db);
        $this->pageQuery = new PdoBackgroundJobPageQuery($db);
    }

    /**
     * @param list<mixed>|null $cursor
     * @return array{
     *   rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,
     *   first_cursor:?array,last_cursor:?array,
     *   counts:array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int},total:int
     * }
     */
    public function fetch(
        string $queue,
        string $status,
        string $search,
        int $perPage,
        ?array $cursor,
        string $move
    ): array {
        $scope = $this->searchScope->build($queue, $search);
        $fromSql = $scope['from'];
        $baseWhereSql = $scope['where'];
        $baseParams = $scope['params'];
        $whereSql = $baseWhereSql;
        $params = $baseParams;

        if ($status !== '') {
            $condition = CatalogJobDisplayStatus::filterCondition($status, 'j');
            $whereSql = $whereSql !== ''
                ? '(' . $whereSql . ') AND ' . $condition['sql']
                : $condition['sql'];
            array_push($params, ...$condition['params']);
        }

        $countsCacheKey = json_encode(
            ['queue' => $queue, 'search' => $search],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $counts = (new CatalogBackgroundJobCountCache($this->config))->remember(
            $countsCacheKey,
            fn(): array => $this->countQuery->counts($fromSql, $baseWhereSql, $baseParams)
        );
        $totalKey = $status !== '' ? $status : 'all';
        $total = max(0, (int)($counts[$totalKey] ?? 0));

        $selectSql = 'SELECT j.id,j.queue_name,j.job_type,j.resource_class,j.resource_limit,j.concurrency_key,j.priority,j.status,'
            . 'j.display_status,j.available_at,j.attempts,j.max_attempts,j.worker_id,j.leased_at,j.lease_expires_at,'
            . 'j.last_heartbeat_at,j.recovery_count,j.cancel_requested_at,j.cancel_requested_by,j.cancel_reason,'
            . 'j.payload_json,j.progress_json,j.progress_updated_at,j.result_json,j.last_error,j.created_by,j.created_at,'
            . 'j.updated_at,j.completed_at,j.dead_lettered_at FROM ' . $fromSql;
        $page = $this->pageQuery->fetch($selectSql, $whereSql, $params, $perPage, $cursor, $move);
        return $page + ['counts' => $counts, 'total' => $total];
    }
}
