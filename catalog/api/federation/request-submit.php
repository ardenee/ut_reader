<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for dependency request submission.
 * Why: It accepts old per-object and current per-package child request formats without embedding protocol state in the endpoint.
 * Role: HTTP API entry point; normalization, policy filtering, availability matching and persistence are delegated to a protocol service.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationDependencyRequestService;

try {
    $db = catalog_db(catalog_config());
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    fed_json_response((new CatalogFederationDependencyRequestService($db))->submit($peer, $payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response($error->responsePayload(), $error->httpStatus());
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
