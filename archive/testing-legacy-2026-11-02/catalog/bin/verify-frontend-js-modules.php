#!/usr/bin/env php
<?php
/**
 * Source contract for incremental browser-JavaScript modularisation.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$core = $read('assets/js/core/http.js');
$gameManager = $read('assets/game-manager-missing-counts.js');
$transform = $read('src/Presentation/Http/CatalogPageResponseTransform.php');

$record(
    'shared_http_module_is_small_and_reusable',
    str_contains($core, 'global.UnrealDbHttp')
        && str_contains($core, 'getJson: getJson')
        && str_contains($core, 'postJson: postJson')
        && str_contains($core, 'requestReference: requestReference')
        && str_contains($core, 'credentials')
        && strlen($core) < 10000,
    'The shared browser transport should solve JSON/error/request-ID handling without becoming another frontend framework.'
);
$record(
    'game_manager_uses_shared_transport',
    str_contains($gameManager, 'window.UnrealDbHttp')
        && str_contains($gameManager, "http.getJson('api/v1/game-missing-counts.php')")
        && !str_contains($gameManager, "fetch('api/v1/game-missing-counts.php'"),
    'A real asynchronous feature must consume the shared transport rather than leaving the module unused.'
);
$record(
    'response_transform_loads_dependency_first',
    str_contains($transform, "'assets/js/core/http.js'")
        && str_contains($transform, 'game-manager-missing-counts.js')
        && strpos($transform, "'assets/js/core/http.js'") < strpos($transform, 'game-manager-missing-counts.js'),
    'The shared dependency must be injected before the page enhancement that consumes it.'
);
$record(
    'background_jobs_state_machine_not_rewritten_for_cosmetic_modularity',
    is_file($root . '/assets/background-jobs-stable.js'),
    'P5 is incremental: do not destabilise the live queue controller merely to reorganise files.'
);

$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach (['src/Presentation/Http/CatalogPageResponseTransform.php', 'bin/verify-frontend-js-modules.php'] as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
