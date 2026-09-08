#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for the transient/runtime errors seen on 2026-09-08.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$autoload = $read('bootstrap/autoload.php');
$downloadLogs = $read('download-logs.php');
$packageHandler = $read('src/Infrastructure/Jobs/GeneratedPackageJobHandler.php');

$checks = [
    'autoload retries transient Windows sharing violations' =>
        str_contains($autoload, 'for ($attempt = 0; $attempt < 3; $attempt++)')
        && str_contains($autoload, '@include_once $path')
        && str_contains($autoload, 'usleep(25000);')
        && str_contains($autoload, 'require_once $path;'),
    'invalid manual Download Logs IP stays non-fatal' =>
        str_contains($downloadLogs, '$manualIp = trim(')
        && str_contains($downloadLogs, 'if (@inet_pton($manualIp) === false)')
        && str_contains($downloadLogs, "$message = 'Enter a valid IPv4 or IPv6 address.';"),
    'invalid IP from a log row stays non-fatal' =>
        str_contains($downloadLogs, "if ($logIp === '' || @inet_pton($logIp) === false)")
        && str_contains($downloadLogs, "$message = 'The selected log record does not contain a valid IP address.';"),
    'package-only dependency matches do not block generation' =>
        str_contains($packageHandler, "if ($plan['missing'] && !$allowIncomplete)")
        && str_contains($packageHandler, 'genuinely missing. Package-only matches are included and do not block generation.')
        && !str_contains($packageHandler, 'dependencies are missing or only matched at package level'),
];

$failures = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failures[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'bootstrap/autoload.php',
    'download-logs.php',
    'src/Infrastructure/Jobs/GeneratedPackageJobHandler.php',
    __FILE__,
] as $relative) {
    $path = $relative === __FILE__
        ? __FILE__
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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
