<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical federation streaming-transfer authentication helper API.
 * Why: Transfer cryptography and inbound streaming authentication now live in focused Infrastructure services.
 * Role: Thin compatibility facade; do not add signature, replay-protection, peer-authentication or HTTP response implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationJsonApi;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationStreamingUploadAuthenticator;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferSignatureService;

function fed_transfer_signature_payload(string $method, string $path, string $timestamp, string $nonce, string $sha256, int $bytes, int $remoteId, string $name): string
{
    return CatalogFederationTransferSignatureService::payload(
        $method,
        $path,
        $timestamp,
        $nonce,
        $sha256,
        $bytes,
        $remoteId,
        $name
    );
}

function fed_transfer_signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $sha256, int $bytes, int $remoteId, string $name): string
{
    return CatalogFederationTransferSignatureService::hmac(
        $secret,
        $method,
        $path,
        $timestamp,
        $nonce,
        $sha256,
        $bytes,
        $remoteId,
        $name
    );
}

function fed_transfer_signature_ed25519(string $method, string $path, string $timestamp, string $nonce, string $sha256, int $bytes, int $remoteId, string $name): string
{
    return CatalogFederationTransferSignatureService::ed25519(
        $method,
        $path,
        $timestamp,
        $nonce,
        $sha256,
        $bytes,
        $remoteId,
        $name
    );
}

function fed_verify_transfer_signature_ed25519(string $publicKey, string $signature, string $method, string $path, string $timestamp, string $nonce, string $sha256, int $bytes, int $remoteId, string $name): bool
{
    return CatalogFederationTransferSignatureService::verifyEd25519(
        $publicKey,
        $signature,
        $method,
        $path,
        $timestamp,
        $nonce,
        $sha256,
        $bytes,
        $remoteId,
        $name
    );
}

/** @return array{0:array,1:array{sha256:string,bytes:int,remote_id:int,name:string}} */
function fed_require_streaming_upload_peer(PDO $db): array
{
    try {
        return (new CatalogFederationStreamingUploadAuthenticator($db))->authenticate();
    } catch (CatalogFederationApiException $error) {
        CatalogFederationJsonApi::respond($error->responsePayload(), $error->httpStatus());
    }
}
