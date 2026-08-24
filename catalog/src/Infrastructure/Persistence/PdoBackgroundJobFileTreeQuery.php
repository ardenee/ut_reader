<?php
/**
 * File-centric Background Jobs read model.
 *
 * Root rows are the source file/container jobs shown in the operator ledger.
 * Direct children are loaded lazily so archive members, nested archives and
 * workflow units can be expanded without scanning/rendering the full durable
 * execution ledger on every poll.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobFileTreeQuery
{
    private const ISSUE_DISPLAY_STATUSES = '"failed","rejected","unverified","partial","error"';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   counts:array{all:int,working:int,issue:int,completed:int,stopped:int},
     *   total:int,page:int,pages:int,per_page:int
     * }
     */
    public function roots(
        string $queue,
        string $state,
        string $search,
        int $page,
        int $perPage
    ): array {
        $perPage = max(10, min($perPage, 200));
        $baseWhere = ['j.queue_name=?', 'j.parent_job_id IS NULL'];
        $baseParams = [$queue];

        if ($search !== '') {
            [$searchSql, $searchParams] = $this->rootSearchCondition($search);
            $baseWhere[] = $searchSql;
            array_push($baseParams, ...$searchParams);
        }

        /*
         * Global counts/filtering deliberately use only the persisted root row.
         * Child-state correlation across every historical root previously made
         * Background Jobs polling catastrophically expensive on large ledgers.
         * Child issue/active counts are looked up only for the bounded page rows
         * below, where they enrich the visible status without affecting hot counts.
         */
        $issue = $this->ownIssueExpression('j');
        $active = 'j.status IN ("queued","running")';
        $baseSql = implode(' AND ', $baseWhere);

        $countStatement = $this->db->prepare(
            'SELECT COUNT(*) AS all_count,'
            . 'SUM(CASE WHEN ' . $issue . ' THEN 1 ELSE 0 END) AS issue_count,'
            . 'SUM(CASE WHEN NOT (' . $issue . ') AND ' . $active . ' THEN 1 ELSE 0 END) AS working_count,'
            . 'SUM(CASE WHEN NOT (' . $issue . ') AND NOT (' . $active . ') AND j.status="completed" THEN 1 ELSE 0 END) AS completed_count,'
            . 'SUM(CASE WHEN NOT (' . $issue . ') AND NOT (' . $active . ') AND j.status="cancelled" THEN 1 ELSE 0 END) AS stopped_count '
            . 'FROM ue_background_jobs j WHERE ' . $baseSql
        );
        $countStatement->execute($baseParams);
        $countRow = $countStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = [
            'all' => max(0, (int)($countRow['all_count'] ?? 0)),
            'working' => max(0, (int)($countRow['working_count'] ?? 0)),
            'issue' => max(0, (int)($countRow['issue_count'] ?? 0)),
            'completed' => max(0, (int)($countRow['completed_count'] ?? 0)),
            'stopped' => max(0, (int)($countRow['stopped_count'] ?? 0)),
        ];

        $where = $baseWhere;
        $params = $baseParams;
        $stateCondition = $this->stateCondition($state, $issue, $active, 'j');
        if ($stateCondition !== '') {
            $where[] = $stateCondition;
        }
        $whereSql = implode(' AND ', $where);

        $totalStatement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs j WHERE ' . $whereSql
        );
        $totalStatement->execute($params);
        $total = max(0, (int)$totalStatement->fetchColumn());
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->db->prepare(
            'SELECT ' . $this->columns('j') . ','
            . $this->childCountExpression('j') . ' AS child_count,'
            . $this->childIssueCountExpression('j') . ' AS child_issue_count,'
            . $this->childActiveCountExpression('j') . ' AS child_active_count '
            . 'FROM ue_background_jobs j WHERE ' . $whereSql
            . ' ORDER BY j.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($params);

        return [
            'rows' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'counts' => $counts,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function children(string $queue, int $parentJobId, int $page, int $perPage): array
    {
        $perPage = max(25, min($perPage, 500));
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=? AND parent_job_id=?'
        );
        $count->execute([$queue, $parentJobId]);
        $total = max(0, (int)$count->fetchColumn());
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->db->prepare(
            'SELECT ' . $this->columns('j') . ','
            . $this->childCountExpression('j') . ' AS child_count,'
            . $this->childIssueCountExpression('j') . ' AS child_issue_count,'
            . $this->childActiveCountExpression('j') . ' AS child_active_count '
            . 'FROM ue_background_jobs j '
            . 'WHERE j.queue_name=? AND j.parent_job_id=? '
            . 'ORDER BY j.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute([$queue, $parentJobId]);

        return [
            'rows' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private function stateCondition(string $state, string $issue, string $active, string $alias): string
    {
        return match ($state) {
            'issue' => $issue,
            'working' => 'NOT (' . $issue . ') AND ' . $active,
            'completed' => 'NOT (' . $issue . ') AND NOT (' . $active . ') AND ' . $alias . '.status="completed"',
            'stopped' => 'NOT (' . $issue . ') AND NOT (' . $active . ') AND ' . $alias . '.status="cancelled"',
            default => '',
        };
    }

    private function ownIssueExpression(string $alias): string
    {
        return '('
            . $alias . '.status IN ("failed","dead_letter") OR '
            . $alias . '.display_status IN (' . self::ISSUE_DISPLAY_STATUSES . ')'
            . ')';
    }

    private function childCountExpression(string $alias): string
    {
        return '(SELECT COUNT(*) FROM ue_background_jobs child_count '
            . 'WHERE child_count.parent_job_id=' . $alias . '.id)';
    }

    private function childIssueCountExpression(string $alias): string
    {
        return '(SELECT COUNT(*) FROM ue_background_jobs child_issue '
            . 'WHERE child_issue.parent_job_id=' . $alias . '.id AND '
            . $this->ownIssueExpression('child_issue') . ')';
    }

    private function childActiveCountExpression(string $alias): string
    {
        return '(SELECT COUNT(*) FROM ue_background_jobs child_active '
            . 'WHERE child_active.parent_job_id=' . $alias . '.id '
            . 'AND child_active.status IN ("queued","running"))';
    }

    /** @return array{0:string,1:list<mixed>} */
    private function rootSearchCondition(string $search): array
    {
        $like = '%' . $search . '%';
        $own = '(CAST(j.id AS CHAR) LIKE ? OR j.job_type LIKE ? OR COALESCE(j.payload_json,"") LIKE ? '
            . 'OR COALESCE(j.last_error,"") LIKE ? OR COALESCE(j.result_json,"") LIKE ?)';
        $child = 'EXISTS(SELECT 1 FROM ue_background_jobs search_child '
            . 'WHERE search_child.parent_job_id=j.id AND '
            . '(CAST(search_child.id AS CHAR) LIKE ? OR search_child.job_type LIKE ? '
            . 'OR COALESCE(search_child.payload_json,"") LIKE ? '
            . 'OR COALESCE(search_child.last_error,"") LIKE ? '
            . 'OR COALESCE(search_child.result_json,"") LIKE ?) LIMIT 1)';
        return [
            '(' . $own . ' OR ' . $child . ')',
            [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like],
        ];
    }

    private function columns(string $alias): string
    {
        return $alias . '.id,' . $alias . '.parent_job_id,' . $alias . '.workflow_unit_key,'
            . $alias . '.queue_name,' . $alias . '.job_type,' . $alias . '.resource_class,'
            . $alias . '.concurrency_key,' . $alias . '.status,' . $alias . '.display_status,'
            . $alias . '.attempts,' . $alias . '.max_attempts,' . $alias . '.worker_id,'
            . $alias . '.leased_at,' . $alias . '.last_heartbeat_at,' . $alias . '.cancel_reason,'
            . $alias . '.payload_json,' . $alias . '.progress_json,' . $alias . '.progress_updated_at,'
            . $alias . '.result_json,' . $alias . '.last_error,' . $alias . '.created_at,'
            . $alias . '.updated_at,' . $alias . '.completed_at,' . $alias . '.dead_lettered_at';
    }
}
