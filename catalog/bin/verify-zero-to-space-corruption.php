#!/usr/bin/env php
<?php
/**
 * Regression gate for deterministic legacy Unreal NUL-to-space corruption.
 *
 * The fixture mirrors the observed UE2 packages where every binary NUL byte was
 * replaced with ASCII space, producing bogus version/licensee values such as
 * 8320/8221 while the low bytes still identify UE2 version 128/licensee 29.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/GameProfiles.php';

use UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier;
use UnrealDb\Catalog\Infrastructure\Import\CatalogLegacyPackageCorruptionDetector;

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

$syntaxFailures = [];
foreach ([
    'src/Infrastructure/Import/CatalogLegacyPackageCorruptionDetector.php',
    'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageInspector.php',
    'src/Application/Telemetry/CatalogInvalidUeErrorClassifier.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
    __FILE__,
] as $relative) {
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

$normal = tempnam(sys_get_temp_dir(), 'ue_normal_');
$corrupt = tempnam(sys_get_temp_dir(), 'ue_space_');
if (!is_string($normal) || !is_string($corrupt)) {
    throw new RuntimeException('Could not create temporary package fixtures.');
}

try {
    $normalHeader = "\xC1\x83\x2A\x9E"
        . pack('v2', 128, 29)
        . pack('V7', 1, 125, 64, 18, 5361, 49, 4930)
        . str_repeat("\x41", 64);
    file_put_contents($normal, $normalHeader);

    $corruptHeader = str_replace("\x00", "\x20", $normalHeader);
    file_put_contents($corrupt, $corruptHeader);

    $normalEvidence = CatalogLegacyPackageCorruptionDetector::detectZeroToSpace($normal);
    $record(
        'normal_ue2_control_is_not_flagged',
        $normalEvidence === null,
        'A normal UE2 version 128/licensee 29 header containing real NUL bytes must remain valid.'
    );

    $evidence = CatalogLegacyPackageCorruptionDetector::detectZeroToSpace($corrupt);
    $record(
        'zero_to_space_fixture_is_detected',
        is_array($evidence)
            && ($evidence['package_version'] ?? null) === 8320
            && ($evidence['licensee_version'] ?? null) === 8221
            && ($evidence['candidate_package_version'] ?? null) === 128
            && ($evidence['candidate_licensee_version'] ?? null) === 29
            && ($evidence['candidate_engine_hint'] ?? null) === 'UE2'
            && ($evidence['zero_bytes'] ?? null) === 0
            && ($evidence['space_bytes'] ?? 0) >= 16,
        'The transformed fixture must be identified as the observed 8320/8221 -> 128/29 UE2 corruption pattern.'
    );

    $classified = CatalogInvalidUeErrorClassifier::classify(
        'Unreal package appears to have NUL bytes replaced with spaces throughout the payload.',
        'unreal.zero_to_space_corruption',
        is_array($evidence) ? $evidence : []
    );
    $record(
        'corruption_has_stable_invalid_ue_type',
        $classified['code'] === 'unreal.zero_to_space_corruption'
            && $classified['error_type'] === 'InvalidUnrealPackage.unreal.zero_to_space_corruption'
            && str_contains($classified['reason'], 'package_version=8320')
            && str_contains($classified['reason'], 'candidate_package_version=128')
            && str_contains($classified['reason'], 'zero_bytes=0'),
        'System Errors must retain one stable type and the exact values that prove the source corruption.'
    );
} finally {
    @unlink($normal);
    @unlink($corrupt);
}

$indexer = $read('src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$verified = $read('src/Infrastructure/Import/CatalogVerifiedPackageInspector.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$record(
    'unverified_detects_corruption_before_unsupported_reader',
    ($detectorPos = strpos($indexer, 'CatalogLegacyPackageCorruptionDetector::detectZeroToSpace($path)')) !== false
        && ($unsupportedPos = strpos($indexer, "'unreal.unsupported_reader'")) !== false
        && $detectorPos < $unsupportedPos,
    'Upload Bucket indexing must classify deterministic source corruption before unsupported-reader fallback.'
);

$record(
    'verified_detects_corruption_before_profile_mismatch',
    ($detectorPos = strpos($verified, 'CatalogLegacyPackageCorruptionDetector::detectZeroToSpace($temporaryPath)')) !== false
        && ($profilePos = strpos($verified, 'if ($strictProfile && empty($classification')) !== false
        && $detectorPos < $profilePos,
    'Verified imports must classify source corruption before a game/profile mismatch can hide it.'
);

$record(
    'workers_reload_for_corruption_detector',
    str_contains($fingerprint, '/src/Infrastructure/Import/CatalogLegacyPackageCorruptionDetector.php'),
    'Detached workers must refresh when the detector changes.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
