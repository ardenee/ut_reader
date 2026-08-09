<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Authenticates signed federation streaming uploads and records replay/peer activity state.
 * Why: Header validation, peer lookup, timestamp/nonce enforcement and signature verification form one inbound authentication boundary and should not emit HTTP responses directly.
 * Role: Infrastructure federation authentication service preserving existing streaming-upload status codes, messages and replay semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use PDOException;

final class CatalogFederationStreamingUploadAuthenticator
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
    }

    /**
     * @param array<string,mixed>|null $server
     * @return array{0:array<string,mixed>,1:array{sha256:string,bytes:int,remote_id:int,name:string}}
     */
    public function authenticate(?array $server = null): array
    {
        $server ??= $_SERVER;

        $siteId = trim((string)($server['HTTP_X_SITE_ID'] ?? ''));
        $timestamp = trim((string)($server['HTTP_X_TIMESTAMP'] ?? ''));
        $nonce = trim((string)($server['HTTP_X_NONCE'] ?? ''));
        $signature = trim((string)($server['HTTP_X_SIGNATURE'] ?? ''));
        $algorithm = strtolower(trim((string)($server['HTTP_X_SIGNATURE_ALGORITHM'] ?? 'hmac-sha256')));
        $keyId = trim((string)($server['HTTP_X_KEY_ID'] ?? ''));
        $sha256 = strtolower(trim((string)($server['HTTP_X_UE_SHA256'] ?? '')));
        $bytes = (int)($server['HTTP_X_UE_FILE_SIZE'] ?? 0);
        $contentLength = (int)($server['CONTENT_LENGTH'] ?? 0);
        $remoteId = max(0, (int)($server['HTTP_X_UE_REMOTE_FILE_ID'] ?? 0));
        $name = trim((string)($server['HTTP_X_UE_ORIGINAL_NAME'] ?? ''));

        if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'PUT'
            || $siteId === ''
            || $timestamp === ''
            || $nonce === ''
            || $signature === ''
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1
            || $bytes < 1
            || $bytes !== $contentLength
            || $name === ''
            || str_contains($name, "\r")
            || str_contains($name, "\n")
            || str_contains($name, "\0")) {
            throw new CatalogFederationApiException('Invalid streaming upload headers', 400);
        }

        $peer = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE peer_site_id=? AND is_active=1',
            [$siteId]
        );
        if (!$peer) {
            throw new CatalogFederationApiException('Unknown or inactive peer', 403);
        }

        $ts = strtotime($timestamp);
        $ttl = (int)(\fed_setting($this->db, 'api_nonce_ttl_seconds', '300') ?: 300);
        if ($ts === false
            || abs(time() - $ts) > $ttl
            || \catalog_one($this->db, 'SELECT id FROM ue_federation_nonces WHERE nonce=?', [$nonce])) {
            throw new CatalogFederationApiException('Expired or reused transfer credentials', 401);
        }

        $requestPath = self::requestPath($server);
        $verified = false;
        if ($algorithm === 'ed25519') {
            $publicKey = trim((string)($peer['signing_public_key'] ?? ''));
            $configuredKeyId = trim((string)($peer['signing_key_id'] ?? ''));
            if ($publicKey === ''
                || !empty($peer['signing_revoked_at'])
                || ($keyId !== ''
                    && $configuredKeyId !== ''
                    && !hash_equals($configuredKeyId, $keyId))) {
                throw new CatalogFederationApiException(
                    'Peer signing key is unavailable or revoked',
                    401
                );
            }
            $verified = CatalogFederationTransferSignatureService::verifyEd25519(
                $publicKey,
                $signature,
                'PUT',
                $requestPath,
                $timestamp,
                $nonce,
                $sha256,
                $bytes,
                $remoteId,
                $name
            );
        } elseif ($algorithm === 'hmac' || $algorithm === 'hmac-sha256') {
            $secret = \fed_peer_secret($this->db, $peer);
            if ($secret === '') {
                throw new CatalogFederationApiException('Unknown or inactive peer', 403);
            }
            $expected = CatalogFederationTransferSignatureService::hmac(
                $secret,
                'PUT',
                $requestPath,
                $timestamp,
                $nonce,
                $sha256,
                $bytes,
                $remoteId,
                $name
            );
            $verified = hash_equals($expected, $signature);
            $algorithm = 'hmac-sha256';
        }

        if (!$verified) {
            \fed_log(
                $this->db,
                (int)$peer['id'],
                null,
                'WARN',
                'TRANSFER_SIGNATURE_FAIL',
                $algorithm . ' ' . $requestPath
            );
            throw new CatalogFederationApiException('Invalid transfer signature', 401);
        }

        try {
            $this->db->prepare(
                'INSERT INTO ue_federation_nonces(peer_id, nonce) VALUES(?,?)'
            )->execute([(int)$peer['id'], $nonce]);
        } catch (PDOException $error) {
            throw new CatalogFederationApiException('Nonce already used', 401, $error);
        }

        $this->db->prepare(
            'UPDATE ue_federation_peers SET last_seen_at=NOW() WHERE id=?'
        )->execute([(int)$peer['id']]);

        return [
            $peer,
            [
                'sha256' => $sha256,
                'bytes' => $bytes,
                'remote_id' => $remoteId,
                'name' => $name,
            ],
        ];
    }

    /** @param array<string,mixed> $server */
    private static function requestPath(array $server): string
    {
        $uri = (string)($server['REQUEST_URI'] ?? '/');
        $position = strpos($uri, '?');
        return $position === false ? $uri : substr($uri, 0, $position);
    }
}
