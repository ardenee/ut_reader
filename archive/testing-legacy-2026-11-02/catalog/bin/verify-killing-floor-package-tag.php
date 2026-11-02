#!/usr/bin/env php
<?php
/**
 * Regression gate for the Killing Floor legacy Unreal package tag.
 *
 * Killing Floor uses 0x9E2A83C2 (bytes C2 83 2A 9E) instead of the standard
 * 0x9E2A83C1 package tag while retaining the UE2 package-summary layout.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogRedirectArchive.php';
require_once $root . '/lib/GameProfiles.php';
require_once $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$phpFiles = [
    'src/Domain/Package/CatalogUnrealPackageTag.php',
    'lib/GameProfiles.php',
    'lib/CatalogRedirectArchive.php',
    'src/Infrastructure/Readers/CatalogLegacyPackageReader.php',
    'src/Infrastructure/Import/CatalogFailedUploadRetention.php',
    'src/Infrastructure/Import/CatalogLegacyPackageCorruptionDetector.php',
    'src/Infrastructure/Jobs/CatalogArchiveMemberContentClassifier.php',
    'src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php',
    'src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php',
    'src/Application/Catalog/CatalogPackageHeaderInspector.php',
    'src/Infrastructure/Maintenance/CatalogZeroGuidRepairService.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    __FILE__,
];
$syntaxFailures = [];
foreach ($phpFiles as $relative) {
    $path = $relative === __FILE__
        ? __FILE__
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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

$record(
    'tag_policy_accepts_standard_and_killing_floor_only',
    CatalogUnrealPackageTag::isSupportedLittleEndianValue(0x9E2A83C1)
        && CatalogUnrealPackageTag::isSupportedLittleEndianValue(0x9E2A83C2)
        && !CatalogUnrealPackageTag::isSupportedLittleEndianValue(0x9E2A83C3)
        && CatalogUnrealPackageTag::variant(0x9E2A83C2) === 'killing_floor',
    'The special tag must be explicit rather than weakening package-magic validation generally.'
);

$fixturePath = tempnam(sys_get_temp_dir(), 'kf_pkg_');
$uz2Path = tempnam(sys_get_temp_dir(), 'kf_uz2_');
if (!is_string($fixturePath) || !is_string($uz2Path)) {
    throw new RuntimeException('Could not create temporary Killing Floor fixtures.');
}
$uz2NamedPath = $uz2Path . '.uz2';
@unlink($uz2NamedPath);

try {
    // Valid empty UE2 package summary: tag, v128/licensee29, flags, empty
    // tables at EOF, GUID, and zero generations.
    $package = pack('Vvv', 0x9E2A83C2, 128, 29)
        . pack('V', 1)
        . pack('V6', 0, 56, 0, 56, 0, 56)
        . pack('V4', 0x11223344, 0x55667788, 0x99AABBCC, 0xDDEEFF00)
        . pack('V', 0);
    file_put_contents($fixturePath, $package);

    $summary = gp_read_legacy_summary($fixturePath);
    $record(
        'header_routing_recognizes_killing_floor_as_ue2',
        !empty($summary['ok'])
            && ($summary['version'] ?? null) === 128
            && ($summary['licensee'] ?? null) === 29
            && ($summary['engine_hint'] ?? null) === 'UE2'
            && ($summary['package_tag_variant'] ?? null) === 'killing_floor',
        'C2832A9E must route from serialized version/licensee data exactly like the UE2 layout it contains.'
    );

    $reader = new CatalogUE2PackageReader($fixturePath);
    $record(
        'legacy_reader_accepts_killing_floor_tag',
        $reader->validatePackage() === []
            && ($reader->getHeader()['version'] ?? null) === 128
            && ($reader->getHeader()['licensee'] ?? null) === 29
            && ($reader->getHeader()['tag'] ?? null) === 0x9E2A83C2,
        'The production UE2 reader must accept the special tag without rewriting source bytes.'
    );

    $compressed = gzcompress($package, 6);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not create UZ2 fixture payload.');
    }
    file_put_contents($uz2NamedPath, pack('V2', strlen($compressed), strlen($package)) . $compressed);

    $decoded = catalog_redirect_archive_decompress_to_temp($uz2NamedPath, 'KillingFloorFixture.u.uz2');
    $decodedPath = (string)($decoded['path'] ?? '');
    $decodedHead = $decodedPath !== '' && is_file($decodedPath)
        ? (string)file_get_contents($decodedPath, false, null, 0, 8)
        : '';
    $record(
        'uz2_decoder_accepts_killing_floor_package_tag',
        substr($decodedHead, 0, 4) === "\xC2\x83\x2A\x9E"
            && (int)($decoded['chunks'] ?? 0) === 1,
        'A valid Epic UZ2 record stream containing a Killing Floor package must pass package-tag validation.'
    );
    if ($decodedPath !== '') {
        @unlink($decodedPath);
    }
} finally {
    @unlink($fixturePath);
    @unlink($uz2Path);
    @unlink($uz2NamedPath);
}

$redirectStream = $read('src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php');
$redirectProcessor = $read('src/Infrastructure/Redirect/CatalogRedirectArchiveProcessor.php');
$uploadInspector = $read('assets/upload-file-inspector-worker.js');
$archiveWorker = $read('assets/public-upload-archive-worker.js');
$redirectReader = $read('assets/unreal-redirect-reader.js');
$legacyUz = $read('assets/legacy-uz-decoder.js');
$publicUpload = $read('assets/public-upload.js');
$workerFingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$headerInspector = $read('src/Application/Catalog/CatalogPackageHeaderInspector.php');
$retention = $read('src/Infrastructure/Import/CatalogFailedUploadRetention.php');

$record(
    'redirect_diagnostics_list_killing_floor_tag',
    str_contains($redirectStream, 'C1832A9E|9E2A83C1|C2832A9E')
        && str_contains($redirectProcessor, 'C1832A9E|9E2A83C1|C2832A9E'),
    'Magic-not-found diagnostics must show every package tag actually accepted by the redirect runtime.'
);

$record(
    'browser_package_magic_accepts_killing_floor',
    str_contains($uploadInspector, 'bytes[0] === 0xc2')
        && str_contains($archiveWorker, 'bytes[0] === 0xc2')
        && str_contains($redirectReader, 'bytes[0] === 0xc2')
        && str_contains($legacyUz, 'data[0]===0xc2')
        && str_contains($publicUpload, 'leTag === 0x9e2a83c1 || leTag === 0x9e2a83c2')
        && str_contains($uploadInspector, 'littleEndianTag === 0x9e2a83c1 || littleEndianTag === 0x9e2a83c2')
        && str_contains($archiveWorker, 'littleEndianTag === 0x9e2a83c1 || littleEndianTag === 0x9e2a83c2'),
    'Direct uploads, archive members, UZ/UZ2 readers and GUID extraction must agree on the Killing Floor tag.'
);

$record(
    'other_server_magic_boundaries_use_shared_policy',
    str_contains($headerInspector, 'CatalogUnrealPackageTag::isSupportedLittleEndianValue($tag)')
        && str_contains($retention, 'CatalogUnrealPackageTag::isSupportedLittleEndianValue($tag)'),
    'Header inspection and failed-upload retention must not reintroduce a standard-tag-only gate.'
);

$record(
    'workers_reload_for_package_tag_policy',
    str_contains($workerFingerprint, '/src/Domain/Package/CatalogUnrealPackageTag.php'),
    'Detached workers must refresh when supported package-tag policy changes.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
