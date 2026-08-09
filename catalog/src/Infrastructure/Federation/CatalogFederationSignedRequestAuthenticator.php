<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Authenticates ordinary signed federation API requests and records replay/peer activity state.
 * Why: Header parsing, peer lookup, timestamp/nonce enforcement and signature verification form one inbound authentication boundary and should not emit HTTP responses directly.
 * Role: Infrastructure federation authentication service preserving existing signed-request status codes, messages, logging and replay semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationPeerSecretService;

final class CatalogFederationSignedRequestAuthenticator
{
    private readonly CatalogFederationPeerSecretService $peerSecrets;

    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        $this->peerSecrets = new CatalogFederationPeerSecretService($db);
    }

    /** @param array<string,mixed>|null $server @return array<string,mixed> */
    public function authenticate(string $body, ?array $server = null): array
    {
        $server ??= $_SERVER;

        $siteId = (string)($server['HTTP_X_SITE_ID'] ?? '');
        $timestamp = (string)($server['HTTP_X_TIMESTAMP'] ?? '');
        $nonce = (string)($server['HTTP_X_NONCE'] ?? '');
        $signature = (string)($server['HTTP_X_SIGNATURE'] ?? '');
        $algorithm = strtolower(trim((string)($server['HTTP_X_SIGNATURE_ALGORITHM'] ?? 'hmac-sha256')));
        $keyId = trim((string)($server['HTTP_X_KEY_ID'] ?? ''));

        if ($siteId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new CatalogFederationApiException('Missing federation auth headers', 401);
        }

        $peer = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE peer_site_id=? AND is_active=1',
            [$siteId]
        );
        if (!$peer) {
            throw new CatalogFederationApiException('Unknown or inactive peer', 403);
        }

        $nonceTtl = (int)(\fed_setting($this->db, 'api_nonce_ttl_seconds', '300') ?: 300);
        $ts = strtotime($timestamp);
        if ($ts === false || abs(time() - $ts) > $nonceTtl) {
            throw new CatalogFederationApiException('Timestamp outside allowed window', 401);
        }
        if (\catalog_one($this->db, 'SELECT id FROM ue_federation_nonces WHERE nonce=?', [$nonce])) {
            throw new CatalogFederationApiException('Nonce already used', 401);
        }

        $requestPath = self::requestPath($server);
        $method = (string)($server['REQUEST_METHOD'] ?? 'GET');
        $verified = false;

        if ($algorithm === 'ed25519') {
            $publicKey = trim((string)($peer['signing_public_key'] ?? ''));
            $configuredKeyId = trim((string)($peer['signing_key_id'] ?? ''));
            $revoked = !empty($peer['signing_revoked_at']);
            if ($publicKey === ''
                || $revoked
                || ($keyId !== ''
                    && $configuredKeyId !== ''
                    && !hash_equals($configuredKeyId, $keyId))) {
                \fed_log(
                    $this->db,
                    (int)$peer['id'],
                    null,
                    'WARN',
                    'SIGNING_KEY_REJECTED',
                    $requestPath
                );
                throw new CatalogFederationApiException(
                    'Peer signing key is unavailable or revoked',
                    401
                );
            }

            $verified = CatalogFederationRequestSignatureService::verifyEd25519(
                $publicKey,
                $method,
                $requestPath,
                $timestamp,
                $nonce,
                $body,
                $signature
            );
        } elseif ($algorithm === 'hmac-sha256' || $algorithm === 'hmac') {
            $secret = $this->peerSecrets->peerSecret($peer);
            if ($secret === '') {
                throw new CatalogFederationApiException('Peer has no API secret stored.', 501);
            }
            $verified = CatalogFederationRequestSignatureService::verifyHmac(
                $secret,
                $method,
                $requestPath,
                $timestamp,
                $nonce,
                $body,
                $signature
            );
            $algorithm = 'hmac-sha256';
        } else {
            throw new CatalogFederationApiException('Unsupported signature algorithm', 401);
        }

        if (!$verified) {
            \fed_log(
                $this->db,
                (int)$peer['id'],
                null,
                'WARN',
                'SIGNATURE_FAIL',
                $algorithm . ' ' . $requestPath
            );
            throw new CatalogFederationApiException('Invalid signature', 401);
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_federation_nonces(peer_id, nonce) VALUES(?,?)'
        );
        $statement->execute([(int)$peer['id'], $nonce]);

        $statement = $this->db->prepare(
            'UPDATE ue_federation_peers SET last_seen_at=NOW() WHERE id=?'
        );
        $statement->execute([(int)$peer['id']]);

        return $peer;
    }

    /** @param array<string,mixed> $server */
    private static function requestPath(array $server): string
    {
        $uri = (string)($server['REQUEST_URI'] ?? '/');
        $position = strpos($uri, '?');
        return $position === false ? $uri : substr($uri, 0, $position);
    }
}
