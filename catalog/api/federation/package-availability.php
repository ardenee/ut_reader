<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for parent package availability checks.
 * Why: It exposes availability matching as a narrow signed request without duplicating dependency-request policy logic.
 * Role: HTTP API entry point; package matching and base-game policy are delegated to the dependency request service.
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

    fed_json_response((new CatalogFederationDependencyRequestService($db))->availability($peer, $payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response($error->responsePayload(), $error->httpStatus());
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
