#!/usr/bin/env php
<?php
/** Read-only contract for readable game file tables across engine generations. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$pagePath = $root . DIRECTORY_SEPARATOR . 'game-files.php';
$page = @file_get_contents($pagePath);
$page = is_string($page) ? $page : '';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'ue4_ue5_hide_internal_compression',
    str_contains($page, '$showCompression = $engineMajor === 3;')
        && str_contains($page, 'Keep it only for UE3'),
    'Internal-compression filtering/column must be shown for UE3 only, not UE4/UE5.'
);

$record(
    'package_and_file_columns_get_width_priority',
    str_contains($page, '.game-files-package,')
        && str_contains($page, '#game-files-table .game-files-file {')
        && str_contains($page, 'width: 50%;')
        && str_contains($page, 'overflow-wrap: anywhere;')
        && str_contains($page, 'word-break: break-word;')
        && str_contains($page, 'class="game-files-file"'),
    'Package and File must share the remaining table width and wrap long Unreal paths instead of being squeezed by metadata columns.'
);

$record(
    'metadata_columns_are_content_sized',
    str_contains($page, 'table-layout: auto !important;')
        && str_contains($page, '#game-files-table .identity-cell,')
        && str_contains($page, '#game-files-table .game-files-actions {')
        && str_contains($page, 'width: 1%;')
        && str_contains($page, '.identity-cell { white-space: nowrap;'),
    'Identity, Version, Size, Compression, Dependencies and Actions must stay only as wide as their contents need so Package/File receive the available space.'
);

$pipes = [];
$process = @proc_open([PHP_BINARY, '-l', $pagePath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$syntaxOk = false;
$syntaxDetail = '';
if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $syntaxOk = $exit === 0;
    $syntaxDetail = trim((string)$stderr . ' ' . (string)$stdout);
} else {
    $syntaxDetail = 'Could not run php -l.';
}
$record('php_syntax', $syntaxOk, $syntaxDetail);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
