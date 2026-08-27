#!/usr/bin/env php
<?php
/** Read-only contract for UE3 package compression detection/diagnostics. */
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

$reader = $read('parsers/EpicUE3PackageReader.php');
$lzx = $read('lib/LzxDecoder.php');
$inspector = $read('bin/inspect-ue3-compression.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'compressed_package_detected_from_chunk_table',
    str_contains($reader, '$h[\'compressed\']=$cc>0')
        && str_contains($reader, 'if ($cc) {')
        && str_contains($reader, '$this->inflatePackage();'),
    'A package is considered UE3 package-compressed only when its serialized CompressedChunks array is non-empty.'
);

$record(
    'ue3_compression_methods_are_identified',
    str_contains($reader, 'COMPRESS_ZLIB=1')
        && str_contains($reader, 'COMPRESS_LZO=2')
        && str_contains($reader, 'COMPRESS_LZX=4')
        && str_contains($reader, 'COMPRESS_TYPE_MASK=0x0F'),
    'UE3 package compression method flags must identify ZLIB=1, LZO=2 and LZX=4.'
);

$record(
    'lzx_is_natively_decompressed',
    str_contains($reader, "require_once __DIR__ . '/../lib/LzxDecoder.php'")
        && str_contains($reader, 'CatalogLzxDecoder::decompress($src,$expected,17)')
        && str_contains($lzx, 'final class CatalogLzxDecoder')
        && str_contains($lzx, 'private const FRAME_SIZE = 32768')
        && str_contains($lzx, '15 => 30')
        && str_contains($lzx, '17 => 34')
        && str_contains($lzx, '21 => 50'),
    'UE3 COMPRESS_LZX must use the native raw-LZX decoder with Epic-compatible 17-bit window semantics.'
);

$record(
    'chunk_bounds_error_contains_auditable_values',
    str_contains($reader, 'compressed_offset=')
        && str_contains($reader, 'compressed_size=')
        && str_contains($reader, 'compressed_end=')
        && str_contains($reader, 'physical_size=')
        && str_contains($reader, 'uncompressed_offset=')
        && str_contains($reader, 'uncompressed_size=')
        && str_contains($reader, 'compression_flags=')
        && str_contains($reader, 'chunk_count=')
        && str_contains($reader, 'package_version='),
    'A physical-size contradiction must include the exact serialized chunk range and package/compression metadata.'
);

$lzxFixture = hex2bin('003070000100000001000000010000004c5a582d55453300');
$lzxFixtureOutput = '';
$lzxFixtureError = '';
try {
    require_once $root . '/lib/LzxDecoder.php';
    $lzxFixtureOutput = CatalogLzxDecoder::decompress($lzxFixture === false ? '' : $lzxFixture, 7, 17);
} catch (Throwable $error) {
    $lzxFixtureError = $error->getMessage();
}
$record(
    'native_lzx_decoder_decompresses_valid_stream',
    $lzxFixtureOutput === 'LZX-UE3',
    $lzxFixtureError !== '' ? $lzxFixtureError : 'Expected LZX-UE3, got ' . bin2hex($lzxFixtureOutput)
);

$record(
    'worker_fingerprint_tracks_ue3_codecs',
    str_contains($fingerprint, "/parsers/EpicUE3PackageReader.php")
        && str_contains($fingerprint, "/lib/LzoDecoder.php")
        && str_contains($fingerprint, "/lib/LzxDecoder.php"),
    'Detached workers must be invalidated when UE3 package compression code changes.'
);

$record(
    'one_file_inspector_is_read_only',
    str_contains($inspector, "'read_only' => true")
        && str_contains($inspector, '\'compression_method\' => $method')
        && str_contains($inspector, '\'chunk_bounds_ok\' => $boundsOk')
        && str_contains($inspector, '\'chunks\' => $chunkRows')
        && !str_contains($inspector, 'PdoJobQueue')
        && !str_contains($inspector, 'enqueue(')
        && !str_contains($inspector, 'INSERT ')
        && !str_contains($inspector, 'UPDATE '),
    'The diagnostic CLI must inspect one file without queueing/importing/modifying catalog state.'
);

$syntaxFailures = [];
foreach ([
    $root . '/parsers/EpicUE3PackageReader.php',
    $root . '/lib/LzxDecoder.php',
    $root . '/bin/inspect-ue3-compression.php',
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
