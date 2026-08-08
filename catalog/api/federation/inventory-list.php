<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for inventory list.
 * Why: It exposes verified package inventory as a narrowly scoped signed request.
 * Role: HTTP API entry point; role policy and inventory reads are delegated to the inventory API service.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationInventoryApiService;

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        fed_json_response(['ok' => false, 'error' => 'Inventory requests require POST.'], 405);
    }

    $db = catalog_db(catalog_config());
    $body = fed_read_request_body(32768);
    $peer = fed_require_signed_peer($db, $body);
    $payload = fed_decode_json_object($body);

    fed_json_response((new CatalogFederationInventoryApiService($db))->list($peer, $payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
} catch (Throwable $error) {
    error_log(
        '[UnrealDB][' . catalog_request_id() . '] federation inventory read failed: '
        . get_class($error) . ': ' . $error->getMessage()
    );
    fed_json_response([
        'ok' => false,
        'error' => 'Peer inventory could not be read.',
        'reference' => catalog_request_id(),
    ], 500);
}
