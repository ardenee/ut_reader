<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Validates trusted HTTPS endpoints, manifest-relative URLs and resolved network addresses.
 * Why: URL/path validation and SSRF-resistant DNS/IP policy are security concerns separate from cURL transfer mechanics.
 * Role: Infrastructure HTTP trust policy preserving the established HTTPS-only, port-443 and public-network contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Http;

use RuntimeException;

final class TrustedHttpEndpointPolicy
{
    /** @return array{base:string,host:string,ip:string} */
    public function source(string $baseUrl, bool $allowPrivateNetwork = false): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Secure HTTP requests require PHP cURL.');
        }

        $parts = parse_url(trim($baseUrl));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if (
            $scheme !== 'https'
            || $host === ''
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Source must be a plain HTTPS URL on port 443.');
        }

        $path = '/' . trim((string)($parts['path'] ?? ''), '/');
        if (str_contains(rawurldecode($path), '..')) {
            throw new RuntimeException('Source path may not contain traversal segments.');
        }

        return [
            'base' => rtrim('https://' . $host . $path, '/'),
            'host' => $host,
            'ip' => $this->resolvedIp($host, $allowPrivateNetwork),
        ];
    }

    /** @param array{base:string,host:string,ip:string} $source */
    public function relativeUrl(array $source, string $relative): string
    {
        $relative = trim($relative);
        $decoded = rawurldecode($relative);
        if (
            $relative === ''
            || str_starts_with($relative, '/')
            || str_starts_with($relative, '\\')
            || str_contains($relative, '\\')
            || str_contains($relative, '://')
            || str_contains($relative, '?')
            || str_contains($relative, '#')
            || str_contains($decoded, "\0")
        ) {
            throw new RuntimeException('Manifest entries must be relative paths.');
        }

        $out = [];
        foreach (explode('/', $decoded) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Manifest path contains an invalid segment.');
            }
            $out[] = rawurlencode($part);
        }
        return $source['base'] . '/' . implode('/', $out);
    }

    /** @return list<string> */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $candidate = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($candidate !== '') {
                    $ips[] = $candidate;
                }
            }
        }

        // dns_get_record() queries DNS directly and can miss Windows hosts-file,
        // DNS suffix, LLMNR, and NetBIOS resolution. The socket resolver used by
        // gethostbynamel()/gethostbyname() follows the operating-system resolver.
        foreach (@gethostbynamel($host) ?: [] as $candidate) {
            $ips[] = (string)$candidate;
        }
        $single = @gethostbyname($host);
        if (is_string($single) && $single !== '' && strcasecmp($single, $host) !== 0) {
            $ips[] = $single;
        }

        return array_values(array_unique(array_filter(
            $ips,
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        )));
    }

    private function resolvedIp(string $host, bool $allowPrivateNetwork): string
    {
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new RuntimeException('Source hostname could not be resolved by PHP: ' . $host);
        }
        if (!$allowPrivateNetwork) {
            foreach ($ips as $ip) {
                if (!$this->isPublic($ip)) {
                    throw new RuntimeException('Source hostname resolves to a blocked network address.');
                }
            }
        }

        usort(
            $ips,
            static fn(string $left, string $right): int =>
                (str_contains($left, ':') ? 1 : 0) <=> (str_contains($right, ':') ? 1 : 0)
        );
        return $ips[0];
    }

    private function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $value = array_map('intval', explode('.', $ip));
            [$a, $b] = $value;
            return !(
                $a === 0
                || $a === 10
                || $a === 127
                || $a >= 224
                || ($a === 100 && $b >= 64 && $b <= 127)
                || ($a === 169 && $b === 254)
                || ($a === 172 && $b >= 16 && $b <= 31)
                || ($a === 192 && ($b === 0 || $b === 168))
                || ($a === 198 && in_array($b, [18, 19, 51], true))
                || ($a === 203 && $b === 0)
            );
        }

        $packed = inet_pton($ip);
        if (
            $packed === false
            || $packed === str_repeat("\0", 16)
            || $packed === str_repeat("\0", 15) . "\1"
        ) {
            return false;
        }
        $a = ord($packed[0]);
        $b = ord($packed[1]);
        return !(
            (($a & 0xfe) === 0xfc)
            || ($a === 0xfe && ($b & 0xc0) === 0x80)
            || $a === 0xff
            || substr($packed, 0, 4) === inet_pton('2001:db8::')
        );
    }
}
