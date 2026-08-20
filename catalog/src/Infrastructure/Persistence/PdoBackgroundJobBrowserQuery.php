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
     *   counts:array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int,partial_archive:int},total:int
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
        // Retained archives are always completed top-level archive jobs. Do not
        // discover that small recovery set by materialising the generic
        // root/problem-child UNION and then applying the archive predicate on top.
        // That query shape can become disproportionately expensive after large
        // workflows have left a deep child ledger. The normal count query is kept
        // so every tab still reports the same authoritative totals.
        if ($status === 'partial_archive' && $search === '') {
            return $this->fetchRetainedArchives($queue, $perPage, $cursor, $move);
        }

        $scope = $this->searchScope->build($queue, $search);
        $fromSql = $scope['from'];
        $baseWhereSql = $scope['where'];
        $baseParams = $scope['params'];
        $whereSql = $baseWhereSql;
        $params = $baseParams;

        $operatorStatusSql = BackgroundJobDisplaySql::operatorStatus('j');
        $operatorStartedSql = BackgroundJobDisplaySql::operatorStartedAt('j');

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

    /**
     * @param list<mixed>|null $cursor
     * @return array{
     *   rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,
     *   first_cursor:?array,last_cursor:?array,
     *   counts:array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int,partial_archive:int},total:int
     * }
     */
    private function fetchRetainedArchives(
        string $queue,
        int $perPage,
        ?array $cursor,
        string $move
    ): array {
        $countScope = $this->searchScope->build($queue, '');
        $counts = $this->countQuery->counts(
            $countScope['from'],
            $countScope['where'],
            $countScope['params']
        );
        $total = max(0, (int)($counts['partial_archive'] ?? 0));

        $where = ['j.parent_job_id IS NULL'];
        $params = [];
        if ($queue !== '') {
            $where[] = 'j.queue_name=?';
            $params[] = $queue;
        }
        $condition = CatalogJobDisplayStatus::filterCondition('partial_archive', 'j');
        $where[] = $condition['sql'];
        array_push($params, ...$condition['params']);

        // These are completed roots, so their operator status/start timestamp do
        // not require the correlated child-state expressions used by live roots.
        $selectSql = 'SELECT j.id,j.parent_job_id,j.workflow_unit_key,j.queue_name,j.job_type,j.resource_class,j.resource_limit,j.concurrency_key,j.priority,j.status,'
            . 'j.status AS operator_status,j.created_at AS operator_started_at,'
            . 'j.display_status,j.available_at,j.attempts,j.max_attempts,j.worker_id,j.leased_at,j.lease_expires_at,'
            . 'j.last_heartbeat_at,j.recovery_count,j.cancel_requested_at,j.cancel_requested_by,j.cancel_reason,'
            . 'j.payload_json,j.progress_json,j.progress_updated_at,j.result_json,j.last_error,j.created_by,j.created_at,'
            . 'j.updated_at,j.completed_at,j.dead_lettered_at FROM ue_background_jobs j';
        $page = $this->pageQuery->fetch(
            $selectSql,
            implode(' AND ', $where),
            $params,
            $perPage,
            $cursor,
            $move
        );
        return $page + ['counts' => $counts, 'total' => $total];
    }
}
