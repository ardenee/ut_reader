<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog support for trusted HTTP source client, centered on `TrustedHttpSourceClient`.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

final class TrustedHttpSourceClient
{
    private static bool $allowUntrustedTls = false;
    private static bool $allowPrivateNetwork = false;
    private static ?bool $federationTestingConfigured = null;

    public static function configureFederationTesting(bool $enabled): void
    {
        self::$allowUntrustedTls = $enabled;
        self::$allowPrivateNetwork = $enabled;
        self::$federationTestingConfigured = $enabled;
    }

    private static function configureFromFederationSetting(): void
    {
        if (self::$federationTestingConfigured !== null) {
            return;
        }

        $enabled = false;
        try {
            if (function_exists('catalog_config') && function_exists('catalog_db') && function_exists('catalog_one')) {
                $db = catalog_db(catalog_config());
                $row = catalog_one(
                    $db,
                    'SELECT setting_value FROM ue_federation_settings WHERE setting_name=? LIMIT 1',
                    ['allow_self_signed_federation_certificates']
                );
                $enabled = (string)($row['setting_value'] ?? '0') === '1';
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB federation TLS] Could not read test TLS setting: ' . $error->getMessage());
        }

        self::configureFederationTesting($enabled);
    }

    public static function source(string $baseUrl): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Secure HTTP requests require PHP cURL.');
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

    /** @param list<string> $headers @return array<string,mixed> */
    public static function postJson(string $url, array $headers, string $body, int $maxResponseBytes = 8388608, int $timeout = 60): array
    {
        self::configureFromFederationSetting();
        $source = self::source($url);
        $maxResponseBytes = max(1024, min($maxResponseBytes, 64 * 1024 * 1024));
        $response = '';
        $curl = self::curl($source, $url, max(5, min($timeout, 300)));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maxResponseBytes): int {
                if (strlen($response) + strlen($chunk) > $maxResponseBytes) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        self::finish($curl, 'federation POST', [200, 201, 202], $response);
        return self::decodeJson($response, 'Federation POST');
    }

    /** @param list<string> $headers */
    public static function postBodyToFile(string $url, array $headers, string $body, string $destination, int $maxBytes, int $timeout = 300, ?callable $progress = null): int
    {
        self::configureFromFederationSetting();
        $source = self::source($url);
        $maxBytes = max(1, $maxBytes);
        $out = @fopen($destination, 'xb');
        if ($out === false) {
            throw new RuntimeException('Could not create federation download file.');
        }
        $written = 0;
        $curl = self::curl($source, $url, max(5, min($timeout, 3600)));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($out, &$written, $maxBytes): int {
                $length = strlen($chunk);
                if ($written + $length > $maxBytes || fwrite($out, $chunk) !== $length) {
                    return 0;
                }
                $written += $length;
                return $length;
            },
        ]);
        if ($progress !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt($curl, CURLOPT_XFERINFOFUNCTION, static function ($handle, float $downloadTotal, float $downloadNow) use ($progress): int {
                $progress((int)$downloadNow, (int)$downloadTotal);
                return 0;
            });
        }
        try {
            self::finish($curl, 'federation download', [200]);
            return $written;
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        } finally {
            fclose($out);
        }
    }

    /** @param list<string> $headers @return array<string,mixed> */
    public static function putFileJson(string $url, array $headers, string $sourceFile, int $maxResponseBytes = 1048576, int $timeout = 3600, ?callable $progress = null): array
    {
        self::configureFromFederationSetting();
        $source = self::source($url);
        $size = filesize($sourceFile);
        if ($size === false || $size < 1 || !is_file($sourceFile) || !is_readable($sourceFile) || is_link($sourceFile)) {
            throw new RuntimeException('Federation upload source is unavailable.');
        }
        $in = @fopen($sourceFile, 'rb');
        if ($in === false) {
            throw new RuntimeException('Could not open federation upload source.');
        }
        $response = '';
        $maxResponseBytes = max(1024, min($maxResponseBytes, 16 * 1024 * 1024));
        $curl = self::curl($source, $url, max(5, min($timeout, 7200)));
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $in,
            CURLOPT_INFILESIZE => (int)$size,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maxResponseBytes): int {
                if (strlen($response) + strlen($chunk) > $maxResponseBytes) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($progress !== null) {
            curl_setopt($curl, CURLOPT_NOPROGRESS, false);
            curl_setopt($curl, CURLOPT_XFERINFOFUNCTION, static function ($handle, float $downloadTotal, float $downloadNow, float $uploadTotal, float $uploadNow) use ($progress): int {
                $progress((int)$uploadNow, (int)$uploadTotal);
                return 0;
            });
        }
        try {
            self::finish($curl, 'federation upload', [200, 201, 202], $response);
        } finally {
            fclose($in);
        }
        return self::decodeJson($response, 'Federation upload');
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
            CURLOPT_USERAGENT => 'UnrealDB/1.0 secure-http-client',
            CURLOPT_SSL_VERIFYPEER => !self::$allowUntrustedTls,
            CURLOPT_SSL_VERIFYHOST => self::$allowUntrustedTls ? 0 : 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$source['host'] . ':443:' . $ip],
        ]);
        return $curl;
    }

    private static function finish($curl, string $label, array $allowed = [200], ?string &$response = null): void
    {
        try {
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            if ($ok === false || !in_array($status, $allowed, true)) {
                $detail = self::responseErrorDetail((string)($response ?? ''));
                throw new RuntimeException(
                    ucfirst($label) . ' request failed'
                    . ($status ? ' with HTTP ' . $status : '')
                    . ($detail !== '' ? ': ' . $detail : ($error !== '' ? ': ' . $error : '.'))
                );
            }
        } finally {
            curl_close($curl);
        }
    }

    private static function responseErrorDetail(string $response): string
    {
        $response = trim($response);
        if ($response === '') {
            return '';
        }

        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $message = trim((string)($decoded['error'] ?? $decoded['message'] ?? ''));
                $reference = trim((string)($decoded['reference'] ?? ''));
                if ($reference !== '') {
                    $message .= ($message !== '' ? ' ' : '') . 'Reference: ' . $reference;
                }
                if ($message !== '') {
                    return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 1000, 'UTF-8');
                }
            }
        } catch (Throwable) {
            // Fall through to a bounded plain-text response.
        }

        return mb_substr(preg_replace('/\s+/', ' ', $response) ?? $response, 0, 1000, 'UTF-8');
    }

    /** @return array<string,mixed> */
    private static function decodeJson(string $response, string $label): array
    {
        try {
            $decoded = json_decode($response, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' returned invalid JSON.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' returned a non-object response.');
        }
        return $decoded;
    }

    /** @return list<string> */
    private static function resolveHostIps(string $host): array
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

        $ips = array_values(array_unique(array_filter(
            $ips,
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        )));
        return $ips;
    }

    private static function publicIp(string $host): string
    {
        $ips = self::resolveHostIps($host);
        if (!$ips) {
            throw new RuntimeException('Source hostname could not be resolved by PHP: ' . $host);
        }
        if (!self::$allowPrivateNetwork) {
            foreach ($ips as $ip) {
                if (!self::isPublic($ip)) {
                    throw new RuntimeException('Source hostname resolves to a blocked network address.');
                }
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
