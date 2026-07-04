<?php
declare(strict_types=1);

final class TrustedHttpSourceClient
{
    public static function source(string $baseUrl): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Secure HTTP source scanning requires PHP cURL.');
        }
        $p = parse_url(trim($baseUrl));
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host = strtolower(trim((string)($p['host'] ?? '')));
        $port = isset($p['port']) ? (int)$p['port'] : 443;
        if ($scheme !== 'https' || $host === '' || $port !== 443 || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new RuntimeException('Source must be a plain HTTPS URL on port 443.');
        }
        $path = '/' . trim((string)($p['path'] ?? ''), '/');
        if (str_contains(rawurldecode($path), '..')) {
            throw new RuntimeException('Source path may not contain traversal segments.');
        }
        return ['base' => rtrim('https://' . $host . $path, '/'), 'host' => $host, 'ip' => self::publicIp($host)];
    }

    public static function relativeUrl(array $source, string $relative): string
    {
        $relative = trim($relative);
        $decoded = rawurldecode($relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_starts_with($relative, '\\') || str_contains($relative, '\\') || str_contains($relative, '://') || str_contains($relative, '?') || str_contains($relative, '#') || str_contains($decoded, "\0")) {
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

    public static function bytes(array $source, string $url, int $maxBytes, string $label): string
    {
        $data = '';
        self::request($source, $url, $label, 30, function (string $chunk) use (&$data, $maxBytes): bool {
            if (strlen($data) + strlen($chunk) > $maxBytes) {
                return false;
            }
            $data .= $chunk;
            return true;
        }, $maxBytes);
        return $data;
    }

    public static function toFile(array $source, string $url, string $destination, int $maxBytes, string $label): int
    {
        $out = @fopen($destination, 'xb');
        if ($out === false) {
            throw new RuntimeException('Could not create temporary ' . $label . ' file.');
        }
        $written = 0;
        try {
            self::request($source, $url, $label, 120, function (string $chunk) use ($out, &$written, $maxBytes): bool {
                $length = strlen($chunk);
                if ($written + $length > $maxBytes || fwrite($out, $chunk) !== $length) {
                    return false;
                }
                $written += $length;
                return true;
            }, $maxBytes);
            return $written;
        } catch (Throwable $e) {
            @unlink($destination);
            throw $e;
        } finally {
            fclose($out);
        }
    }

    public static function headSize(array $source, string $url): ?int
    {
        $length = null;
        $curl = self::curl($source, $url, 20);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($h, string $line) use (&$length): int {
            if (stripos($line, 'Content-Length:') === 0) {
                $value = trim(substr($line, 15));
                $length = ctype_digit($value) ? (int)$value : null;
            }
            return strlen($line);
        });
        try {
            self::finish($curl, 'remote metadata', [200, 204]);
            return $length;
        } catch (Throwable) {
            return null;
        }
    }

    private static function request(array $source, string $url, string $label, int $timeout, callable $write, int $maxBytes): void
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Maximum bytes must be positive.');
        }
        $declared = null;
        $curl = self::curl($source, $url, $timeout);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($h, string $line) use (&$declared, $maxBytes): int {
            if (stripos($line, 'Content-Length:') === 0) {
                $value = trim(substr($line, 15));
                $declared = ctype_digit($value) ? (int)$value : null;
                if ($declared !== null && $declared > $maxBytes) {
                    return 0;
                }
            }
            return strlen($line);
        });
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($h, string $chunk) use ($write): int {
            return $write($chunk) ? strlen($chunk) : 0;
        });
        self::finish($curl, $label);
        if ($declared !== null && $declared > $maxBytes) {
            throw new RuntimeException(ucfirst($label) . ' exceeds its configured size limit.');
        }
    }

    private static function curl(array $source, string $url, int $timeout)
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize HTTP request.');
        }
        $ip = str_contains($source['ip'], ':') ? '[' . $source['ip'] . ']' : $source['ip'];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'UnrealDB/1.0 secure-source-scan',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$source['host'] . ':443:' . $ip],
        ]);
        return $curl;
    }

    private static function finish($curl, string $label, array $allowed = [200]): void
    {
        try {
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            if ($ok === false || !in_array($status, $allowed, true)) {
                throw new RuntimeException(ucfirst($label) . ' request failed' . ($status ? ' with HTTP ' . $status : '') . ($error !== '' ? ': ' . $error : '.'));
            }
        } finally {
            curl_close($curl);
        }
    }

    private static function publicIp(string $host): string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
        if (!$ips) {
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ips[] = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            }
            $ips = array_values(array_filter(array_unique($ips)));
        }
        if (!$ips) {
            throw new RuntimeException('Source hostname could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new RuntimeException('Source hostname resolves to a blocked network address.');
            }
        }
        usort($ips, static fn(string $a, string $b): int => (str_contains($a, ':') ? 1 : 0) <=> (str_contains($b, ':') ? 1 : 0));
        return $ips[0];
    }

    private static function isPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $v = array_map('intval', explode('.', $ip));
            [$a, $b] = $v;
            return !($a === 0 || $a === 10 || $a === 127 || $a >= 224 || ($a === 100 && $b >= 64 && $b <= 127) || ($a === 169 && $b === 254) || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && ($b === 0 || $b === 168)) || ($a === 198 && in_array($b, [18, 19, 51], true)) || ($a === 203 && $b === 0));
        }
        $packed = inet_pton($ip);
        if ($packed === false || $packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1") {
            return false;
        }
        $a = ord($packed[0]);
        $b = ord($packed[1]);
        return !((($a & 0xfe) === 0xfc) || ($a === 0xfe && ($b & 0xc0) === 0x80) || $a === 0xff || substr($packed, 0, 4) === inet_pton('2001:db8::'));
    }
}
