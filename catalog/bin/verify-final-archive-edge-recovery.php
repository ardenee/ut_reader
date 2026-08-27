#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for the final retained-archive recovery cases. */
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
    $source = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php');
    $consistencySource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogZipMetadataConsistency.php');
    $extractorSource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogArchiveExtractor.php');
    $localRecoverySource = (string)file_get_contents($root . '/src/Infrastructure/Archive/CatalogZipLocalHeaderRecoveryReader.php');
    $unknownMarker = "if (\$format === '7z' && (int)\$entry['size'] < 1)";
    $unknownPos = strpos($source, $unknownMarker);
    $planPos = strpos($source, '$decision = $plan($entry);');
    $record(
        'seven_zip_unknown_size_is_probed_before_policy',
        $unknownPos !== false && $planPos !== false && $unknownPos < $planPos
            && str_contains($source, 'decodeUnknownSizeEntry('),
        '7-Zip members whose libarchive size metadata is zero must be bounded-decoded before import policy rejects them as empty.'
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
            $source . "\n" . $consistencySource . "\n" . $extractorSource . "\n" . $localRecoverySource
        ),
        'Archive edge recovery must not launch an external archive process.'
    );

    $worker = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
    $record(
        'worker_fingerprint_tracks_recovery_code',
        str_contains($worker, '/Archive/CatalogSequentialArchiveReader.php')
            && str_contains($worker, '/Archive/CatalogZipMetadataConsistency.php')
            && str_contains($worker, '/Archive/CatalogArchiveExtractor.php')
            && str_contains($worker, '/Archive/CatalogZipLocalHeaderRecoveryReader.php'),
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
