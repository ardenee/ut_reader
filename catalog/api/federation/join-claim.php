<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for automatic join claim.
 * Why: Method/body parsing and response serialization stay at the API boundary; token/state lifecycle lives in a service.
 * Role: Thin federation HTTP adapter preserving the existing pairing contract.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationJoinApiService;

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        fed_json_response(['ok' => false, 'error' => 'Join claims require POST.'], 405);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $payload = str_contains($contentType, 'application/json')
        ? fed_decode_json_object(fed_read_request_body(16384))
        : $_POST;

    fed_json_response((new CatalogFederationJoinApiService($db))->claim($payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus);
} catch (Throwable $error) {
    error_log('[UnrealDB federation automatic join] ' . get_class($error) . ': ' . $error->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Automatic parent pairing failed.'], 500);
}
