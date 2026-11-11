<?php
/**
 * feedback.php is a site-navigation destination, not a public entrypoint.
 * Anonymous direct/cross-site GET/HEAD requests are denied and public source
 * IPs are persisted in the existing site-wide blocklist.
 *
 * Referer is a navigation signal, not cryptographic proof: clients may forge
 * it. The rule is deliberately strict as an anti-scraper operational policy.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;
use Throwable;

final class CatalogFeedbackEntryGuard
{
    /** @param array<string,mixed> $server */
    public static function isSiteNavigation(array $server): bool
    {
        $referer = trim((string)($server['HTTP_REFERER'] ?? ''));
        if ($referer === '' || strlen($referer) > 4096
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $referer) === 1) {
            return false;
        }
        $fetchSite = strtolower(trim((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site'], true)) {
            return false;
        }
        $refererUrl = parse_url($referer);
        if (!is_array($refererUrl) || isset($refererUrl['user']) || isset($refererUrl['pass'])
            || !isset($refererUrl['host'], $refererUrl['scheme'])) {
            return false;
        }
        $scheme = strtolower((string)$refererUrl['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        $siteHost = trim((string)($server['HTTP_HOST'] ?? ''));
        $siteUrl = parse_url('http://' . $siteHost);
        if (!is_array($siteUrl) || !isset($siteUrl['host'])) return false;
        $host = strtolower((string)$siteUrl['host']);
        $allowedHosts = [
            'unrealdb.com', 'www.unrealdb.com',
            '192.168.0.55', '192.168.0.51',
            '127.0.0.1', 'localhost',
        ];
        if (!in_array($host, $allowedHosts, true)
            || !hash_equals($host, strtolower((string)$refererUrl['host']))) {
            return false;
        }
        // Do not accept navigation from an unrelated HTTP service on another
        // port, e.g. a hostile site hosted alongside the catalogue.
        $sitePort = (int)($siteUrl['port'] ?? ($scheme === 'https' ? 443 : 80));
        $refererPort = (int)($refererUrl['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($sitePort !== $refererPort) return false;
        $secure = (!empty($server['HTTPS'])
                && strtolower((string)$server['HTTPS']) !== 'off')
            || (int)($server['SERVER_PORT'] ?? 0) === 443;
        if ($secure && $scheme !== 'https') return false;
        $path = (string)($refererUrl['path'] ?? '');
        return str_starts_with(strtolower($path), '/catalog/')
            && $path !== '/catalog/';
    }

    /** @param array<string,mixed> $server */
    public static function mustReject(array $server, bool $admin, bool $hasDiagnosticRedirect): bool
    {
        $method = strtoupper(trim((string)($server['REQUEST_METHOD'] ?? 'GET')));
        if (!in_array($method, ['GET', 'HEAD'], true) || $admin || $hasDiagnosticRedirect) {
            return false;
        }
        return !self::isSiteNavigation($server);
    }

    public static function isPublicClientIp(string $ip): bool
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Some PHP versions still classify RFC 5737 documentation ranges as
        // globally routable. Never persist such non-client identifiers.
        $packed = @inet_pton($ip);
        if (!is_string($packed)) return false;
        if (strlen($packed) === 4) {
            $octets = array_values(unpack('C4', $packed));
            if (($octets[0] === 192 && $octets[1] === 0 && $octets[2] === 2)
                || ($octets[0] === 198 && $octets[1] === 51 && $octets[2] === 100)
                || ($octets[0] === 203 && $octets[1] === 0 && $octets[2] === 113)) {
                return false;
            }
        }
        if (strlen($packed) === 16 && substr($packed, 0, 4) === "\x20\x01\x0d\xb8") {
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $config */
    public static function enforce(PDO $db, array $config, bool $hasDiagnosticRedirect = false): void
    {
        if (PHP_SAPI === 'cli' || !self::mustReject(
            $_SERVER,
            function_exists('catalog_support_is_admin') && \catalog_support_is_admin(),
            $hasDiagnosticRedirect
        )) return;

        // Derive from the actual network peer, not spoofable forwarded headers.
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (self::isPublicClientIp($ip)) {
            try {
                (new CatalogSiteBlocklist($db, $config))->block(
                    $ip,
                    null,
                    'Automatic site block: direct or external feedback.php GET/HEAD without same-origin catalogue Referer'
                );
            } catch (Throwable $error) {
                error_log('[UnrealDB feedback entry guard] Failed to persist IP block: ' . $error->getMessage());
            }
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
        echo 'Feedback is accessible through links on UnrealDB.';
        exit;
    }
}
