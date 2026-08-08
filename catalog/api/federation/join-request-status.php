<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for join-request status.
 * Why: Method/body parsing and response serialization stay at the API boundary; token/state lifecycle lives in a service.
 * Role: Thin federation HTTP adapter preserving the existing status/pairing contract.
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
        fed_json_response(['ok' => false, 'error' => 'Join request status requires POST.'], 405);
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $payload = fed_decode_json_object(fed_read_request_body(32768));

    $result = (new CatalogFederationJoinApiService($db))->status($payload);
    if (!empty($result['claim_ready'])) {
        $result['claim_endpoint'] = rtrim((string)fed_setting($db, 'site_url', ''), '/')
            . '/api/federation/join-claim.php';
    }
    fed_json_response($result);
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus);
} catch (Throwable $error) {
    error_log('[UnrealDB federation join status] ' . get_class($error) . ': ' . $error->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Join request status failed.'], 500);
}
