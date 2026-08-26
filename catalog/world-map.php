<?php
/**
 * Serve the world map used by Download Logs as a same-origin cached SVG.
 *
 * Source: Menelabs VectorAtlas, generated from Natural Earth data.
 * VectorAtlas is CC BY 4.0; Download Logs displays the required attribution.
 * The upstream revision is pinned so the browser-visible map cannot change
 * unexpectedly between requests.
 */
declare(strict_types=1);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$cacheDir = __DIR__ . '/storage/cache/world-map';
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'world.svg';

function world_map_valid_svg(string $svg): bool
{
    $length = strlen($svg);
    return $length > 10000
        && $length <= 5000000
        && stripos($svg, '<svg') !== false
        && stripos($svg, '<path') !== false
        && stripos($svg, 'id="ie"') !== false
        && stripos($svg, 'id="us"') !== false
        && stripos($svg, '<script') === false
        && stripos($svg, 'javascript:') === false
        && stripos($svg, '<foreignObject') === false;
}

function world_map_send(string $svg, string $method): never
{
    $etag = hash('sha256', $svg);
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
    header('ETag: "' . $etag . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; sandbox");

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

function world_map_fetch(string $url): ?string
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'UnrealDB world map cache/1.0',
                CURLOPT_HTTPHEADER => ['Accept: image/svg+xml,image/*;q=0.8'],
            ]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status === 200 && is_string($body) && world_map_valid_svg($body)) {
                return $body;
            }
        }
    }

    if ((bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'follow_location' => 1,
                'max_redirects' => 2,
                'header' => "User-Agent: UnrealDB world map cache/1.0\r\nAccept: image/svg+xml,image/*;q=0.8\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (is_string($body) && world_map_valid_svg($body)) {
            return $body;
        }
    }

    return null;
}

if (is_file($cacheFile)) {
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && world_map_valid_svg($cached)) {
        world_map_send($cached, $method);
    }
    @unlink($cacheFile);
}

$revision = '98bc8b95ee210012c32b02805d21a8de77a04507';
$upstream = 'https://cdn.jsdelivr.net/gh/melenaos/Menelabs.VectorAtlas@'
    . $revision
    . '/dist/world.svg';
$svg = world_map_fetch($upstream);
if (!is_string($svg)) {
    header('Cache-Control: no-store');
    http_response_code(503);
    exit;
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

world_map_send($svg, $method);
