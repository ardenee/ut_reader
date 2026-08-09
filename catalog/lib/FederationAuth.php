<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves historical federation authentication, identity and settings helper APIs.
 * Why: Focused signing, authentication, signed transport, secret/key, settings and identity implementations now live under Infrastructure while legacy callers keep stable function names.
 * Role: Transitional compatibility facade plus remaining federation HTTP body/log/response helpers awaiting separate extraction.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationApiException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationIdentityService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationOutgoingSignaturePolicy;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestSignatureService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationSettingsStore;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationSignedJsonClient;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationSignedRequestAuthenticator;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationPeerSecretService;
use UnrealDb\Catalog\Infrastructure\Security\FederationSecretStore;

require_once __DIR__ . '/CatalogSupport.php';

function fed_random_id(): string
{
    return CatalogFederationIdentityService::randomId();
}

function fed_random_secret(): string
{
    return CatalogFederationKeyMaterial::randomSecret();
}

function fed_base64url_encode(string $bytes): string
{
    return CatalogFederationKeyMaterial::base64UrlEncode($bytes);
}

function fed_base64url_decode(string $value): string
{
    return CatalogFederationKeyMaterial::base64UrlDecode($value);
}

function fed_ed25519_secret_key(): string
{
    return CatalogFederationKeyMaterial::ed25519SecretKey();
}

function fed_ed25519_public_key(): string
{
    return CatalogFederationKeyMaterial::ed25519PublicKey();
}

function fed_ed25519_key_id(string $publicKey): string
{
    return CatalogFederationKeyMaterial::ed25519KeyId($publicKey);
}

function fed_secret_store(): FederationSecretStore
{
    return CatalogFederationPeerSecretService::store();
}

function fed_require_encrypted_secrets(): bool
{
    return CatalogFederationPeerSecretService::requireEncryptedSecrets();
}

/** @return array{hash:string,stored:string} */
function fed_prepare_peer_secret(string $secret): array
{
    return CatalogFederationPeerSecretService::prepare($secret);
}

function fed_secret_for_crypto(string $stored): string
{
    return CatalogFederationPeerSecretService::forCrypto($stored);
}

function fed_peer_secret(PDO $db, array $peer, bool $migratePlaintext = true): string
{
    return (new CatalogFederationPeerSecretService($db))->peerSecret($peer, $migratePlaintext);
}

/** @return array{migrated:int,encrypted:int,missing:int} */
function fed_migrate_peer_secrets(PDO $db): array
{
    return (new CatalogFederationPeerSecretService($db))->migrateAll();
}

function fed_setting(PDO $db, string $name, ?string $default = null): ?string
{
    return (new CatalogFederationSettingsStore($db))->get($name, $default);
}

function fed_set_setting(PDO $db, string $name, string $value): void
{
    (new CatalogFederationSettingsStore($db))->set($name, $value);
}

function fed_all_settings(PDO $db): array
{
    return (new CatalogFederationSettingsStore($db))->all();
}

function fed_site_fingerprint(string $siteUrl, string $siteId): string
{
    return CatalogFederationIdentityService::fingerprint($siteUrl, $siteId);
}

function fed_ensure_identity(PDO $db, string $siteUrl = '', string $siteName = ''): array
{
    return (new CatalogFederationIdentityService($db))->ensure($siteUrl, $siteName);
}

function fed_log(PDO $db, ?int $peerId, ?int $jobId, string $level, string $event, string $details = ''): void
{
    $stmt = $db->prepare('INSERT INTO ue_federation_transfer_logs(peer_id, transfer_job_id, level, event, details) VALUES(?,?,?,?,?)');
    $stmt->execute([$peerId, $jobId, $level, $event, $details]);
}

function fed_request_body_limit_bytes(int $default = 1048576): int
{
    $configured = (int)(getenv('UNREALDB_FEDERATION_MAX_JSON_BYTES') ?: 0);
    return max(1024, min($configured > 0 ? $configured : $default, 64 * 1024 * 1024));
}

function fed_read_request_body(?int $maxBytes = null): string
{
    $limit = $maxBytes ?? fed_request_body_limit_bytes();
    $limit = max(1024, min($limit, 64 * 1024 * 1024));
    $declaredLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($declaredLength !== false && $declaredLength !== null && (int)$declaredLength > $limit) {
        fed_json_response(['ok' => false, 'error' => 'Request body exceeds the allowed size.'], 413);
    }
    $stream = fopen('php://input', 'rb');
    if (!is_resource($stream)) {
        fed_json_response(['ok' => false, 'error' => 'Request body could not be read.'], 400);
    }
    try {
        $body = stream_get_contents($stream, $limit + 1);
    } finally {
        fclose($stream);
    }
    if (!is_string($body)) {
        fed_json_response(['ok' => false, 'error' => 'Request body could not be read.'], 400);
    }
    if (strlen($body) > $limit) {
        fed_json_response(['ok' => false, 'error' => 'Request body exceeds the allowed size.'], 413);
    }
    return $body;
}

/** @return array<string,mixed> */
function fed_decode_json_object(string $body): array
{
    try {
        $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
    }
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'JSON payload must be an object.'], 400);
    }
    return $payload;
}

function fed_body_hash(string $body): string
{
    return CatalogFederationRequestSignatureService::bodyHash($body);
}

function fed_signature_payload(string $method, string $path, string $timestamp, string $nonce, string $bodyHash): string
{
    return CatalogFederationRequestSignatureService::payload($method, $path, $timestamp, $nonce, $bodyHash);
}

function fed_sign_request(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    return CatalogFederationRequestSignatureService::hmac($secret, $method, $path, $timestamp, $nonce, $body);
}

function fed_verify_signature(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature): bool
{
    return CatalogFederationRequestSignatureService::verifyHmac(
        $secret,
        $method,
        $path,
        $timestamp,
        $nonce,
        $body,
        $signature
    );
}

function fed_sign_request_ed25519(string $method, string $path, string $timestamp, string $nonce, string $body): string
{
    return CatalogFederationRequestSignatureService::ed25519($method, $path, $timestamp, $nonce, $body);
}

function fed_verify_signature_ed25519(string $publicKey, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature): bool
{
    return CatalogFederationRequestSignatureService::verifyEd25519(
        $publicKey,
        $method,
        $path,
        $timestamp,
        $nonce,
        $body,
        $signature
    );
}

function fed_request_path(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $pos = strpos($uri, '?');
    return $pos === false ? $uri : substr($uri, 0, $pos);
}

function fed_require_signed_peer(PDO $db, string $body): array
{
    try {
        return (new CatalogFederationSignedRequestAuthenticator($db))->authenticate($body);
    } catch (CatalogFederationApiException $error) {
        fed_json_response($error->responsePayload(), $error->httpStatus());
        throw $error;
    }
}

function fed_outgoing_signature_algorithm(): string
{
    return CatalogFederationOutgoingSignaturePolicy::resolve();
}

function fed_http_post_signed(string $url, string $siteId, string $secret, array $payload): array
{
    return CatalogFederationSignedJsonClient::post(
        $url,
        $siteId,
        $secret,
        $payload,
        fed_request_body_limit_bytes(8388608),
        60
    );
}

function fed_public_status(PDO $db): array
{
    return (new CatalogFederationIdentityService($db))->publicStatus();
}

function fed_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
