<?php
/**
 * File-centric Background Jobs read model.
 *
 * Root rows are the source file/container jobs shown in the operator ledger.
 * Direct children are loaded lazily so archive members, nested archives and
 * workflow units can be expanded without scanning/rendering the full durable
 * execution ledger on every poll.
 *
 * Content-routing archive jobs are implementation details, not physical files.
 * When a physical file is detected by bytes as ZIP/RAR/7z despite a misleading
 * extension, the synthetic decoder row is folded out but the extracted members
 * remain children of that physical file. This preserves true containment while
 * avoiding fake names such as "map.ut2.rar" in the operator tree.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoBackgroundJobFileTreeQuery
{
    private const ISSUE_DISPLAY_STATUSES = '"failed","rejected","unverified","invalid_ue_package","partial","error"';
    private const SYNTHETIC_ARCHIVE_WORKFLOW_PREFIX = 'archive:content-container:';
    private const PROFILED_UPLOAD_BATCH_JOB_TYPE = 'catalog.profiled_upload_batch';

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
        string $jobType,
        int $page,
        int $perPage
    ): array {
        $perPage = max(10, min($perPage, 200));
        $jobType = trim($jobType);

        // A profiled/game upload batch is a planning coordinator, not the source
        // file the operator needs to track. While that coordinator is live or has
        // itself failed it remains visible; once it completes, each direct staged
        // import beneath it becomes a logical source root with its own archive/
        // package descendants. An explicit coordinator job-type filter still
        // exposes historical batch rows for diagnostics.
        $logicalRootScope = $jobType === self::PROFILED_UPLOAD_BATCH_JOB_TYPE
            ? 'j.parent_job_id IS NULL'
            : $this->logicalRootExpression('j');

        $pageRootScope = $logicalRootScope;

        $commonWhere = ['j.queue_name=?'];
        $commonParams = [$queue];
        if ($jobType !== '') {
            $commonWhere[] = 'j.job_type=?';
            $commonParams[] = $jobType;
        }
        if ($search !== '') {
            [$searchSql, $searchParams] = $this->rootSearchCondition($search);
            $commonWhere[] = $searchSql;
            array_push($commonParams, ...$searchParams);
        }

        $baseWhere = array_merge($commonWhere, [$pageRootScope]);
        $baseParams = $commonParams;

        /*
         * Global counts/filtering deliberately use only each logical source row.
         * Child-state correlation across every historical source previously made
         * Background Jobs polling catastrophically expensive on large ledgers.
         * Child issue/active counts are looked up only for the bounded page rows
         * below, where they enrich the visible status without affecting hot counts.
         */
        $issue = $this->ownIssueExpression('j');
        $active = 'j.status IN ("queued","running")';
        $logicalCountWhere = array_merge($commonWhere, [$logicalRootScope]);
        $logicalCountSql = implode(' AND ', $logicalCountWhere);

        $countStatement = $this->db->prepare(
            'SELECT COUNT(*) AS all_count,'
            . 'SUM(CASE WHEN ' . $issue . ' THEN 1 ELSE 0 END) AS issue_count,'
            . 'SUM(CASE WHEN NOT (' . $issue . ') AND ' . $active . ' THEN 1 ELSE 0 END) AS working_count,'
            . 'SUM(CASE WHEN NOT (' . $issue . ') AND NOT (' . $active . ') AND j.status="completed" THEN 1 ELSE 0 END) AS completed_count,'
            . 'SUM(CASE WHEN NOT (' . $issue . ') AND NOT (' . $active . ') AND j.status="cancelled" THEN 1 ELSE 0 END) AS stopped_count '
            . 'FROM ue_background_jobs j WHERE ' . $logicalCountSql
        );
        $countStatement->execute($commonParams);
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
     * Selects the logical source roots matching the same filters as roots()
     * without loading child counts or row payloads. This is used by file-centric
     * bulk actions so "all matching" is not limited to the current 200-row page.
     *
     * @return array{ids:list<int>,total:int,limited:bool,limit:int}
     */
    public function matchingRootIds(
        string $queue,
        string $state,
        string $search,
        string $jobType,
        int $limit = 10000
    ): array {
        $limit = max(1, min($limit, 10000));
        $jobType = trim($jobType);
        $logicalRootScope = $jobType === self::PROFILED_UPLOAD_BATCH_JOB_TYPE
            ? 'j.parent_job_id IS NULL'
            : $this->logicalRootExpression('j');

        $where = ['j.queue_name=?'];
        $params = [$queue];
        if ($jobType !== '') {
            $where[] = 'j.job_type=?';
            $params[] = $jobType;
        }
        if ($search !== '') {
            [$searchSql, $searchParams] = $this->rootSearchCondition($search);
            $where[] = $searchSql;
            array_push($params, ...$searchParams);
        }
        $where[] = $logicalRootScope;

        $issue = $this->ownIssueExpression('j');
        $active = 'j.status IN ("queued","running")';
        $stateCondition = $this->stateCondition($state, $issue, $active, 'j');
        if ($stateCondition !== '') {
            $where[] = $stateCondition;
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_background_jobs j WHERE ' . $whereSql
        );
        $count->execute($params);
        $total = max(0, (int)$count->fetchColumn());

        $statement = $this->db->prepare(
            'SELECT j.id FROM ue_background_jobs j WHERE ' . $whereSql
            . ' ORDER BY j.id ASC LIMIT ' . $limit
        );
        $statement->execute($params);
        $ids = array_values(array_filter(
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn(int $id): bool => $id > 0
        ));

        return [
            'ids' => $ids,
            'total' => $total,
            'limited' => $total > count($ids),
            'limit' => $limit,
        ];
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function children(string $queue, int $parentJobId, int $page, int $perPage): array
    {
        $perPage = max(25, min($perPage, 500));
        $visibleSql = $this->visibleChildrenIdSql();
        $visibleParams = [$queue, $parentJobId, $queue, $parentJobId];

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM (' . $visibleSql . ') visible_children'
        );
        $count->execute($visibleParams);
        $total = max(0, (int)$count->fetchColumn());
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->db->prepare(
            'SELECT ' . $this->columns('j') . ',visible_children.tree_hoisted,'
            . $this->childCountExpression('j') . ' AS child_count,'
            . $this->childIssueCountExpression('j') . ' AS child_issue_count,'
            . $this->childActiveCountExpression('j') . ' AS child_active_count '
            . 'FROM (' . $visibleSql . ') visible_children '
            . 'JOIN ue_background_jobs j ON j.id=visible_children.id '
            . 'ORDER BY j.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($visibleParams);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->applyLogicalTreeContext($rows, $parentJobId);

        return [
            'rows' => $rows,
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

    private function propagatingChildIssueExpression(string $alias): string
    {
        return $this->ownIssueExpression($alias);
    }

    private function logicalRootExpression(string $alias): string
    {
        $parentAlias = 'logical_root_parent';
        return '('
            . '(' . $alias . '.parent_job_id IS NULL AND ('
            . $alias . '.job_type<>"' . self::PROFILED_UPLOAD_BATCH_JOB_TYPE . '" OR '
            . $alias . '.status IN ("queued","running") OR '
            . $this->ownIssueExpression($alias)
            . ')) OR '
            . 'EXISTS(SELECT 1 FROM ue_background_jobs ' . $parentAlias . ' WHERE '
            . $parentAlias . '.id=' . $alias . '.parent_job_id '
            . 'AND ' . $parentAlias . '.queue_name=' . $alias . '.queue_name '
            . 'AND ' . $parentAlias . '.job_type="' . self::PROFILED_UPLOAD_BATCH_JOB_TYPE . '")'
            . ')';
    }

    /**
     * Visible children are:
     *  1. real direct child rows, excluding synthetic content-container jobs; and
     *  2. real files extracted by a synthetic decoder directly beneath the
     *     requested physical parent. The synthetic decoder itself stays hidden.
     */
    private function visibleChildrenIdSql(): string
    {
        return 'SELECT direct_child.id,0 AS tree_hoisted FROM ue_background_jobs direct_child '
            . 'WHERE direct_child.queue_name=? AND direct_child.parent_job_id=? '
            . 'AND NOT (' . $this->syntheticArchiveExpression('direct_child') . ') '
            . 'UNION ALL '
            . 'SELECT routed_child.id,1 AS tree_hoisted FROM ue_background_jobs synthetic_parent '
            . 'JOIN ue_background_jobs routed_child ON routed_child.parent_job_id=synthetic_parent.id '
            . 'AND routed_child.queue_name=synthetic_parent.queue_name '
            . 'WHERE synthetic_parent.queue_name=? AND synthetic_parent.parent_job_id=? '
            . 'AND ' . $this->syntheticArchiveExpression('synthetic_parent');
    }

    private function childCountExpression(string $alias): string
    {
        return '('
            . $this->directChildCount('direct_count', $alias, '')
            . ' + '
            . $this->syntheticRoutedChildCount('synthetic_count', 'routed_count', $alias, '')
            . ')';
    }

    private function childIssueCountExpression(string $alias): string
    {
        return '('
            . $this->directChildCount('direct_issue', $alias, 'AND ' . $this->propagatingChildIssueExpression('direct_issue'))
            . ' + '
            . $this->syntheticRoutedChildCount(
                'synthetic_issue',
                'routed_issue',
                $alias,
                'AND ' . $this->propagatingChildIssueExpression('routed_issue')
            )
            . ')';
    }

    private function childActiveCountExpression(string $alias): string
    {
        return '('
            . $this->directChildCount(
                'direct_active',
                $alias,
                'AND direct_active.status IN ("queued","running")'
            )
            . ' + '
            . $this->syntheticRoutedChildCount(
                'synthetic_active',
                'routed_active',
                $alias,
                'AND routed_active.status IN ("queued","running")'
            )
            . ')';
    }

    private function directChildCount(string $childAlias, string $parentAlias, string $extra): string
    {
        return '(SELECT COUNT(*) FROM ue_background_jobs ' . $childAlias . ' '
            . 'WHERE ' . $childAlias . '.parent_job_id=' . $parentAlias . '.id '
            . 'AND NOT (' . $this->syntheticArchiveExpression($childAlias) . ') '
            . $extra . ')';
    }

    private function syntheticRoutedChildCount(
        string $syntheticAlias,
        string $routedAlias,
        string $parentAlias,
        string $extra
    ): string {
        return '(SELECT COUNT(*) FROM ue_background_jobs ' . $syntheticAlias . ' '
            . 'JOIN ue_background_jobs ' . $routedAlias . ' ON ' . $routedAlias . '.parent_job_id=' . $syntheticAlias . '.id '
            . 'AND ' . $routedAlias . '.queue_name=' . $syntheticAlias . '.queue_name '
            . 'WHERE ' . $syntheticAlias . '.parent_job_id=' . $parentAlias . '.id '
            . 'AND ' . $this->syntheticArchiveExpression($syntheticAlias) . ' '
            . $extra . ')';
    }

    private function syntheticArchiveExpression(string $alias): string
    {
        return $alias . '.workflow_unit_key LIKE "' . self::SYNTHETIC_ARCHIVE_WORKFLOW_PREFIX . '%"';
    }

    /** @param list<array<string,mixed>> $rows */
    private function applyLogicalTreeContext(array &$rows, int $parentJobId): void
    {
        if ($rows === []) {
            return;
        }

        $parentPath = $this->jobSourceRelativePath($parentJobId);
        foreach ($rows as &$row) {
            $row['tree_parent_job_id'] = $parentJobId;
            if ((int)($row['tree_hoisted'] ?? 0) !== 1 || $parentPath === '') {
                continue;
            }

            $payload = $this->decodeObject((string)($row['payload_json'] ?? ''));
            $entryPath = $this->cleanPath((string)($payload['archive_entry_path'] ?? ''));
            if ($entryPath === '') {
                $entryPath = $this->cleanPath((string)($payload['original_name'] ?? ''));
            }
            if ($entryPath !== '') {
                $row['tree_source_relative_path'] = $this->joinPath($parentPath, $entryPath);
            }
        }
        unset($row);
    }

    private function jobSourceRelativePath(int $jobId): string
    {
        if ($jobId < 1) {
            return '';
        }
        $statement = $this->db->prepare('SELECT payload_json FROM ue_background_jobs WHERE id=? LIMIT 1');
        $statement->execute([$jobId]);
        $payload = $this->decodeObject((string)($statement->fetchColumn() ?: ''));
        return $this->cleanPath((string)($payload['source_relative_path'] ?? $payload['original_name'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function cleanPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return trim($path, '/ ');
    }

    private function joinPath(string $parent, string $child): string
    {
        $parent = $this->cleanPath($parent);
        $child = $this->cleanPath($child);
        if ($parent === '') {
            return $child;
        }
        if ($child === '') {
            return $parent;
        }
        return $parent . '/' . $child;
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
