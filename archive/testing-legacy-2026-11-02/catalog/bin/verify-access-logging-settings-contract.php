#!/usr/bin/env php
<?php
/** Read-only contract for Access Matrix logging exclusions. */
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

$settings = $read('src/Infrastructure/Telemetry/CatalogAccessLoggingSettings.php');
$recorder = $read('src/Infrastructure/Telemetry/CatalogAccessEventRecorder.php');
$page = $read('logging-settings.php');
$navigation = $read('lib/CatalogNavigation.php');
$accessMatrix = $read('access-matrix.php');

$checks = [
    'settings support global activity logging toggle' =>
        str_contains($settings, "private const ENABLED = 'access_logging_enabled';")
        && str_contains($page, 'name="logging_enabled"'),
    'settings support authenticated administrator exclusion' =>
        str_contains($settings, "private const IGNORE_ADMINS = 'access_logging_ignore_admin_sessions';")
        && str_contains($page, 'name="ignore_admin_sessions"'),
    'settings support exact ignored IP list' =>
        str_contains($settings, "private const IGNORED_IPS = 'access_logging_ignored_ips';")
        && str_contains($page, 'name="ignored_ips"')
        && str_contains($settings, 'inet_pton'),
    'current IP can be added from settings page' =>
        str_contains($page, 'add_current_ip')
        && str_contains($page, 'catalog_public_access_client_ip()'),
    'recorder applies exclusions before insert' =>
        str_contains($recorder, 'CatalogAccessLoggingSettings($this->db)')
        && str_contains($recorder, '->shouldIgnore($ipText, $isAdmin)')
        && strpos($recorder, '->shouldIgnore($ipText, $isAdmin)') < strpos($recorder, 'INSERT INTO ue_access_events'),
    'settings page is in administrator navigation' =>
        str_contains($navigation, "'Logging Settings' => \$root . 'logging-settings.php'"),
    'access matrix links directly to logging settings' =>
        str_contains($accessMatrix, "'Logging Settings' => 'logging-settings.php'"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

$syntaxFailures = [];
foreach ([
    'src/Infrastructure/Telemetry/CatalogAccessLoggingSettings.php',
    'src/Infrastructure/Telemetry/CatalogAccessEventRecorder.php',
    'logging-settings.php',
    'lib/CatalogNavigation.php',
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
    $failed[] = 'php_syntax: ' . implode(' | ', $syntaxFailures);
}

$result = [
    'ok' => $failed === [],
    'checks' => count($checks) + 1,
    'failures' => $failed,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 2);
