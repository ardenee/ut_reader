#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for final archive dead-letter recovery paths. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail) use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
$temp = [];

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is required for this verifier.');
    }

    $payload = "\xC1\x83\x2A\x9E" . str_repeat('ThunderStorm-stale-central-', 256);
    $compressed = gzdeflate($payload, 9);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not build raw-DEFLATE verifier payload.');
    }
    $name = 'CTF-ThunderStorm.unr';
    $localCrc = (int)sprintf('%u', crc32($payload));
    $localCompressed = strlen($compressed);
    $localSize = strlen($payload);

    $local = pack(
        'VvvvvvVVVvv',
        0x04034b50,
        20,
        0,
        8,
        0,
        0,
        $localCrc,
        $localCompressed,
        $localSize,
        strlen($name),
        0
    ) . $name . $compressed;

    // Deliberately stale final central-directory values. The member path/method
    // stay the same, while CRC and sizes disagree with the valid local record.
    $staleCrc = $localCrc ^ 0x00ffffff;
    $staleCompressed = $localCompressed + 37;
    $staleSize = $localSize + 211;
    $central = pack(
        'VvvvvvvVVVvvvvvVV',
        0x02014b50,
        20,
        20,
        0,
        8,
        0,
        0,
        $staleCrc,
        $staleCompressed,
        $staleSize,
        strlen($name),
        0,
        0,
        0,
        0,
        0,
        0
    ) . $name;
    $centralOffset = strlen($local);
    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        1,
        1,
        strlen($central),
        $centralOffset,
        0
    );

    $archivePath = tempnam(sys_get_temp_dir(), 'unrealdb-stale-central-');
    if (!is_string($archivePath)) {
        throw new RuntimeException('Could not allocate stale ZIP verifier source.');
    }
    $temp[] = $archivePath;
    file_put_contents($archivePath, $local . $central . $eocd);

    $reader = new CatalogSequentialArchiveReader(['archive' => ['max_entries' => 100]]);
    $record(
        'stale_zip_selected_for_recovery',
        $reader->shouldUse($archivePath, basename($archivePath) . '.zip'),
        'A ZIP with trustworthy local metadata that disagrees with the final central directory must leave the ordinary ZipArchive path.'
    );

    $captured = '';
    $failedState = null;
    $walk = $reader->walk(
        $archivePath,
        basename($archivePath) . '.zip',
        64 * 1024 * 1024,
        static fn(array $entry): array => [
            'extract' => true,
            'max_bytes' => 64 * 1024 * 1024,
            'state' => ['kind' => 'extract'],
        ],
        static function (array $entry, ?string $temporary, mixed $state) use (&$captured, &$failedState): void {
            if (is_array($state) && ($state['kind'] ?? '') === 'failed') {
                $failedState = $state;
            }
            if ($temporary !== null && is_file($temporary)) {
                $data = file_get_contents($temporary);
                if (is_string($data)) {
                    $captured = $data;
                }
            }
        }
    );

    $record(
        'stale_zip_avoids_libarchive',
        (string)($walk['format'] ?? '') === 'zip-local-header-recovery',
        'Modified-in-place ZIP recovery must use the PHP local-header walker rather than libarchive.'
    );
    $record(
        'stale_zip_local_payload_verified',
        $failedState === null && hash_equals($payload, $captured),
        'Recovered ZIP bytes must match the local-header-bounded DEFLATE payload after exact size/CRC verification.'
    );

    $sequentialSource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php');
    $record(
        'seven_zip_zero_size_not_blindly_probed',
        !str_contains($sequentialSource, 'decodeUnknownSizeEntry(')
            && !str_contains($sequentialSource, '7-Zip unknown-size member'),
        'A zero-size 7-Zip record must be returned to normal retained/partial policy instead of forcing a stream probe that can dead-letter the parent.'
    );

    $recoverySource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogZipLocalHeaderRecoveryReader.php');
    $record(
        'zip_recovery_stays_in_process_php',
        preg_match('/\b(?:proc_open|shell_exec|popen|passthru|system|exec)\s*\(/', $recoverySource) !== 1,
        'Stale ZIP recovery must not launch an external archive application or shell process.'
    );

    $workerSource = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
    $record(
        'worker_fingerprint_tracks_zip_recovery',
        str_contains($workerSource, '/Archive/CatalogZipLocalHeaderRecoveryReader.php'),
        'Detached workers must reconcile when the local-header ZIP recovery reader changes.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, trim($error->getMessage()) !== '' ? $error->getMessage() : get_class($error));
} finally {
    foreach ($temp as $path) {
        @unlink($path);
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

echo PHP_EOL . 'Final archive dead-letter recovery: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
