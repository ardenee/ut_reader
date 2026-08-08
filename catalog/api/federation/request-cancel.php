<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the signed federation request-cancel HTTP endpoint.
 * Why: Authentication/JSON/serialization stays at the API boundary; request lifecycle mutations live in a shared service.
 * Role: Thin federation HTTP adapter preserving the existing status/message contract.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestApiService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $result = (new CatalogFederationRequestApiService($db))->cancelRequest($peer, $payload);
    fed_json_response($result);
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus);
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
