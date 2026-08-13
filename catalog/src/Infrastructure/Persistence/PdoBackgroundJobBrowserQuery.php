<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds and executes the live Background Jobs list/count query with keyset pagination.
 * Why: The operator page reports stable parent jobs while internal workflow units roll up into parent progress/status.
 * Role: Infrastructure read model for the Background Jobs browser.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
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

        // "In progress" is deliberately tied to worker ownership. A parent is
        // active if it is itself claimed or one of its child units is claimed.
        // A coordinator that merely has queued/completed children is Waiting.
        $hasWorkflowSql = 'EXISTS(SELECT 1 FROM ue_background_jobs job_child WHERE job_child.parent_job_id=j.id LIMIT 1)';
        $hasRunningChildSql = 'EXISTS(SELECT 1 FROM ue_background_jobs running_child '
            . 'WHERE running_child.parent_job_id=j.id AND running_child.status="running" LIMIT 1)';
        $operatorStatusSql = 'CASE '
            . 'WHEN j.status="running" THEN "running" '
            . 'WHEN j.parent_job_id IS NULL AND j.status="queued" AND ' . $hasRunningChildSql . ' THEN "running" '
            . 'ELSE j.status END';

        // Workflow parent elapsed time is intentionally stable across coordinator
        // yields/reclaims. The separate Currently working panel reports the exact
        // claim duration of each worker-owned child/task.
        $operatorStartedSql = 'CASE WHEN ' . $hasWorkflowSql . ' THEN j.created_at ELSE j.leased_at END';

        if ($status !== '') {
            if (in_array($status, ['queued', 'running'], true)) {
                $conditionSql = $operatorStatusSql . '=?';
                $conditionParams = [$status];
            } else {
                $condition = CatalogJobDisplayStatus::filterCondition($status, 'j');
                $conditionSql = $condition['sql'];
                $conditionParams = $condition['params'];
            }
            $whereSql = $whereSql !== ''
                ? '(' . $whereSql . ') AND ' . $conditionSql
                : $conditionSql;
            array_push($params, ...$conditionParams);
        }

        // Parent-only counts are cheap enough to keep live with the rows.
        $counts = $this->countQuery->counts($fromSql, $baseWhereSql, $baseParams);
        $totalKey = $status !== '' ? $status : 'all';
        $total = max(0, (int)($counts[$totalKey] ?? 0));

        $selectSql = 'SELECT j.id,j.parent_job_id,j.workflow_unit_key,j.queue_name,j.job_type,j.resource_class,j.resource_limit,j.concurrency_key,j.priority,j.status,'
            . $operatorStatusSql . ' AS operator_status,' . $operatorStartedSql . ' AS operator_started_at,'
            . 'j.display_status,j.available_at,j.attempts,j.max_attempts,j.worker_id,j.leased_at,j.lease_expires_at,'
            . 'j.last_heartbeat_at,j.recovery_count,j.cancel_requested_at,j.cancel_requested_by,j.cancel_reason,'
            . 'j.payload_json,j.progress_json,j.progress_updated_at,j.result_json,j.last_error,j.created_by,j.created_at,'
            . 'j.updated_at,j.completed_at,j.dead_lettered_at FROM ' . $fromSql;
        $page = $this->pageQuery->fetch($selectSql, $whereSql, $params, $perPage, $cursor, $move);
        return $page + ['counts' => $counts, 'total' => $total];
    }
}
