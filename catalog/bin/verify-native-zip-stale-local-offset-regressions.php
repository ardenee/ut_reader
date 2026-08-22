#!/usr/bin/env php
<?php
/** Read-only/no-database regression verifier for stale ZIP local-header offsets. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogNativeZipArchiveReader;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$temp = null;
try {
    // Reuse the known-good one-member method-6 fixture from the main codec verifier.
    $legacyFixture = base64_decode(
        'UEsDBBQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAdGVzdC50eHQD9fX19QP19fX1gwD/C1BLAQIUABQAAAAGAAAAAAAJUfgZDgAAAAUAAAAIAAAAAAAAAAAAAAAAAAAAAAB0ZXN0LnR4dFBLBQYAAAAAAQABADYAAAA0AAAAAAA=',
        true
    );
    if (!is_string($legacyFixture)) {
        throw new RuntimeException('Could not decode method-6 regression fixture.');
    }
    $legacyCentralAt = strpos($legacyFixture, "PK\x01\x02");
    $legacyEocdAt = strpos($legacyFixture, "PK\x05\x06");
    if (!is_int($legacyCentralAt) || !is_int($legacyEocdAt) || $legacyCentralAt < 1 || $legacyEocdAt <= $legacyCentralAt) {
        throw new RuntimeException('Method-6 regression fixture structure is invalid.');
    }
    $legacyLocal = substr($legacyFixture, 0, $legacyCentralAt);
    $legacyCentral = substr($legacyFixture, $legacyCentralAt, $legacyEocdAt - $legacyCentralAt);

    $normalName = 'normal.txt';
    $normalPayload = 'NORMAL-DATA';
    $normalCompressed = gzdeflate($normalPayload, 9);
    if (!is_string($normalCompressed)) {
        throw new RuntimeException('Could not create DEFLATE regression payload.');
    }
    $crcBytes = hash('crc32b', $normalPayload, true);
    $crcParts = unpack('Ncrc', $crcBytes);
    $normalCrc = (int)($crcParts['crc'] ?? 0);

    $normalLocal = pack(
        'VvvvvvVVVvv',
        0x04034b50,
        20,
        0,
        8,
        0,
        0,
        $normalCrc,
        strlen($normalCompressed),
        strlen($normalPayload),
        strlen($normalName),
        0
    ) . $normalName . $normalCompressed;

    $legacyLocalOffset = strlen($normalLocal);
    // The legacy fixture has one central record; rewrite its local-header offset
    // so it remains valid after the normal member is prepended.
    $legacyCentral = substr_replace($legacyCentral, pack('V', $legacyLocalOffset), 42, 4);

    // Deliberately stale local-header offset: byte 7 is inside the first local
    // header and therefore cannot contain PK\x03\x04. This reproduces the class
    // of offsets seen in captainkirk.zip/MH-boxes-remade+.zip while leaving the
    // central directory otherwise authoritative and complete.
    $staleNormalOffset = 7;
    $normalCentral = pack(
        'VvvvvvvVVVvvvvvVV',
        0x02014b50,
        20,
        20,
        0,
        8,
        0,
        0,
        $normalCrc,
        strlen($normalCompressed),
        strlen($normalPayload),
        strlen($normalName),
        0,
        0,
        0,
        0,
        0,
        $staleNormalOffset
    ) . $normalName;

    $centralOffset = strlen($normalLocal) + strlen($legacyLocal);
    $central = $normalCentral . $legacyCentral;
    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        2,
        2,
        strlen($central),
        $centralOffset,
        0
    );
    $archive = $normalLocal . $legacyLocal . $central . $eocd;

    $temp = tempnam(sys_get_temp_dir(), 'unrealdb-stale-local-offset-');
    if (!is_string($temp) || file_put_contents($temp, $archive) !== strlen($archive)) {
        throw new RuntimeException('Could not write stale-local-offset ZIP fixture.');
    }

    $reader = new CatalogNativeZipArchiveReader(['archive' => ['max_entries' => 100]]);
    $record(
        'legacy_detection_ignores_unrelated_stale_local_offset',
        $reader->hasLegacyCompression($temp),
        'Central-directory method detection must not resolve every local header eagerly.'
    );

    $decoded = [];
    $walk = $reader->walk(
        $temp,
        'stale-local-offset.zip',
        1048576,
        static fn(array $entry): array => [
            'extract' => true,
            'max_bytes' => 1048576,
            'state' => null,
        ],
        static function (array $entry, ?string $temporary) use (&$decoded): void {
            if ($temporary === null || !is_file($temporary)) {
                throw new RuntimeException('Regression verifier did not receive an extracted member.');
            }
            $data = file_get_contents($temporary);
            if (!is_string($data)) {
                throw new RuntimeException('Regression verifier could not read an extracted member.');
            }
            $decoded[(string)$entry['path']] = $data;
        }
    );

    $record(
        'ordinary_deflate_member_recovers_stale_local_offset',
        ($decoded['normal.txt'] ?? null) === $normalPayload,
        'An ordinary method-8 member must fall back to bounded native local-header recovery when ext-zip cannot use its stale offset.'
    );
    $record(
        'legacy_member_still_decodes_after_recovery',
        ($decoded['test.txt'] ?? null) === 'AAAAA',
        'Recovering an earlier ordinary member must not disturb later method-6 decoding.'
    );
    $record(
        'stale_offset_fixture_uses_native_backend',
        (string)($walk['format'] ?? '') === 'zip-native-php',
        'The mixed legacy archive must remain on the native PHP compatibility path.'
    );

    $source = (string)@file_get_contents($root . '/src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php');
    $record(
        'local_header_recovery_is_bounded',
        str_contains($source, 'LOCAL_HEADER_RECOVERY_FORWARD_BYTES = 4194304')
            && str_contains($source, 'LOCAL_HEADER_RECOVERY_BACKTRACK_BYTES = 65536'),
        'Stale local-header recovery must remain bounded rather than scanning an entire archive blindly.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, $error->getMessage());
} finally {
    if (is_string($temp) && is_file($temp)) {
        @unlink($temp);
    }
}

$failed = 0;
foreach ($results as $result) {
    $status = $result['ok'] ? 'PASS' : 'FAIL';
    echo '[' . $status . '] ' . $result['name'];
    if ($result['detail'] !== '') {
        echo ' — ' . $result['detail'];
    }
    echo PHP_EOL;
    if (!$result['ok']) {
        $failed++;
    }
}

echo PHP_EOL . 'Native ZIP stale local offsets: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
