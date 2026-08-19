<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the cursor-based Background Jobs status endpoint.
 * Why: HTTP validation/cursor serialization belong here while persistence and row hydration are delegated.
 * Role: Thin HTTP API entry point for the Background Jobs live list.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogArchiveJobOutcomeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobResultHydrator;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobDisplayStatus;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobBrowserQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $queue = trim((string)($_GET['queue'] ?? ''));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $search = trim((string)($_GET['search'] ?? ''));
    $requestedPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min((int)($_GET['per_page'] ?? $_GET['limit'] ?? 100), 1000));
    $move = strtolower(trim((string)($_GET['move'] ?? 'first')));
    if ($move === 'prev') {
        $move = 'previous';
    }
    if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
        $move = 'first';
    }

    if ($queue !== '' && (strlen($queue) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queue) !== 1)) {
        JsonResponse::error('invalid_queue', 'A valid queue name is required.', 400);
    }
    if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
        JsonResponse::error('invalid_status', 'Unsupported job status filter.', 400);
    }
    if (mb_strlen($search, 'UTF-8') > 200) {
        JsonResponse::error('invalid_search', 'Search text is too long.', 400);
    }

    $context = json_encode([
        'page' => 'background-jobs',
        'queue' => $queue,
        'status' => $status,
        'search' => $search,
        'limit' => $perPage,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $cursorToken = trim((string)($_GET['cursor'] ?? ''));
    $cursor = $cursorToken !== ''
        ? CatalogKeysetPaginator::decode($application->config, $context, $cursorToken)
        : null;
    if ($cursorToken !== '' && $cursor === null) {
        $move = 'first';
        $requestedPage = 1;
    }

    $query = new PdoBackgroundJobBrowserQuery($application->db, $application->config);
    $pageResult = $query->fetch($queue, $status, $search, $perPage, $cursor, $move);
    $counts = $pageResult['counts'];
    $total = max(0, (int)$pageResult['total']);
    $pages = max(1, (int)ceil($total / max(1, $perPage)));

    if ($move === 'first') {
        $requestedPage = 1;
    } elseif ($move === 'last') {
        $requestedPage = $pages;
    } else {
        $requestedPage = max(1, min($pages, $requestedPage));
    }

    if ($pageResult['rows'] === [] && $total > 0 && $move !== 'first') {
        $move = 'first';
        $requestedPage = 1;
        $pageResult = $query->fetch($queue, $status, $search, $perPage, null, 'first');
    }

    $rows = (new CatalogBackgroundJobResultHydrator($application->config))->hydrate($pageResult['rows']);
    $rows = (new CatalogArchiveJobOutcomeProjector($application->db))->project($rows);
    $previousCursor = is_array($pageResult['first_cursor'])
        ? CatalogKeysetPaginator::encode($application->config, $context, $pageResult['first_cursor'])
        : '';
    $nextCursor = is_array($pageResult['last_cursor'])
        ? CatalogKeysetPaginator::encode($application->config, $context, $pageResult['last_cursor'])
        : '';

    JsonResponse::send([
        'data' => ['jobs' => $rows, 'events' => []],
        'meta' => [
            'limit' => $perPage,
            'page' => $requestedPage,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'counts' => $counts,
            'queue' => $queue !== '' ? $queue : null,
            'status' => $status !== '' ? $status : null,
            'search' => $search !== '' ? $search : null,
            'move' => $move,
            'has_previous' => $requestedPage > 1 && (bool)$pageResult['has_previous'],
            'has_next' => $requestedPage < $pages && (bool)$pageResult['has_next'],
            'previous_cursor' => $previousCursor,
            'next_cursor' => $nextCursor,
        ],
    ]);
} catch (Throwable $exception) {
    $requestId = function_exists('catalog_request_id') ? catalog_request_id() : '';
    $message = trim($exception->getMessage()) !== ''
        ? trim($exception->getMessage())
        : get_class($exception) . ' was thrown without an error message.';
    $detail = get_class($exception) . ': ' . $message . ' at '
        . str_replace('\\', '/', $exception->getFile()) . ':' . $exception->getLine();
    error_log('[UnrealDB cursor job status API]'
        . ($requestId !== '' ? '[' . $requestId . ']' : '') . ' ' . $detail);

    if (function_exists('catalog_system_error_record')) {
        try {
            catalog_system_error_record([
                'source_kind' => 'api',
                'severity' => 'critical',
                'error_type' => get_class($exception),
                'message' => $message,
                'http_status' => 503,
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'trace_text' => $exception->getTraceAsString(),
                'request_id' => $requestId,
                'context' => [
                    'endpoint' => 'job-status-cursor',
                    'queue' => isset($queue) ? $queue : '',
                    'status' => isset($status) ? $status : '',
                    'move' => isset($move) ? $move : '',
                ],
            ]);
        } catch (Throwable) {
        }
    }

    JsonResponse::error('unavailable', 'The jobs service is temporarily unavailable.', 503);
}
