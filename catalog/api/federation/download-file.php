<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Streams a verified child file to its paired parent under the supplied federation policy.
 * Why: HTTP response headers and streaming belong at the endpoint; authorization and storage policy do not.
 * Role: HTTP streaming adapter over the federation download authorization service.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationDownloadAuthorizationService;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $body = fed_read_request_body(32768);
    $peer = fed_require_signed_peer($db, $body);
    $payload = fed_decode_json_object($body);

    $transfer = (new CatalogFederationDownloadAuthorizationService($db, $config))
        ->parentPull($peer, $payload);
    $file = $transfer['file'];
    $path = $transfer['path'];
    $isBaseGame = $transfer['is_base_game'];

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes((string)$file['original_name']) . '"');
    header('X-UE-File-Id: ' . (int)$file['id']);
    header('X-UE-Package-Guid: ' . (string)$file['package_guid']);
    header('X-UE-MD5: ' . (string)$file['md5']);
    header('X-UE-SHA1: ' . (string)$file['sha1']);
    header('X-UE-Base-Game: ' . ($isBaseGame ? '1' : '0'));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (CatalogFederationApiException $error) {
    if (!headers_sent()) {
        fed_json_response(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus());
    }
} catch (Throwable $error) {
    if (!headers_sent()) {
        fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
    }
}
