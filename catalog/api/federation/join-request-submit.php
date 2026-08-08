<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the public federation join-request submission endpoint.
 * Why: It exposes the compatibility request_token pairing flow as a narrow machine-readable API.
 * Role: HTTP API entry point; validation, rate limiting and join-request persistence are delegated to a protocol service.
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

    $config = catalog_config();
    $db = catalog_db($config);
    $body = fed_read_request_body(min(fed_request_body_limit_bytes(65536), 262144));
    $payload = fed_decode_json_object($body);

    fed_json_response((new CatalogFederationPublicJoinRequestService($db))->submit($payload));
} catch (CatalogFederationApiException $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB federation join][' . $requestId . '] ' . $error->getMessage());
    $safeDetails = [
        'Request rate-limit storage is unavailable.',
        'Could not create request rate-limit storage.',
        'Could not lock request rate-limit state.',
        'Could not persist request rate-limit state.',
    ];
    $publicError = in_array($error->getMessage(), $safeDetails, true)
        ? $error->getMessage()
        : 'Join request could not be processed.';
    fed_json_response([
        'ok' => false,
        'error' => $publicError,
        'reference' => $requestId,
    ], 503);
}
