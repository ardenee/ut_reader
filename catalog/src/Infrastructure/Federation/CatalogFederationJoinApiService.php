<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation join-request submit, cancel, status and automatic pairing claim lifecycle.
 * Why: Token validation, rate limiting, expiry transitions, compatibility upgrades and pairing state mutations belong to protocol services.
 * Role: Infrastructure federation protocol service preserving existing join response contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;

final class CatalogFederationJoinApiService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/CatalogPublicRateLimit.php';
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function submit(array $payload): array
    {
        $rateLimit = \catalog_public_join_rate_limit('federation_join_submit');
        if (empty($rateLimit['allowed'])) {
            throw new CatalogFederationApiException(
                'Too many federation join requests from this address. Retry after '
                . max(1, (int)($rateLimit['retry_after'] ?? 60)) . ' seconds.',
                429
            );
        }

        $role = \federation_reconcile_site_role($this->db);
        if ($role !== 'parent') {
            throw new CatalogFederationApiException('This server is not currently operating as a Parent.', 409);
        }

        $siteName = trim((string)($payload['site_name'] ?? ''));
        $siteUrl = \fed_canonical_site_url((string)($payload['site_url'] ?? ''));
        $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
        $fingerprint = strtoupper(trim((string)($payload['site_fingerprint'] ?? '')));
        if ($siteName === '' || $siteUrl === '' || $siteId === '' || $fingerprint === '') {
            throw new CatalogFederationApiException(
                'site_name, site_url, site_id, and site_fingerprint are required.',
                400
            );
        }
        if ($siteId === strtolower((string)\fed_setting($this->db, 'site_id', ''))) {
            throw new CatalogFederationApiException('A site cannot join itself.', 409);
        }

        $existingPeer = \catalog_one(
            $this->db,
            'SELECT id,site_name,site_fingerprint,peer_role,is_active FROM ue_federation_peers WHERE site_id=? LIMIT 1',
            [$siteId]
        );
        if ($existingPeer) {
            if ((string)$existingPeer['site_fingerprint'] !== $fingerprint) {
                throw new CatalogFederationApiException(
                    'This site ID is already known with a different fingerprint.',
                    409
                );
            }
            throw new CatalogFederationApiException(
                'This child is already paired with the Parent. Existing peer ID: ' . (int)$existingPeer['id'] . '.',
                409
            );
        }

        $this->db->prepare(
            'DELETE FROM ue_federation_join_requests WHERE site_id=? AND status IN ("denied","expired","cancelled")'
        )->execute([$siteId]);

        $existing = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_join_requests WHERE site_id=? AND status="pending" ORDER BY created_at DESC LIMIT 1',
            [$siteId]
        );
        if ($existing) {
            if (empty($existing['request_token_hash'])) {
                $requestToken = bin2hex(random_bytes(32));
                $this->db->prepare(
                    'UPDATE ue_federation_join_requests SET request_token_hash=? WHERE id=?'
                )->execute([hash('sha256', $requestToken), (int)$existing['id']]);
                return [
                    'ok' => true,
                    'request_id' => (int)$existing['id'],
                    'status' => 'pending',
                    'request_token' => $requestToken,
                    'message' => 'Existing pending request upgraded for automatic status checks.',
                ];
            }
            return [
                'ok' => true,
                'request_id' => (int)$existing['id'],
                'status' => 'pending',
                'message' => 'This child already has a pending request.',
            ];
        }

        $requestToken = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO ue_federation_join_requests('
            . 'site_id,site_name,site_url,site_fingerprint,request_token_hash,status,request_ip,user_agent'
            . ') VALUES(?,?,?,?,?,"pending",?,?)'
        );
        $stmt->execute([
            $siteId,
            $siteName,
            $siteUrl,
            $fingerprint,
            hash('sha256', $requestToken),
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $requestId = (int)$this->db->lastInsertId();
        \fed_log(
            $this->db,
            null,
            null,
            'INFO',
            'JOIN_REQUEST_RECEIVED',
            'Join request #' . $requestId . ' from ' . $siteName . ' (' . $siteId . ')'
        );

        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'pending',
            'request_token' => $requestToken,
            'message' => 'Join request submitted. Waiting for parent admin approval.',
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function cancel(array $payload): array
    {
        $requestId = (int)($payload['request_id'] ?? 0);
        $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
        $requestToken = trim((string)($payload['request_token'] ?? ''));
        if ($requestId <= 0 || $siteId === '' || $requestToken === '') {
            throw new CatalogFederationApiException(
                'request_id, site_id, and request_token are required.',
                400
            );
        }

        $request = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_join_requests WHERE id=? AND site_id=? LIMIT 1',
            [$requestId, $siteId]
        );
        if (!$request) {
            throw new CatalogFederationApiException('Join request not found.', 404);
        }
        $requestTokenHash = hash('sha256', $requestToken);
        if (empty($request['request_token_hash'])
            || !hash_equals((string)$request['request_token_hash'], $requestTokenHash)) {
            throw new CatalogFederationApiException('Bad request token.', 403);
        }
        if ((string)$request['status'] !== 'pending') {
            throw new CatalogFederationApiException('Join request can only be cancelled while pending.', 409);
        }

        $stmt = $this->db->prepare(
            'UPDATE ue_federation_join_requests SET status="cancelled",admin_notes=? WHERE id=? AND status="pending"'
        );
        $stmt->execute(['Cancelled by child before approval.', $requestId]);
        \fed_log(
            $this->db,
            null,
            null,
            'INFO',
            'JOIN_REQUEST_CANCELLED',
            'Join request #' . $requestId . ' cancelled by child ' . $siteId . '.'
        );
        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'cancelled',
            'message' => 'Join request cancelled.',
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function status(array $payload): array
    {
        $requestId = (int)($payload['request_id'] ?? 0);
        $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
        $requestToken = trim((string)($payload['request_token'] ?? ''));
        if ($requestId <= 0 || $siteId === '' || $requestToken === '') {
            throw new CatalogFederationApiException('request_id, site_id, and request_token are required.', 400);
        }

        $request = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_join_requests WHERE id=? AND site_id=? LIMIT 1',
            [$requestId, $siteId]
        );
        if (!$request) {
            throw new CatalogFederationApiException('Join request not found.', 404);
        }

        $requestTokenHash = hash('sha256', $requestToken);
        if (empty($request['request_token_hash'])
            || !hash_equals((string)$request['request_token_hash'], $requestTokenHash)) {
            throw new CatalogFederationApiException('Bad request token.', 403);
        }

        $status = (string)$request['status'];
        if ($status === 'approved'
            && !hash_equals((string)($request['claim_token_hash'] ?? ''), $requestTokenHash)) {
            $ttl = max(600, (int)(\fed_setting($this->db, 'join_claim_token_ttl_seconds', '86400') ?: 86400));
            $this->db->prepare(
                'UPDATE ue_federation_join_requests '
                . 'SET claim_token_hash=request_token_hash,claim_expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND) '
                . 'WHERE id=? AND status="approved"'
            )->execute([$ttl, $requestId]);
            $request['claim_token_hash'] = $requestTokenHash;
            $request['claim_expires_at'] = date('Y-m-d H:i:s', time() + $ttl);
        }

        $response = [
            'ok' => true,
            'request_id' => (int)$request['id'],
            'status' => $status,
            'message' => 'Waiting for parent admin approval.',
        ];

        if ($status === 'denied') {
            $response['message'] = 'Join request denied by parent admin.';
            $response['admin_notes'] = (string)($request['admin_notes'] ?? '');
            return $response;
        }

        if ($status === 'approved' || $status === 'claimed') {
            if ($status === 'approved'
                && !empty($request['claim_expires_at'])
                && strtotime((string)$request['claim_expires_at']) < time()) {
                $this->db->prepare(
                    'UPDATE ue_federation_join_requests SET status="expired",claim_token_hash=NULL WHERE id=?'
                )->execute([(int)$request['id']]);
                return [
                    'ok' => true,
                    'request_id' => (int)$request['id'],
                    'status' => 'expired',
                    'message' => 'Join approval expired. Submit a new request.',
                ];
            }

            $response['message'] = $status === 'claimed'
                ? 'Parent pairing is approved. The child may safely retry automatic pairing if needed.'
                : 'Approved. The child will complete pairing automatically.';
            $response['admin_notes'] = (string)($request['admin_notes'] ?? '');
            $response['claim_ready'] = true;
            $response['claim_endpoint'] = rtrim((string)\fed_setting($this->db, 'site_url', 'upload') ?: '', '/')
                . '/api/federation/join-claim.php';
            return $response;
        }

        if ($status === 'expired') {
            $response['message'] = 'Join approval expired. Submit a new request.';
        }
        return $response;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function claim(array $payload): array
    {
        $requestId = (int)($payload['request_id'] ?? 0);
        $token = trim((string)($payload['token'] ?? ''));
        if ($token === '') {
            throw new CatalogFederationApiException('Missing automatic pairing token.', 400);
        }

        $hash = hash('sha256', $token);
        if ($requestId > 0) {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_join_requests WHERE id=? AND (claim_token_hash=? OR request_token_hash=?) LIMIT 1',
                [$requestId, $hash, $hash]
            );
        } else {
            $request = \catalog_one(
                $this->db,
                'SELECT * FROM ue_federation_join_requests WHERE claim_token_hash=? OR request_token_hash=? ORDER BY id DESC LIMIT 1',
                [$hash, $hash]
            );
        }
        if (!$request) {
            throw new CatalogFederationApiException('Invalid automatic pairing token.', 404);
        }

        $status = (string)$request['status'];
        if (!in_array($status, ['approved', 'claimed'], true)) {
            throw new CatalogFederationApiException('Join request is not pairable from status: ' . $status, 409);
        }

        if ($status === 'approved'
            && !empty($request['claim_expires_at'])
            && strtotime((string)$request['claim_expires_at']) < time()) {
            $this->db->prepare(
                'UPDATE ue_federation_join_requests SET status="expired",claim_token_hash=NULL WHERE id=?'
            )->execute([(int)$request['id']]);
            throw new CatalogFederationApiException('Automatic pairing approval expired.', 410);
        }

        $peer = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_peers WHERE id=? AND is_active=1 LIMIT 1',
            [(int)($request['created_peer_id'] ?? 0)]
        );
        if (!$peer) {
            throw new CatalogFederationApiException(
                'Approved child peer is unavailable. Parent admin must approve a new request.',
                500
            );
        }

        $sharedSecret = \fed_peer_secret($this->db, $peer);
        if ($sharedSecret === '') {
            throw new CatalogFederationApiException(
                'Pairing secret is unavailable. Parent admin must approve a new request.',
                500
            );
        }

        if ($status === 'approved') {
            $claim = $this->db->prepare(
                'UPDATE ue_federation_join_requests SET status="claimed",claimed_at=NOW() WHERE id=? AND status="approved"'
            );
            $claim->execute([(int)$request['id']]);
            if ($claim->rowCount() !== 1) {
                throw new CatalogFederationApiException('Automatic pairing state changed; retry the status check.', 409);
            }
            $status = 'claimed';
        }

        $identity = \fed_ensure_identity($this->db);
        \fed_log(
            $this->db,
            (int)$peer['id'],
            null,
            'INFO',
            'JOIN_PAIRED_AUTOMATICALLY',
            'Join request #' . (int)$request['id'] . ' paired automatically by child.'
        );

        return [
            'ok' => true,
            'parent' => [
                'site_name' => (string)$identity['site_name'],
                'site_url' => (string)$identity['site_url'],
                'site_id' => (string)$identity['site_id'],
                'site_fingerprint' => (string)$identity['site_fingerprint'],
                'peer_role_for_child' => 'parent',
                'shared_secret' => $sharedSecret,
            ],
            'request' => [
                'id' => (int)$request['id'],
                'status' => $status,
            ],
        ];
    }
}
