<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Streams an approved federation dependency request file to a paired child.
 * Why: HTTP response headers and streaming belong at the endpoint; authorization and lifecycle state do not.
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
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $transfer = (new CatalogFederationDownloadAuthorizationService($db, $config))
        ->approvedDependency($peer, $payload);
    $item = $transfer['file'];
    $path = $transfer['path'];
    $itemId = $transfer['item_id'];
    $isBaseGame = $transfer['is_base_game'];

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes((string)$item['original_name']) . '"');
    header('X-UE-Request-Item-Id: ' . $itemId);
    header('X-UE-File-Id: ' . (int)$item['local_file_id']);
    header('X-UE-Package-Guid: ' . (string)$item['package_guid']);
    header('X-UE-MD5: ' . (string)$item['md5']);
    header('X-UE-SHA1: ' . (string)$item['sha1']);
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
