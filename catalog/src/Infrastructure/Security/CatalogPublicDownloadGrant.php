<?php
/**
 * Session-bound public individual-file download grants.
 *
 * Public pages may advertise download-info.php freely. The physical download
 * controller requires a short-lived one-time grant issued from that page so
 * copied/crawled download.php?id=... URLs are not reusable public entry points.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

final class CatalogPublicDownloadGrant
{
    private const SESSION_KEY = 'catalog_public_file_download_grants';
    private const TTL_SECONDS = 300;
    private const MAX_GRANTS = 30;

    public function issue(int $fileId): string
    {
        if ($fileId < 1) {
            return '';
        }

        $this->prune();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION[self::SESSION_KEY][(string)$fileId] = [
            'token_hash' => hash('sha256', $token),
            'expires_at' => time() + self::TTL_SECONDS,
        ];

        if (count($_SESSION[self::SESSION_KEY]) > self::MAX_GRANTS) {
            $_SESSION[self::SESSION_KEY] = array_slice(
                $_SESSION[self::SESSION_KEY],
                -self::MAX_GRANTS,
                null,
                true
            );
        }

        return $token;
    }

    public function consume(int $fileId, string $token): bool
    {
        if ($fileId < 1 || $token === '') {
            return false;
        }

        $this->prune();
        $key = (string)$fileId;
        $grant = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (!is_array($grant)) {
            return false;
        }

        $expected = strtolower(trim((string)($grant['token_hash'] ?? '')));
        $expires = (int)($grant['expires_at'] ?? 0);
        $actual = hash('sha256', $token);

        if ($expected === '' || $expires < time() || !hash_equals($expected, $actual)) {
            return false;
        }

        // One click, one transfer attempt. A copied URL cannot be replayed.
        unset($_SESSION[self::SESSION_KEY][$key]);
        return true;
    }

    public function cameFromDownloadInfo(int $fileId): bool
    {
        if ($fileId < 1) {
            return false;
        }

        $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site'], true)) {
            return false;
        }

        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            // Modern browsers normally supply Sec-Fetch-Site even when a
            // privacy policy suppresses Referer. Require one same-site signal.
            return in_array($fetchSite, ['same-origin', 'same-site'], true);
        }

        $parts = parse_url($referer);
        if (!is_array($parts)) {
            return false;
        }

        $refererHost = strtolower(trim((string)($parts['host'] ?? '')));
        $requestHost = self::requestHost();
        if ($refererHost === '' || $requestHost === '' || !hash_equals($requestHost, $refererHost)) {
            return false;
        }

        $path = str_replace('\\', '/', (string)($parts['path'] ?? ''));
        if (strtolower(basename($path)) !== 'download-info.php') {
            return false;
        }

        $params = [];
        parse_str((string)($parts['query'] ?? ''), $params);
        return (int)($params['id'] ?? 0) === $fileId;
    }

    private function prune(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
            return;
        }

        $now = time();
        foreach ($_SESSION[self::SESSION_KEY] as $key => $grant) {
            if (!is_array($grant) || (int)($grant['expires_at'] ?? 0) < $now) {
                unset($_SESSION[self::SESSION_KEY][$key]);
            }
        }
    }

    private static function requestHost(): string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        // HTTP_HOST may include a port. IPv6 literals are bracketed.
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            return $end === false ? strtolower($host) : strtolower(substr($host, 1, $end - 1));
        }

        return strtolower((string)(preg_replace('/:\d+$/', '', $host) ?? $host));
    }
}
