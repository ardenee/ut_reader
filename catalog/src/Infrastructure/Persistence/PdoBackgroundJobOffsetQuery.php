<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes the compatibility offset-based durable-job query used by secondary admin pages.
 * Why: The legacy API contract remains available without keeping SQL/JSON aggregate logic in the HTTP endpoint.
 * Role: Infrastructure read model; preserves existing wildcard search and offset pagination semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;

final class PdoBackgroundJobOffsetQuery
{
    private readonly PdoBackgroundJobDisplayCountQuery $countQuery;

    public function __construct(private readonly PDO $db)
    {
        $this->countQuery = new PdoBackgroundJobDisplayCountQuery($db);
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   counts:array{all:int,queued:int,running:int,completed:int,failed:int,dead_letter:int,cancelled:int},
     *   total:int,page:int,pages:int,per_page:int
     * }
     */
    public function fetch(
        int $jobId,
        string $queue,
        string $status,
        string $search,
        int $page,
        int $perPage
    ): array {
        $baseWhere = [];
        $baseParams = [];
        if ($jobId > 0) {
            $baseWhere[] = 'j.id=?';
            $baseParams[] = $jobId;
        }
        if ($queue !== '') {
            $baseWhere[] = 'j.queue_name=?';
            $baseParams[] = $queue;
        }

        if ($jobId < 1) {
            $baseWhere[] = 'j.parent_job_id IS NULL';
        }
        if ($search !== '') {
            $baseWhere[] = '(CAST(j.id AS CHAR) LIKE ? OR j.job_type LIKE ? OR COALESCE(j.concurrency_key,"") LIKE ? '
                . 'OR COALESCE(j.payload_json,"") LIKE ? OR COALESCE(j.last_error,"") LIKE ? '
                . 'OR COALESCE(j.result_json,"") LIKE ?)';
            $like = '%' . $search . '%';
            array_push($baseParams, $like, $like, $like, $like, $like, $like);
        }

        $hasRunningChildSql = 'EXISTS(SELECT 1 FROM ue_background_jobs job_child '
            . 'WHERE job_child.parent_job_id=j.id AND job_child.status="running" LIMIT 1)';
        $operatorStatusSql = 'CASE '
            . 'WHEN j.status="running" THEN "running" '
            . 'WHEN j.parent_job_id IS NULL AND j.status="queued" AND ' . $hasRunningChildSql . ' THEN "running" '
            . 'ELSE j.status END';
        $where = $baseWhere;
        $params = $baseParams;
        if ($status !== '') {
            if (in_array($status, ['queued', 'running'], true)) {
                $where[] = $operatorStatusSql . '=?';
                $params[] = $status;
            } else {
                $condition = CatalogJobDisplayStatus::filterCondition($status, 'j');
                $where[] = $condition['sql'];
                array_push($params, ...$condition['params']);
            }
        }

        $whereSql = implode(' AND ', $where);
        $baseWhereSql = implode(' AND ', $baseWhere);
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs j' . ($whereSql !== '' ? ' WHERE ' . $whereSql : '')
        );
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $counts = $this->countQuery->counts('ue_background_jobs j', $baseWhereSql, $baseParams);
        $sql = 'SELECT j.id,j.parent_job_id,j.workflow_unit_key,j.queue_name,j.job_type,j.resource_class,j.resource_limit,j.concurrency_key,j.priority,j.status,'
            . $operatorStatusSql . ' AS operator_status,j.display_status,j.available_at,j.attempts,j.max_attempts,j.worker_id,j.leased_at,j.lease_expires_at,'
            . 'j.last_heartbeat_at,j.recovery_count,j.cancel_requested_at,j.cancel_requested_by,j.cancel_reason,'
            . 'j.payload_json,j.progress_json,j.progress_updated_at,j.result_json,j.last_error,j.created_by,j.created_at,'
            . 'j.updated_at,j.completed_at,j.dead_lettered_at FROM ue_background_jobs j'
            . ($whereSql !== '' ? ' WHERE ' . $whereSql : '')
            . ' ORDER BY j.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'counts' => $counts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }
}
