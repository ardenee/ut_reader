#!/usr/bin/env php
<?php
/** Read-only contract for direct file download actions on file detail pages. */
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

$examine = $read('file-examine-paged-core.php');
$info = $read('file-info.php');
$download = $read('download.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'file_examine_has_download_action',
    str_contains($examine, 'href="download.php?id=')
        && str_contains($examine, '>Download file</a>'),
    'file-examine.php must expose a direct Download file action for the displayed verified file.'
);

$record(
    'file_info_has_download_action',
    str_contains($info, 'href="download.php?id=')
        && str_contains($info, '>Download file</a>'),
    'file-info.php must expose a direct Download file action for the displayed file.'
);

$record(
    'both_pages_reuse_canonical_download_route',
    str_contains($download, 'public_download_send_local')
        && str_contains($download, 'catalog_download_audit_start')
        && str_contains($download, 'base_game_file_is_protected')
        && !str_contains($examine, 'readfile(')
        && !str_contains($info, 'readfile('),
    'Detail pages must link to the existing audited/protected download route instead of serving files themselves.'
);

$syntaxFailures = [];
foreach ([
    $root . '/file-examine-paged-core.php',
    $root . '/file-info.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
