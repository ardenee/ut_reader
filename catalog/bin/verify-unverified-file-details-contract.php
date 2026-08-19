#!/usr/bin/env php
<?php
/** Read-only source contract for Unverified File Details package identity presentation. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$pagePath = $root . '/unverified-file-details.php';
$queryPath = $root . '/src/Infrastructure/Unverified/PdoUnverifiedFileDetailsQuery.php';
$page = (string)@file_get_contents($pagePath);
$query = (string)@file_get_contents($queryPath);
$checks = [];
$failures = [];

$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'details_query_exposes_stored_package_fields',
    str_contains($query, 'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1'),
    'The details model must expose the persisted is_compressed, compression_flags and package_guid fields without a second schema-specific query.'
);

$record(
    'zero_guid_is_labeled_without_rewriting_source_value',
    str_contains($page, 'function ufd_is_zero_guid')
        && str_contains($page, "str_repeat('0', 32)")
        && str_contains($page, 'Zero GUID · source value')
        && str_contains($page, "catalog_h($guid)"),
    'An all-zero 128-bit GUID must remain visible exactly as stored while being identified as a deliberate zero source value.'
);

$record(
    'compression_status_and_flags_are_visible',
    str_contains($page, 'function ufd_compression_label')
        && str_contains($page, 'function ufd_compression_flags')
        && str_contains($page, "'Compression' => $compressionLabel")
        && str_contains($page, '$compressionFlags'),
    'Unverified package details must expose persisted compression state and raw flags.'
);

$record(
    'ue3_compression_algorithm_is_decoded',
    str_contains($page, "1 => 'ZLIB compressed'")
        && str_contains($page, "2 => 'LZO compressed'")
        && str_contains($page, '$flags & 0x0F'),
    'UE3 compression type bits must distinguish the ZLIB and LZO algorithms used by the Epic UE3 reader.'
);

$record(
    'compression_flags_are_hexadecimal',
    str_contains($page, "sprintf('0x%08X'")
        && str_contains($page, "compression_flags"),
    'Raw compression flags should be displayed in the same hexadecimal form used by UE tooling.'
);

$syntaxFailures = [];
foreach ([$pagePath, $queryPath] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
