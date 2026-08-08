<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for inventory push.
 * Why: It exposes inbound peer inventory as a narrowly scoped machine-readable request.
 * Role: HTTP API entry point; validation, policy filtering and persistence are delegated to the inventory push service.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationInventoryPushService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $service = new CatalogFederationInventoryPushService($db);
    $body = fed_read_request_body($service->maxPayloadBytes());
    $peer = fed_require_signed_peer($db, $body);
    $payload = fed_decode_json_object($body);

    fed_json_response($service->push($peer, $payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
} catch (Throwable $error) {
    error_log(
        '[UnrealDB][' . catalog_request_id() . '] federation inventory push failed: '
        . get_class($error) . ': ' . $error->getMessage()
    );
    fed_json_response([
        'ok' => false,
        'error' => 'Inventory could not be processed.',
        'reference' => catalog_request_id(),
    ], 500);
}
