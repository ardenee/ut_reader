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
use UnrealDb\Catalog\Domain\Jobs\JobType;
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
        // The retained view also uses direct queue counts below so opening this
        // operator recovery page can never be held hostage by a deep child ledger.
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
        $counts = $this->fastQueueCounts($queue);
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

    /**
     * Direct count path for the no-search retained-archive view.
     *
     * The generic browser count intentionally supports arbitrary search scopes,
     * but it must materialise the root/problem-child read model and evaluate
     * operator-state expressions across it. For the recovery page we already know
     * there is no search term, so count roots and visible problem children directly
     * from their indexed table predicates instead.
     *
     * @return array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int,partial_archive:int}
     */
    private function fastQueueCounts(string $queue): array
    {
        $counts = [
            'all' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'dead_letter' => 0,
            'cancelled' => 0,
            'partial_archive' => 0,
        ];

        $queueSql = '';
        $params = [];
        if ($queue !== '') {
            $queueSql = ' AND j.queue_name=?';
            $params[] = $queue;
        }

        $root = $this->db->prepare(
            'SELECT j.status,j.display_status,j.job_type,COUNT(*) AS total,'
            . 'SUM(CASE WHEN j.status="queued" AND EXISTS('
            . 'SELECT 1 FROM ue_background_jobs running_child '
            . 'WHERE running_child.parent_job_id=j.id AND running_child.status="running" LIMIT 1'
            . ') THEN 1 ELSE 0 END) AS queued_running '
            . 'FROM ue_background_jobs j WHERE j.parent_job_id IS NULL' . $queueSql . ' '
            . 'GROUP BY j.status,j.display_status,j.job_type'
        );
        $root->execute($params);
        foreach ($root->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $this->accumulateCountRow($counts, $row, max(0, (int)($row['queued_running'] ?? 0)));
        }

        $problem = $this->db->prepare(
            'SELECT j.status,j.display_status,j.job_type,COUNT(*) AS total '
            . 'FROM ue_background_jobs j WHERE j.parent_job_id IS NOT NULL '
            . 'AND j.status IN ("failed","dead_letter")' . $queueSql . ' '
            . 'GROUP BY j.status,j.display_status,j.job_type'
        );
        $problem->execute($params);
        foreach ($problem->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $this->accumulateCountRow($counts, $row, 0);
        }

        return $counts;
    }

    /** @param array<string,int> $counts @param array<string,mixed> $row */
    private function accumulateCountRow(array &$counts, array $row, int $queuedRunning): void
    {
        $amount = max(0, (int)($row['total'] ?? 0));
        if ($amount < 1) {
            return;
        }
        $counts['all'] += $amount;

        $queueStatus = strtolower(trim((string)($row['status'] ?? '')));
        $displayStatus = strtolower(trim((string)($row['display_status'] ?? '')));
        $jobType = trim((string)($row['job_type'] ?? ''));

        if ($queueStatus === 'completed'
            && $displayStatus === 'partial'
            && in_array($jobType, [JobType::PROCESS_BUCKET_ARCHIVE, JobType::IMPORT_STAGED_ARCHIVE], true)) {
            $counts['partial_archive'] += $amount;
        }

        if ($queueStatus === 'queued' && $queuedRunning > 0) {
            $queuedRunning = min($amount, $queuedRunning);
            $counts['running'] += $queuedRunning;
            $counts['queued'] += $amount - $queuedRunning;
            return;
        }

        $group = CatalogJobDisplayStatus::groupDisplayStatus($queueStatus, $displayStatus);
        if (array_key_exists($group, $counts)) {
            $counts[$group] += $amount;
        }
    }
}
