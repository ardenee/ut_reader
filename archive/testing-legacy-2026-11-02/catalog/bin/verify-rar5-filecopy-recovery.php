#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for RAR5 FILECOPY recovery. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogRar5FileCopyMap;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail) use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
$vint = static function (int $value): string {
    $out = '';
    do {
        $byte = $value & 0x7f;
        $value >>= 7;
        if ($value > 0) {
            $byte |= 0x80;
        }
        $out .= chr($byte);
    } while ($value > 0);
    return $out;
};
$block = static function (string $headerData) use ($vint): string {
    $size = $vint(strlen($headerData));
    $crcHex = hash('crc32b', $size . $headerData);
    return pack('V', (int)hexdec($crcHex)) . $size . $headerData;
};

$temp = '';
try {
    $source = 'DM-48SC-Reverie-V3/DreamTearDown.umx';
    $logical = 'DOM-48SC-Reverie-V3/DreamTearDown.umx';

    $redirData = $vint(0x05)
        . $vint(0x05)
        . $vint(0)
        . $vint(strlen($source))
        . $source;
    $extra = $vint(strlen($redirData)) . $redirData;
    $fileData = $vint(2)
        . $vint(0x0001)
        . $vint(strlen($extra))
        . $vint(0)
        . $vint(944205)
        . $vint(0)
        . $vint(0)
        . $vint(0)
        . $vint(strlen($logical))
        . $logical
        . $extra;
    $endData = $vint(5) . $vint(0) . $vint(0);
    $fixture = "Rar!\x1A\x07\x01\x00" . $block($fileData) . $block($endData);

    $temp = tempnam(sys_get_temp_dir(), 'unrealdb-rar5-filecopy-');
    if (!is_string($temp)) {
        throw new RuntimeException('Could not allocate RAR5 verifier fixture.');
    }
    if (file_put_contents($temp, $fixture) !== strlen($fixture)) {
        throw new RuntimeException('Could not write RAR5 verifier fixture.');
    }

    $targets = (new CatalogRar5FileCopyMap())->targets($temp);
    $record(
        'rar5_filecopy_exact_target_is_parsed',
        ($targets[$logical] ?? null) === $source,
        'RAR5 FILECOPY must resolve the logical member to the exact source path stored in its redirection record.'
    );

    $tampered = $fixture;
    $tampered[12] = chr(ord($tampered[12]) ^ 0x01);
    file_put_contents($temp, $tampered);
    $record(
        'rar5_filecopy_rejects_bad_header_crc',
        (new CatalogRar5FileCopyMap())->targets($temp) === [],
        'RAR5 redirection metadata is only trusted when its block header CRC32 validates.'
    );

    $readerSource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php');
    $record(
        'rar_reader_uses_recorded_filecopy_target',
        str_contains($readerSource, 'CatalogRar5FileCopyMap')
            && str_contains($readerSource, 'resolveFileCopySource(')
            && str_contains($readerSource, 'extractFileCopySourceToTemporary('),
        'PECL RAR recovery must use the recorded FILECOPY source instead of guessing by size or filename similarity.'
    );
    $record(
        'rar_filecopy_recovery_has_no_external_processes',
        preg_match('/\b(?:proc_open|shell_exec|popen|passthru|system|exec)\s*\(/', $readerSource) !== 1,
        'RAR5 FILECOPY recovery must remain entirely in-process PHP.'
    );

    $worker = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
    $record(
        'worker_fingerprint_tracks_rar5_filecopy_parser',
        str_contains($worker, '/Archive/CatalogRar5FileCopyMap.php'),
        'Detached workers must reconcile when RAR5 FILECOPY parsing changes.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, $error->getMessage());
} finally {
    if (is_string($temp) && $temp !== '' && is_file($temp)) {
        @unlink($temp);
    }
}

$failed = 0;
foreach ($results as $result) {
    echo '[' . ($result['ok'] ? 'PASS' : 'FAIL') . '] ' . $result['name']
        . ' — ' . $result['detail'] . PHP_EOL;
    if (!$result['ok']) {
        $failed++;
    }
}

echo PHP_EOL . 'RAR5 FILECOPY recovery: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
