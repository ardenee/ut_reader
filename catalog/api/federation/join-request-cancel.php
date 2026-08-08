<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the public federation join-request cancellation endpoint.
 * Why: It exposes cancellation of the compatibility request_token pairing flow as a narrow machine-readable API.
 * Role: HTTP API entry point; token validation, peer cleanup and join-request persistence are delegated to a protocol service.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationPublicJoinRequestService;

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        fed_json_response(['ok' => false, 'error' => 'Only POST is supported.'], 405);
    }

    $db = catalog_db(catalog_config());
    $payload = fed_decode_json_object(fed_read_request_body(262144));
    fed_json_response((new CatalogFederationPublicJoinRequestService($db))->cancel($payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
