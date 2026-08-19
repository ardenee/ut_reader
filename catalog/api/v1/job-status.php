<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the compatibility offset-based job status endpoint used by secondary admin pages.
 * Why: The API contract remains stable while persistence and row hydration are delegated to namespaced services.
 * Role: Thin HTTP API entry point.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogArchiveJobOutcomeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobResultHydrator;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobEventLog;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobOffsetQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    $queue = trim((string)($_GET['queue'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $search = trim((string)($_GET['search'] ?? ''));
    $page = $jobId > 0 ? 1 : max(1, (int)($_GET['page'] ?? 1));
    $perPage = $jobId > 0
        ? 1
        : max(1, min((int)($_GET['per_page'] ?? $_GET['limit'] ?? 100), 1000));
    $eventOffset = max(0, (int)($_GET['event_offset'] ?? 0));
    $eventLimit = max(1, min((int)($_GET['event_limit'] ?? 250), 1000));

    if ($queue !== '' && (strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1)) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $result = (new PdoBackgroundJobOffsetQuery($application->db))->fetch(
        $jobId,
        $queue,
        $status,
        $search,
        $page,
        $perPage
    );
    $rows = (new CatalogBackgroundJobResultHydrator($application->config))->hydrate($result['rows']);
    $rows = (new CatalogArchiveJobOutcomeProjector($application->db))->project($rows);

    $eventState = ['events' => [], 'offset' => $eventOffset, 'has_more' => false];
    if ($jobId > 0 && $rows !== []) {
        try {
            $eventState = (new CatalogJobEventLog($application->config))
                ->readFrom($jobId, $eventOffset, $eventLimit);
        } catch (Throwable $eventError) {
            error_log('[UnrealDB job events][' . catalog_request_id() . '] ' . $eventError->getMessage());
        }
    }

    JsonResponse::send([
        'data' => [
            'jobs' => $rows,
            'events' => $eventState['events'],
        ],
        'meta' => [
            'limit' => $result['per_page'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'counts' => $result['counts'],
            'job_id' => $jobId > 0 ? $jobId : null,
            'queue' => $queue !== '' ? $queue : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'event_offset' => (int)$eventState['offset'],
            'events_has_more' => (bool)$eventState['has_more'],
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB job status API] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
