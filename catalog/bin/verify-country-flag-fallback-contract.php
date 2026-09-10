#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for same-origin country flag delivery.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$endpointPath = $root . DIRECTORY_SEPARATOR . 'country-flag.php';
$endpoint = @file_get_contents($endpointPath);
$endpoint = is_string($endpoint) ? $endpoint : '';

$checks = [
    'country flag endpoint exists' => $endpoint !== '',
    'valid flags remain cached locally' =>
        str_contains($endpoint, "storage/cache/country-flags")
        && str_contains($endpoint, "@file_put_contents($temporary, $svg, LOCK_EX)"),
    'flag delivery has independent upstream fallback' =>
        str_contains($endpoint, 'cdn.jsdelivr.net/gh/lipis/flag-icons@7.5.0')
        && str_contains($endpoint, 'unpkg.com/flag-icons@7.5.0'),
    'transient upstream failure returns a valid placeholder instead of 404' =>
        str_contains($endpoint, 'function country_flag_fallback_svg')
        && str_contains($endpoint, 'http_response_code(200);')
        && str_contains($endpoint, "'public, max-age=300'")
        && str_contains($endpoint, 'X-UnrealDB-Flag-Fallback: 1')
        && !str_contains($endpoint, "http_response_code(404);\n    exit;"),
    'fallback is not persisted in the flag cache' =>
        ($fallbackPos = strpos($endpoint, '$fallback = country_flag_fallback_svg($code);')) !== false
        && ($cacheWritePos = strpos($endpoint, '@file_put_contents($temporary, $svg, LOCK_EX)')) !== false
        && $fallbackPos < $cacheWritePos
        && str_contains(substr($endpoint, $fallbackPos, $cacheWritePos - $fallbackPos), 'country_flag_send('),
    'request method and country-code validation remain enforced' =>
        str_contains($endpoint, "preg_match('/^[a-z]{2}$/', $code)")
        && str_contains($endpoint, "in_array($method, ['GET', 'HEAD'], true)"),
];

$failures = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failures[] = $label;
    }
}

$syntaxFailures = [];
foreach ([$endpointPath, __FILE__] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
if ($syntaxFailures !== []) {
    $failures[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

$result = [
    'ok' => $failures === [],
    'checks' => count($checks) + 1,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
