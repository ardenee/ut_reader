#!/usr/bin/env php
<?php
/** Read-only verifier for deterministic PHP archive-decoder capability handling. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$sequentialPath = $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php';
$rarPath = $root . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php';
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php';
$workerPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';

$sequential = (string)@file_get_contents($sequentialPath);
$rar = (string)@file_get_contents($rarPath);
$handler = (string)@file_get_contents($handlerPath);
$worker = (string)@file_get_contents($workerPath);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$syntaxFailures = [];
foreach ([$sequentialPath, $rarPath, $handlerPath, $workerPath] as $path) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . implode(' ', $output);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$record(
    'skipped_zip_members_are_not_decoded',
    str_contains($sequential, "if (\$format === 'zip' && !\$extract)")
        && str_contains($sequential, '$complete($entry, null, $state);')
        && str_contains($sequential, 'ZIP entries are independently compressed'),
    'Unsupported/nested/reused ZIP members must advance without opening their payload stream.'
);

$record(
    'unsupported_zip_compression_is_terminal_capability',
    str_contains($handler, "str_contains(\$message, 'unsupported zip compression method')")
        && str_contains($handler, 'isTerminalArchiveCapabilityFailure'),
    'Historic ZIP methods unsupported by both installed PHP decoders must become retained partial results instead of retry loops.'
);

$record(
    'failed_native_rar_extraction_is_terminal_capability',
    str_contains($handler, "str_contains(\$message, 'rarentry::extract() returned failure')")
        && str_contains($handler, "str_contains(\$message, 'rarentry::extract() also failed')")
        && str_contains($rar, 'RarEntry::extract() returned failure'),
    'If ext-rar streaming and native RarEntry::extract() both fail deterministically, do not repeat identical retries.'
);

$record(
    'terminal_decoder_failure_retains_source',
    str_contains($handler, 'installed PHP archive decoder cannot decode')
        && str_contains($handler, "'status' => 'partial'")
        && str_contains($handler, "'source_retained' => true"),
    'Decoder capability failures must finish as partial with their immutable source archive retained.'
);

$record(
    'archive_handling_remains_php_extension_only',
    !str_contains($sequential, 'proc_open(')
        && !str_contains($rar, 'proc_open(')
        && !str_contains($sequential, 'shell_exec(')
        && !str_contains($rar, 'shell_exec(')
        && !str_contains($sequential, '7z.exe')
        && !str_contains($rar, '7z.exe')
        && str_contains($rar, '\\RarArchive::open($archivePath)')
        && str_contains($sequential, 'new \\libarchive\\Archive($archivePath)'),
    'Archive decoding must stay entirely inside PHP extensions; no archive executable or shell fallback is allowed.'
);

$record(
    'worker_fingerprint_tracks_changed_decoders',
    str_contains($worker, '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php')
        && str_contains($worker, '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php')
        && str_contains($worker, '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php'),
    'These decoder/handler changes must invalidate old detached workers.'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
