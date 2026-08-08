<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for request status.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; request lifecycle reads are delegated to the federation request-status service.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestStatusService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    fed_json_response((new CatalogFederationRequestStatusService($db, $config))->status($peer, $payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
