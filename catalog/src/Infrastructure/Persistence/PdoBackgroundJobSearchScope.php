<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the authoritative Background Jobs queue/search persistence scope shared by list and bulk actions.
 * Why: The operator page reports stable top-level jobs while surfacing only child units that require operator attention.
 * Role: Infrastructure query builder; contains no HTTP or mutation behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobSearchProjectionRuntime;

final class PdoBackgroundJobSearchScope
{
    private readonly CatalogJobSearchProjectionRuntime $searchRuntime;

    public function __construct(private readonly PDO $db)
    {
        $this->searchRuntime = new CatalogJobSearchProjectionRuntime();
    }

    /** @return array{from:string,where:string,params:list<mixed>} */
    public function build(string $queue, string $search): array
    {
        /*
         * Never make the operator page discover its tiny visible row set through
         * an OR predicate over the complete execution ledger. Large workflows can
         * leave hundreds of thousands of routine child rows behind; the previous
         * `parent IS NULL OR failed child` predicate allowed MySQL to scan that
         * ledger for every two-second browser poll.
         *
         * Split visibility into independently indexable branches instead:
         * top-level jobs; direct source jobs planned by a profiled/game upload
         * batch; plus only failed/dead-letter children outside those promoted
         * sources that still need direct operator attention. Cancelled routine
         * child execution units remain folded into their source workflow.
         */
        $params = [];
        $profiledType = JobType::PROFILED_UPLOAD_BATCH;
        if ($queue !== '') {
            $fromSql = '('
                . 'SELECT root_job.* FROM ue_background_jobs root_job '
                . 'WHERE root_job.queue_name=? AND root_job.parent_job_id IS NULL '
                . 'UNION ALL '
                . 'SELECT profiled_source.* FROM ue_background_jobs profiled_source '
                . 'JOIN ue_background_jobs profiled_parent ON profiled_parent.id=profiled_source.parent_job_id '
                . 'AND profiled_parent.queue_name=profiled_source.queue_name '
                . 'WHERE profiled_source.queue_name=? AND profiled_parent.job_type=? '
                . 'UNION ALL '
                . 'SELECT problem_child.* FROM ue_background_jobs problem_child '
                . 'WHERE problem_child.queue_name=? AND problem_child.parent_job_id IS NOT NULL '
                . 'AND problem_child.status IN ("failed","dead_letter") '
                . 'AND NOT EXISTS(SELECT 1 FROM ue_background_jobs problem_parent '
                . 'WHERE problem_parent.id=problem_child.parent_job_id '
                . 'AND problem_parent.queue_name=problem_child.queue_name '
                . 'AND problem_parent.job_type=?)'
                . ') j';
            $params[] = $queue;
            $params[] = $queue;
            $params[] = $profiledType;
            $params[] = $queue;
            $params[] = $profiledType;
        } else {
            $fromSql = '('
                . 'SELECT root_job.* FROM ue_background_jobs root_job '
                . 'WHERE root_job.parent_job_id IS NULL '
                . 'UNION ALL '
                . 'SELECT profiled_source.* FROM ue_background_jobs profiled_source '
                . 'JOIN ue_background_jobs profiled_parent ON profiled_parent.id=profiled_source.parent_job_id '
                . 'AND profiled_parent.queue_name=profiled_source.queue_name '
                . 'WHERE profiled_parent.job_type=? '
                . 'UNION ALL '
                . 'SELECT problem_child.* FROM ue_background_jobs problem_child '
                . 'WHERE problem_child.parent_job_id IS NOT NULL '
                . 'AND problem_child.status IN ("failed","dead_letter") '
                . 'AND NOT EXISTS(SELECT 1 FROM ue_background_jobs problem_parent '
                . 'WHERE problem_parent.id=problem_child.parent_job_id '
                . 'AND problem_parent.queue_name=problem_child.queue_name '
                . 'AND problem_parent.job_type=?)'
                . ') j';
            $params[] = $profiledType;
            $params[] = $profiledType;
        }

        // Keep a non-empty base condition because several shared operator queries
        // append current/actionable status conditions around this scope.
        $where = ['1=1'];

        if ($search !== '') {
            $projectionAvailable = $this->searchRuntime->synchronize($this->db);
            $booleanSearch = $this->searchRuntime->booleanQuery($search);
            if ($projectionAvailable && $booleanSearch !== '') {
                $fromSql .= ' JOIN ue_background_job_search js ON js.job_id=j.id';
                if (ctype_digit($search)) {
                    $where[] = '(j.id=? OR MATCH(js.search_text) AGAINST (? IN BOOLEAN MODE))';
                    $params[] = (int)$search;
                    $params[] = $booleanSearch;
                } else {
                    $where[] = 'MATCH(js.search_text) AGAINST (? IN BOOLEAN MODE)';
                    $params[] = $booleanSearch;
                }
            } elseif ($projectionAvailable) {
                $fromSql .= ' JOIN ue_background_job_search js ON js.job_id=j.id';
                $where[] = 'js.search_text LIKE ?';
                $params[] = '%' . $search . '%';
            } else {
                $where[] = '(CAST(j.id AS CHAR) LIKE ? OR j.job_type LIKE ? OR COALESCE(j.concurrency_key,"") LIKE ? '
                    . 'OR COALESCE(j.payload_json,"") LIKE ? OR COALESCE(j.last_error,"") LIKE ? '
                    . 'OR COALESCE(j.result_json,"") LIKE ?)';
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
        }
        return ['from' => $fromSql, 'where' => implode(' AND ', $where), 'params' => $params];
    }
}
