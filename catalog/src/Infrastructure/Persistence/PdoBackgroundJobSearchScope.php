<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the authoritative Background Jobs queue/search persistence scope shared by list and bulk actions.
 * Why: "Select all matching" must target exactly the same indexed search set the administrator is viewing.
 * Role: Infrastructure query builder; contains no HTTP or mutation behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
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
        $fromSql = 'ue_background_jobs j';
        $where = [];
        $params = [];
        if ($queue !== '') {
            $where[] = 'j.queue_name=?';
            $params[] = $queue;
        }
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
                // Preserve the compatibility fallback if the projection is not
                // available yet; migration/readiness checks should make this rare.
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
