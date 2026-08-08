<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles authenticated child-to-parent federation file uploads.
 * Why: Streaming authentication belongs at the HTTP boundary; hashing, durable staging and transfer persistence do not.
 * Role: HTTP adapter over the federation streaming upload service.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/FederationTransferAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationStreamingUploadService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    [$peer, $meta] = fed_require_streaming_upload_peer($db);

    fed_json_response((new CatalogFederationStreamingUploadService($db, $config))->receive($peer, $meta));
} catch (CatalogFederationApiException $error) {
    fed_json_response($error->responsePayload(), $error->httpStatus());
} catch (Throwable $error) {
    error_log('[UnrealDB federation upload] ' . get_class($error) . ': ' . $error->getMessage());
    fed_json_response(['ok' => false, 'error' => 'Upload could not be completed.'], 500);
}
