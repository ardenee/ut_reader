<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns the public child-submitted federation join request compatibility flow.
 * Why: The public request_token protocol has different validation and lifecycle semantics from the internal join API.
 * Role: Infrastructure protocol service preserving join-request-submit/cancel compatibility without page-local SQL.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogFederationPublicJoinRequestService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogPublicRateLimit.php';
        require_once $root . '/lib/FederationAuth.php';
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function submit(array $payload): array
    {
        if ((string)\fed_setting($this->db, 'join_requests_enabled', '1') !== '1') {
            throw new CatalogFederationApiException('Join requests are disabled on this parent.', 403);
        }

        $siteName = trim((string)($payload['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string)($payload['site_url'] ?? '')), '/');
        $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
        $fingerprint = strtoupper(trim((string)($payload['site_fingerprint'] ?? '')));
        $requestToken = trim((string)($payload['request_token'] ?? ''));
        $contactName = trim((string)($payload['contact_name'] ?? ''));
        $contactEmail = trim((string)($payload['contact_email'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));

        if ($siteName === '' || strlen($siteName) > 160
            || $siteUrl === '' || strlen($siteUrl) > 1000
            || $siteId === '' || $fingerprint === '' || $requestToken === '') {
            throw new CatalogFederationApiException(
                'Valid site_name, site_url, site_id, site_fingerprint, and request_token values are required.',
                400
            );
        }
        if (preg_match('/^[a-f0-9-]{36}$/', $siteId) !== 1
            || preg_match('/^[A-F0-9]{32}$/', $fingerprint) !== 1
            || strlen($requestToken) < 32 || strlen($requestToken) > 256) {
            throw new CatalogFederationApiException('Federation identity fields are invalid.', 400);
        }

        $url = parse_url($siteUrl);
        if (!is_array($url)
            || strtolower((string)($url['scheme'] ?? '')) !== 'https'
            || trim((string)($url['host'] ?? '')) === ''
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            throw new CatalogFederationApiException('site_url must be a plain HTTPS URL.', 400);
        }
        if ($contactEmail !== ''
            && (strlen($contactEmail) > 255 || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw new CatalogFederationApiException('contact_email is invalid.', 400);
        }
        if (strlen($contactName) > 160 || strlen($notes) > 4000) {
            throw new CatalogFederationApiException('Contact name or notes exceed the allowed length.', 400);
        }

        $expected = \fed_site_fingerprint($siteUrl, $siteId);
        if (!hash_equals($expected, $fingerprint)) {
            throw new CatalogFederationApiException('Fingerprint does not match site_url and site_id.', 400);
        }

        try {
            \catalog_public_join_rate_limit($siteId);
        } catch (RuntimeException $error) {
            if (str_starts_with($error->getMessage(), 'Too many requests.')) {
                throw new CatalogFederationApiException($error->getMessage(), 429, $error);
            }
            throw $error;
        }

        if (\catalog_one(
            $this->db,
            'SELECT id FROM ue_federation_peers '
            . 'WHERE peer_site_id=? AND peer_role="child" AND is_active=1 LIMIT 1',
            [$siteId]
        )) {
            throw new CatalogFederationApiException(
                'This site is already paired as a child. Remove the existing child connection before pairing it again.',
                409
            );
        }

        $tokenHash = hash('sha256', $requestToken);
        $existing = \catalog_one(
            $this->db,
            'SELECT id,status FROM ue_federation_join_requests '
            . 'WHERE site_id=? AND status IN ("pending","approved") ORDER BY id DESC LIMIT 1',
            [$siteId]
        );
        if ($existing) {
            $status = (string)$existing['status'];
            $this->db->prepare(
                'UPDATE ue_federation_join_requests '
                . 'SET site_name=?,site_url=?,site_fingerprint=?,contact_name=?,contact_email=?,notes=?,'
                . 'request_token_hash=?,claim_token_hash=CASE WHEN status="approved" THEN ? ELSE NULL END,'
                . 'updated_at=NOW() WHERE id=?'
            )->execute([
                $siteName,
                $siteUrl,
                $fingerprint,
                $contactName ?: null,
                $contactEmail ?: null,
                $notes ?: null,
                $tokenHash,
                $tokenHash,
                (int)$existing['id'],
            ]);
            \fed_log(
                $this->db,
                null,
                null,
                'INFO',
                'JOIN_REQUEST_API_REFRESHED',
                'Refreshed active join request #' . (int)$existing['id'] . ' for ' . $siteName . '.'
            );
            return [
                'ok' => true,
                'request_id' => (int)$existing['id'],
                'status' => $status,
                'message' => $status === 'approved'
                    ? 'Existing approved join request refreshed. Check status to complete pairing.'
                    : 'Existing pending join request refreshed.',
            ];
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_federation_join_requests('
            . 'status,requested_role,site_name,site_url,site_id,site_fingerprint,'
            . 'contact_name,contact_email,notes,request_token_hash'
            . ') VALUES("pending","child",?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $siteName,
            $siteUrl,
            $siteId,
            $fingerprint,
            $contactName ?: null,
            $contactEmail ?: null,
            $notes ?: null,
            $tokenHash,
        ]);
        $requestId = (int)$this->db->lastInsertId();
        \fed_log(
            $this->db,
            null,
            null,
            'INFO',
            'JOIN_REQUEST_API_SUBMITTED',
            'Auto join request #' . $requestId . ' from ' . $siteName . ' / ' . $siteUrl
        );
        return [
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'pending',
            'message' => 'Join request submitted. Waiting for parent administrator approval.',
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function cancel(array $payload): array
    {
        $requestId = (int)($payload['request_id'] ?? 0);
        $siteId = strtolower(trim((string)($payload['site_id'] ?? '')));
        $token = trim((string)($payload['request_token'] ?? ''));
        if ($requestId <= 0 || $siteId === '' || $token === '') {
            throw new CatalogFederationApiException(
                'request_id, site_id, and request_token are required.',
                400
            );
        }

        $request = \catalog_one(
            $this->db,
            'SELECT * FROM ue_federation_join_requests '
            . 'WHERE id=? AND site_id=? AND status IN ("pending","approved")',
            [$requestId, $siteId]
        );
        if (!$request
            || empty($request['request_token_hash'])
            || !hash_equals((string)$request['request_token_hash'], hash('sha256', $token))) {
            throw new CatalogFederationApiException(
                'Active join request not found or token invalid.',
                404
            );
        }

        $this->db->beginTransaction();
        try {
            $peerId = (int)($request['created_peer_id'] ?? 0);
            if ($peerId > 0 && empty($request['claimed_at'])) {
                $this->db->prepare('DELETE FROM ue_federation_peer_files WHERE peer_id=?')->execute([$peerId]);
                $this->db->prepare('DELETE FROM ue_federation_peers WHERE id=?')->execute([$peerId]);
            }
            $this->db->prepare(
                'UPDATE ue_federation_join_requests '
                . 'SET status="expired",admin_notes="Cancelled by the requesting child before pairing completed.",'
                . 'claim_token_hash=NULL,claim_expires_at=NULL,created_peer_id=NULL WHERE id=?'
            )->execute([$requestId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        \fed_log(
            $this->db,
            null,
            null,
            'INFO',
            'JOIN_REQUEST_CANCELLED_BY_CHILD',
            'Join request #' . $requestId . ' cancelled before pairing completed.'
        );
        return ['ok' => true, 'status' => 'expired', 'message' => 'Join request cancelled.'];
    }
}
