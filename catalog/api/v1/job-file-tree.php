<?php
/**
 * File-centric Background Jobs API used only by the administrator file tree.
 * Root jobs are paged; direct children are loaded lazily with parent_job_id.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogArchiveJobOutcomeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobFileTreeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobResultHydrator;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobFileTreeQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $queue = trim((string)($_GET['queue'] ?? ($application->config['queue']['name'] ?? 'catalog')));
    $state = strtolower(trim((string)($_GET['state'] ?? 'all')));
    $search = trim((string)($_GET['search'] ?? ''));
    $jobType = trim((string)($_GET['job_type'] ?? ''));
    $parentJobId = max(0, (int)($_GET['parent_job_id'] ?? 0));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(10, min((int)($_GET['per_page'] ?? ($parentJobId > 0 ? 200 : 100)), $parentJobId > 0 ? 500 : 200));

    if ($queue === '' || strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if (!in_array($state, ['all', 'working', 'issue', 'completed', 'stopped'], true)) {
        JsonResponse::error('invalid_state', 'Unsupported file state filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $jobTypes = JobType::all();
    if ($jobType !== '' && !in_array($jobType, $jobTypes, true)) {
        $jobType = '';
    }

    $query = new PdoBackgroundJobFileTreeQuery($application->db);
    if ($parentJobId > 0) {
        $result = $query->children($queue, $parentJobId, $page, $perPage);
        $counts = null;
    } else {
        $result = $query->roots($queue, $state, $search, $jobType, $page, $perPage);
        $counts = $result['counts'];
    }

    $rows = (new CatalogBackgroundJobResultHydrator($application->config))->hydrate($result['rows']);
    // The file-centric page must use the same archive-child outcome projection
    // as the compatibility status APIs. Without this, a partial archive only
    // exposes "N failed" and hides the child member name/job/error even though
    // that diagnostic data is already durable in ue_background_jobs.
    $rows = (new CatalogArchiveJobOutcomeProjector($application->db))->project($rows);
    $rows = (new CatalogBackgroundJobFileTreeProjector())->project($rows);

    JsonResponse::send([
        'data' => [
            'files' => $rows,
        ],
        'meta' => [
            'queue' => $queue,
            'state' => $parentJobId > 0 ? null : $state,
            'search' => $parentJobId > 0 ? null : ($search !== '' ? $search : null),
            'job_type' => $parentJobId > 0 ? null : ($jobType !== '' ? $jobType : null),
            'job_types' => $parentJobId > 0 ? null : $jobTypes,
            'parent_job_id' => $parentJobId > 0 ? $parentJobId : null,
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'counts' => $counts,
        ],
    ]);
} catch (Throwable $exception) {
    $requestId = function_exists('catalog_request_id') ? catalog_request_id() : '';
    error_log('[UnrealDB job file tree]'
        . ($requestId !== '' ? '[' . $requestId . ']' : '') . ' '
        . get_class($exception) . ': ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The file job view is temporarily unavailable.', 503);
}
