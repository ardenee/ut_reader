<?php
/**
 * Serve country flags as same-origin cached SVG images.
 *
 * The browser never contacts the upstream flag host directly. A valid flag is
 * fetched once from a pinned flag-icons release, cached under catalog/storage,
 * and served locally on subsequent requests. Transient upstream failures return
 * a short-lived same-origin placeholder instead of a broken image response.
 */
declare(strict_types=1);

$code = strtolower(trim((string)($_GET['code'] ?? '')));
if (preg_match('/^[a-z]{2}$/', $code) !== 1) {
    http_response_code(400);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$cacheDir = __DIR__ . '/storage/cache/country-flags';
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $code . '.svg';

function country_flag_send(
    string $svg,
    string $etag,
    string $method,
    string $cacheControl = 'public, max-age=604800, stale-while-revalidate=86400',
    bool $fallback = false
): never {
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: ' . $cacheControl);
    header('ETag: "' . $etag . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    if ($fallback) {
        header('X-UnrealDB-Flag-Fallback: 1');
    }

    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && hash_equals('"' . $etag . '"', $ifNoneMatch)) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: ' . strlen($svg));
    if ($method !== 'HEAD') {
        echo $svg;
    }
    exit;
}

function country_flag_valid_svg(string $svg): bool
{
    $length = strlen($svg);
    return $length > 50
        && $length <= 250000
        && stripos($svg, '<svg') !== false
        && stripos($svg, '<script') === false
        && stripos($svg, 'javascript:') === false;
}

function country_flag_fetch(string $url): ?string
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'UnrealDB country flag cache/1.1',
            CURLOPT_HTTPHEADER => ['Accept: image/svg+xml,image/*;q=0.8'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($status === 200 && is_string($body) && country_flag_valid_svg($body)) {
            return $body;
        }
        return null;
    }

    if ((bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'follow_location' => 1,
                'max_redirects' => 2,
                'header' => "User-Agent: UnrealDB country flag cache/1.1\r\nAccept: image/svg+xml,image/*;q=0.8\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (is_string($body) && country_flag_valid_svg($body)) {
            return $body;
        }
    }

    return null;
}

function country_flag_fallback_svg(string $code): string
{
    $label = strtoupper($code);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 3" role="img" aria-label="'
        . $label
        . '"><rect width="4" height="3" rx=".15" fill="#e5e7eb"/><text x="2" y="1.9" text-anchor="middle" font-family="Arial,sans-serif" font-size="1" fill="#374151">'
        . $label
        . '</text></svg>';
}

if (is_file($cacheFile)) {
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && country_flag_valid_svg($cached)) {
        country_flag_send($cached, hash('sha256', $cached), $method);
    }
    @unlink($cacheFile);
}

$encodedCode = rawurlencode($code);
$upstreams = [
    'https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.5.0/flags/4x3/' . $encodedCode . '.svg',
    'https://unpkg.com/flag-icons@7.5.0/flags/4x3/' . $encodedCode . '.svg',
];

$svg = null;
foreach ($upstreams as $upstream) {
    $candidate = country_flag_fetch($upstream);
    if (is_string($candidate)) {
        $svg = $candidate;
        break;
    }
}

if (!is_string($svg)) {
    // Do not cache this placeholder on disk. The short browser cache allows the
    // next request to retry the real flag after a transient CDN/network failure.
    $fallback = country_flag_fallback_svg($code);
    http_response_code(200);
    country_flag_send(
        $fallback,
        hash('sha256', $fallback),
        $method,
        'public, max-age=300',
        true
    );
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    $temporary = $cacheFile . '.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($temporary, $svg, LOCK_EX) !== false) {
        @rename($temporary, $cacheFile);
    }
    if (is_file($temporary)) {
        @unlink($temporary);
    }
}

country_flag_send($svg, hash('sha256', $svg), $method);
