<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Exposes the existing background-job search projection synchronization/query-token behavior through one explicit Infrastructure adapter.
 * Why: Persistence query objects should not scatter calls to procedural performance helpers.
 * Role: Transitional Infrastructure boundary until the job-search projection implementation is fully namespaced.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;

final class CatalogJobSearchProjectionRuntime
{
    public function __construct()
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogPerformance.php';
    }

    public function synchronize(PDO $db): bool
    {
        return \catalog_performance_sync_job_search($db);
    }

    public function booleanQuery(string $search): string
    {
        return \catalog_performance_boolean_query($search);
    }
}
