<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Creates and reports the local federation site identity.
 * Why: Site ID generation, URL/name persistence, fingerprinting and public status composition form one identity boundary independent of request authentication.
 * Role: Infrastructure federation identity service preserving the existing site identity and public-status response contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationKeyMaterial;

final class CatalogFederationIdentityService
{
    private readonly CatalogFederationSettingsStore $settings;

    public function __construct(private readonly PDO $db)
    {
        $this->settings = new CatalogFederationSettingsStore($db);
    }

    public static function randomId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function fingerprint(string $siteUrl, string $siteId): string
    {
        return strtoupper(substr(
            hash(
                'sha256',
                rtrim(strtolower(trim($siteUrl)), '/') . '|' . strtolower(trim($siteId))
            ),
            0,
            32
        ));
    }

    /** @return array{site_id:string,site_url:string,site_name:string,site_fingerprint:string,ed25519_public_key:string,ed25519_key_id:string} */
    public function ensure(string $siteUrl = '', string $siteName = ''): array
    {
        $siteId = $this->settings->get('site_id', '') ?: '';
        if ($siteId === '') {
            $siteId = self::randomId();
            $this->settings->set('site_id', $siteId);
        }

        if ($siteUrl !== '') {
            $this->settings->set('site_url', $siteUrl);
        } else {
            $siteUrl = $this->settings->get('site_url', '') ?: '';
        }

        if ($siteName !== '') {
            $this->settings->set('site_name', $siteName);
        } else {
            $siteName = $this->settings->get('site_name', '') ?: '';
        }

        $fingerprint = $siteUrl !== '' ? self::fingerprint($siteUrl, $siteId) : '';
        if ($fingerprint !== '') {
            $this->settings->set('site_fingerprint', $fingerprint);
        }

        $publicKey = CatalogFederationKeyMaterial::ed25519PublicKey();
        return [
            'site_id' => $siteId,
            'site_url' => $siteUrl,
            'site_name' => $siteName,
            'site_fingerprint' => $fingerprint,
            'ed25519_public_key' => $publicKey !== ''
                ? CatalogFederationKeyMaterial::base64UrlEncode($publicKey)
                : '',
            'ed25519_key_id' => CatalogFederationKeyMaterial::ed25519KeyId($publicKey),
        ];
    }

    /** @return array<string,mixed> */
    public function publicStatus(): array
    {
        $identity = $this->ensure();
        return [
            'ok' => true,
            'site_name' => $identity['site_name'],
            'site_url' => $identity['site_url'],
            'site_id' => $identity['site_id'],
            'site_fingerprint' => $identity['site_fingerprint'],
            'site_role' => $this->settings->get('site_role', 'standalone'),
            'parent_enabled' => $this->settings->get('parent_enabled', '0'),
            'child_enabled' => $this->settings->get('child_enabled', '0'),
            'signature_algorithms' => ['hmac-sha256', 'ed25519'],
            'ed25519_public_key' => $identity['ed25519_public_key'],
            'ed25519_key_id' => $identity['ed25519_key_id'],
            'server_time' => date('c'),
        ];
    }
}
