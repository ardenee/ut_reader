#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for the final retained-archive recovery cases. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Archive\CatalogNativeZipArchiveReader;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogSequentialArchiveReader;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogZipLocalHeaderRecoveryReader;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$results = [];
$record = static function (string $name, bool $ok, string $detail) use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$temp = [];
try {
    $source = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php');
    $consistencySource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogZipMetadataConsistency.php');
    $extractorSource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php');
    $localRecoverySource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogZipLocalHeaderRecoveryReader.php');
    $nativeZipSource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogNativeZipArchiveReader.php');
    $planPos = strpos($source, '$decision = $plan($entry);');
    $zeroSizeSkipPos = strpos(
        $source,
        'if ($declaredSize !== null && (int)$declaredSize === 0 && !$extract)'
    );
    $streamOpenPos = strpos($source, '$input = $archive->currentEntryStream();');
    $record(
        'seven_zip_zero_size_metadata_is_policy_first',
        $planPos !== false
            && $zeroSizeSkipPos !== false
            && $streamOpenPos !== false
            && $planPos < $zeroSizeSkipPos
            && $zeroSizeSkipPos < $streamOpenPos
            && !str_contains($source, 'decodeUnknownSizeEntry('),
        'Zero-size 7-Zip metadata must reach import policy before any stream probe; non-extract records are completed without probing an empty/reference/anti-style payload.'
    );
    $record(
        'stale_zip_metadata_routes_to_sequential_recovery',
        str_contains($source, 'CatalogZipMetadataConsistency')
            && str_contains($source, 'hasTrustedLocalMetadataMismatch($archivePath)'),
        'ZIPs whose final central directory disagrees with trustworthy local metadata must use the verified sequential recovery path.'
    );
    $record(
        'failed_zip_member_uses_exact_local_header_fallback',
        str_contains($extractorSource, '->extractExactMember(')
            && str_contains($localRecoverySource, 'public function extractExactMember(')
            && str_contains($localRecoverySource, 'decoded CRC32 does not match local header'),
        'A failed ZipArchive member read must retry only that exact member from bounded local-header bytes and still require CRC32 verification.'
    );
    $record(
        'sequential_zip_probe_failure_prefers_native_reader',
        str_contains($source, 'Prefer the native')
            && str_contains($source, 'new CatalogNativeZipArchiveReader($this->config))->walk(')
            && str_contains($source, 'isNativeZipMetadataCapabilityFailure'),
        'ZIPs already known to have unreliable libzip streams must use native/local-header decoding before libarchive.'
    );
    $record(
        'zip64_member_fields_are_resolved_natively',
        str_contains($nativeZipSource, 'resolveZip64MemberFields(')
            && str_contains($nativeZipSource, 'ZIP64 extra record is missing')
            && !str_contains($nativeZipSource, 'does not support ZIP64 member fields'),
        'ZIP64 per-member sentinels must resolve from the standard 0x0001 extra field instead of forcing libarchive.'
    );
    $record(
        'missing_eocd_can_use_local_headers_only',
        str_contains($source, 'walkLocalHeadersOnly(')
            && str_contains($localRecoverySource, 'public function walkLocalHeadersOnly(')
            && str_contains($localRecoverySource, 'local-header-only recovery found no recoverable member records'),
        'A ZIP without a usable EOCD may recover only self-verifying local records and must not depend on libarchive decompression.'
    );
    $record(
        'zip_metadata_probe_is_bounded',
        str_contains($consistencySource, 'MAX_SCAN_BYTES = 16777216')
            && str_contains($consistencySource, '(int)$fileSize > self::MAX_SCAN_BYTES'),
        'Metadata-consistency probing must not add an unbounded full-archive scan to ordinary ZIP ingestion.'
    );

    $payload = "\xC1\x83\x2A\x9E" . str_repeat('ThunderStorm-local-header-recovery-', 128);
    $compressed = gzdeflate($payload, 9);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not create raw-DEFLATE ZIP verifier payload.');
    }
    $name = 'CTF-ThunderStorm.unr';
    $crc = (int)sprintf('%u', crc32($payload));
    $local = pack(
        'VvvvvvVVVvv',
        0x04034b50,
        20,
        0,
        8,
        0,
        0,
        $crc,
        strlen($compressed),
        strlen($payload),
        strlen($name),
        0
    ) . $name . $compressed;

    // The verifier only needs a valid local member record because the final
    // acceptance decision is independently guarded by actual output size + CRC.
    $archivePath = tempnam(sys_get_temp_dir(), 'unrealdb-stale-zip-');
    $outputPath = tempnam(sys_get_temp_dir(), 'unrealdb-stale-out-');
    if (!is_string($archivePath) || !is_string($outputPath)) {
        throw new RuntimeException('Could not allocate ZIP recovery verifier files.');
    }
    $temp[] = $archivePath;
    $temp[] = $outputPath;
    file_put_contents($archivePath, $local);
    file_put_contents($outputPath, $payload);

    $reader = new CatalogSequentialArchiveReader(['archive' => ['max_entries' => 100]]);
    $method = new ReflectionMethod(CatalogSequentialArchiveReader::class, 'zipLocalHeaderValidatesOutput');
    $accepted = (bool)$method->invoke($reader, $archivePath, $name, $outputPath, strlen($payload));
    $record(
        'zip_local_header_can_validate_stale_central_output',
        $accepted,
        'A matching local ZIP header must be allowed to validate actual decoded size + CRC when the final central directory is stale.'
    );

    $localOnlyRecovered = false;
    $localOnly = new CatalogZipLocalHeaderRecoveryReader(['archive' => ['max_entries' => 100]]);
    $localOnly->walkLocalHeadersOnly(
        $archivePath,
        'local-only.zip',
        strlen($payload) * 2,
        static fn(array $entry): array => [
            'extract' => (string)($entry['path'] ?? '') === $name,
            'max_bytes' => strlen($payload) * 2,
            'state' => null,
        ],
        static function (array $entry, ?string $temporary) use (&$localOnlyRecovered, $payload, $name): void {
            if ((string)($entry['path'] ?? '') !== $name || !is_string($temporary) || !is_file($temporary)) {
                return;
            }
            $localOnlyRecovered = file_get_contents($temporary) === $payload;
        }
    );
    $record(
        'missing_eocd_runtime_recovers_crc_verified_local_member',
        $localOnlyRecovered,
        'A ZIP containing only a valid local file record must be recoverable without an EOCD when its decoded size and CRC32 verify.'
    );

    $zip64Payload = 'UNREALDB_ZIP64_MEMBER';
    $zip64Name = 'Maps/ZIP64-Test.unr';
    $zip64Crc = (int)sprintf('%u', crc32($zip64Payload));
    $zip64Local = pack(
        'VvvvvvVVVvv',
        0x04034b50,
        45,
        0,
        0,
        0,
        0,
        $zip64Crc,
        strlen($zip64Payload),
        strlen($zip64Payload),
        strlen($zip64Name),
        0
    ) . $zip64Name . $zip64Payload;
    $zip64Extra = pack(
        'vvVVVVVV',
        0x0001,
        24,
        strlen($zip64Payload),
        0,
        strlen($zip64Payload),
        0,
        0,
        0
    );
    $zip64Central = pack(
        'VvvvvvvVVVvvvvvVV',
        0x02014b50,
        45,
        45,
        0,
        0,
        0,
        0,
        $zip64Crc,
        0xffffffff,
        0xffffffff,
        strlen($zip64Name),
        strlen($zip64Extra),
        0,
        0,
        0,
        0,
        0xffffffff
    ) . $zip64Name . $zip64Extra;
    $zip64Eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        1,
        1,
        strlen($zip64Central),
        strlen($zip64Local),
        0
    );
    $zip64Path = tempnam(sys_get_temp_dir(), 'unrealdb-zip64-member-');
    if (!is_string($zip64Path)) {
        throw new RuntimeException('Could not allocate ZIP64 member verifier file.');
    }
    $temp[] = $zip64Path;
    file_put_contents($zip64Path, $zip64Local . $zip64Central . $zip64Eocd);

    $zip64Recovered = false;
    $nativeZip = new CatalogNativeZipArchiveReader(['archive' => ['max_entries' => 100]]);
    $nativeZip->walk(
        $zip64Path,
        'zip64-member.zip',
        strlen($zip64Payload) * 2,
        static fn(array $entry): array => [
            'extract' => (string)($entry['path'] ?? '') === $zip64Name,
            'max_bytes' => strlen($zip64Payload) * 2,
            'state' => null,
        ],
        static function (array $entry, ?string $temporary) use (&$zip64Recovered, $zip64Payload, $zip64Name): void {
            if ((string)($entry['path'] ?? '') !== $zip64Name || !is_string($temporary) || !is_file($temporary)) {
                return;
            }
            $zip64Recovered = file_get_contents($temporary) === $zip64Payload;
        }
    );
    $record(
        'zip64_member_runtime_decodes_standard_extra_field',
        $zip64Recovered,
        'A normal-sized member using ZIP64 size/offset sentinels must resolve the standard 0x0001 extra field and extract exactly.'
    );

    file_put_contents($outputPath, $payload . 'tampered');
    $rejected = !(bool)$method->invoke($reader, $archivePath, $name, $outputPath, strlen($payload) + 8);
    $record(
        'zip_local_header_recovery_rejects_crc_mismatch',
        $rejected,
        'Local-header recovery must still reject output whose CRC32 does not match the local member header.'
    );

    $record(
        'recovery_remains_in_process_php',
        !preg_match(
            '/\b(?:proc_open|shell_exec|popen|passthru|system|exec)\s*\(/',
            $source . "\n" . $consistencySource . "\n" . $extractorSource . "\n" . $localRecoverySource . "\n" . $nativeZipSource
        ),
        'Archive edge recovery must not launch an external archive process.'
    );

    $worker = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
    $record(
        'worker_fingerprint_tracks_recovery_code',
        str_contains($worker, '/Archive/CatalogSequentialArchiveReader.php')
            && str_contains($worker, '/Archive/CatalogZipMetadataConsistency.php')
            && str_contains($worker, '/Archive/CatalogArchiveExtractor.php')
            && str_contains($worker, '/Archive/CatalogZipLocalHeaderRecoveryReader.php')
            && str_contains($worker, '/Archive/CatalogNativeZipArchiveReader.php')
            && str_contains($worker, '/Persistence/PdoJobQueueSupport.php'),
        'Detached workers must reconcile when sequential or ZIP metadata recovery code changes.'
    );
} catch (Throwable $error) {
    $record('verifier_runtime', false, $error->getMessage());
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

echo PHP_EOL . 'Final archive edge recovery: ' . ($failed === 0 ? 'PASS' : 'FAIL')
    . ' (' . number_format(count($results) - $failed) . '/' . number_format(count($results)) . ')' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
