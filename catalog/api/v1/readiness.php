<?php
/**
 * Production readiness endpoint for load balancers and service managers.
 *
 * This endpoint is session-free and intentionally returns only dependency names,
 * status and bounded latency measurements. It does not expose connection strings,
 * filesystem paths, SQL errors or credentials.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap/operational.php';

use UnrealDb\Catalog\Infrastructure\Composition\CatalogSystemReadinessFactory;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_operational_application();
    $report = CatalogSystemReadinessFactory::create($application->db, $application->config)->check();

    JsonResponse::send([
        'data' => [
            'status' => $report->ready ? 'ready' : 'not_ready',
            'service' => 'unrealdb-catalog',
            'time' => gmdate('c'),
            'checks' => $report->checkData(),
        ],
    ], $report->ready ? 200 : 503);
} catch (Throwable $exception) {
    error_log('[UnrealDB readiness] ' . $exception->getMessage());
    JsonResponse::send([
        'data' => [
            'status' => 'not_ready',
            'service' => 'unrealdb-catalog',
            'time' => gmdate('c'),
            'checks' => [],
        ],
    ], 503);
}
