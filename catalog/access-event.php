<?php
/**
 * Lightweight first-party interaction/section telemetry endpoint.
 *
 * The client sends only coarse page/section/action labels. Server-derived IP,
 * user/session identity and user agent are recorded by the telemetry service.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogAccessEventRecorder;

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        exit;
    }

    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site'], true)) {
        http_response_code(403);
        exit;
    }

    $config = catalog_config();
    $db = catalog_db($config);

    // Interaction telemetry must not become a write-amplification endpoint.
    // Drop excess events silently; the UI never waits for telemetry.
    $retry = (new CatalogPublicAccessGuard($config))->rateLimit(
        $db,
        'access-matrix-event',
        1200,
        600
    );
    if ($retry > 0) {
        http_response_code(204);
        exit;
    }

    (new CatalogAccessEventRecorder($db))->recordClientEvent($_POST);
    http_response_code(204);
} catch (Throwable $error) {
    error_log('[UnrealDB access matrix endpoint] ' . $error->getMessage());
    http_response_code(204);
}
