<?php
declare(strict_types=1);
/**
 * Narrow behavioral checks derived from original Epic C++:
 * - Core/Private/Misc/EngineVersion.cpp:20-30, 90-97
 * - CoreUObject/Private/UObject/PackageFileSummary.cpp:33-45, 184-189, 346-351.
 * These do NOT certify the complete UE4.27 package loader.
 */
require_once dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';

$results = [];
$check = static function (string $name, bool $ok, array $detail = []) use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok] + $detail;
};
$readerClass = new ReflectionClass(UnrealPackageReader4::class);
$reader = $readerClass->newInstanceWithoutConstructor();
$readVersion = $readerClass->getMethod('readEngineVersion');
$fixVersion = $readerClass->getMethod('fixCorruptEngineVersion');

$readBinary = static function (int $rawChangelist) use ($reader, $readVersion): array {
    $data = pack('v3V', 4, 26, 0, $rawChangelist) . pack('V', strlen("Release-4.26") + 1) . "Release-4.26\0";
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $data);
    rewind($stream);
    $binary = new UE4BinaryReader($stream, strlen($data));
    try {
        return (array)$readVersion->invoke($reader, $binary);
    } finally {
        fclose($stream);
    }
};
$rawChangelist = 0x80000000 | 12740027;
$decoded = $readBinary($rawChangelist);
$corrected = (array)$fixVersion->invoke($reader, $decoded, 520);
$check('epic_licensee_fix_pre_522', $corrected['changelist'] === 12740027
    && $corrected['serializedChangelist'] === $rawChangelist
    && $corrected['effectiveChangelistRaw'] === 12740027
    && $corrected['isLicenseeVersion'] === false);
$atGate = (array)$fixVersion->invoke($reader, $decoded, 522);
$check('epic_licensee_preserved_at_522', $atGate['effectiveChangelistRaw'] === $rawChangelist
    && $atGate['isLicenseeVersion'] === true
    && $atGate['serializedChangelist'] === $rawChangelist);
$belowThreshold = $readBinary(0x80000000 | 12740026);
$below = (array)$fixVersion->invoke($reader, $belowThreshold, 520);
$check('epic_licensee_preserved_below_changelist_threshold',
    $below['effectiveChangelistRaw'] === (0x80000000 | 12740026)
    && $below['isLicenseeVersion'] === true);
$ordinary = $readBinary(12740027);
$unchanged = (array)$fixVersion->invoke($reader, $ordinary, 520);
$check('epic_nonlicensee_not_rewritten', $unchanged['serializedChangelist'] === 12740027
    && $unchanged['isLicenseeVersion'] === false);

$source = 'H:/Epic Games/UE_4.27/Engine/Content/EngineMaterials/RandomVectors_WithXNeighbor.uasset';
if (!is_file($source)) {
    throw new RuntimeException('Genuine installed UE4.27 sample missing: ' . $source);
}
$original = file_get_contents($source);
if (!is_string($original) || strlen($original) < 500) {
    throw new RuntimeException('Genuine UE4.27 sample unreadable.');
}
$i32 = static fn(string $buf, int $off): int => (int)unpack('V', substr($buf, $off, 4))[1];
if ($i32($original, 4) !== 0xFFFFFFF9 || $i32($original, 12) < 520) {
    throw new RuntimeException('Unexpected source sample version header.');
}
$customCount = $i32($original, 20);
if ($customCount > 500) {
    throw new RuntimeException('Unexpected custom-version table count.');
}
$folderLenOffset = 24 + $customCount * 20 + 4;
$folderLength = $i32($original, $folderLenOffset);
if ($folderLength < 0 || $folderLength > 2048) {
    throw new RuntimeException('Unexpected Epic sample folder FString length.');
}
$flagsOffset = $folderLenOffset + 4 + $folderLength;
$serializedFlags = $i32($original, $flagsOffset);
if (($serializedFlags & 1) !== 0) {
    throw new RuntimeException('Genuine sample already has transient PKG_NewlyCreated flag.');
}
$mutated = substr_replace($original, pack('V', $serializedFlags | 1), $flagsOffset, 4);
$flagFixture = sys_get_temp_dir() . '/ue427-epic-newlycreated-flag.uasset';
file_put_contents($flagFixture, $mutated);
try {
    $loaded = new UnrealPackageReader4($flagFixture);
    $h = $loaded->getHeader();
    $check('epic_clears_transient_package_flag', (int)$h['serializedPackageFlags'] === ($serializedFlags | 1)
        && (int)$h['packageFlags'] === ($serializedFlags & ~1)
        && $loaded->validatePackage() === []);
} finally {
    @unlink($flagFixture);
}

// A pre-v7 fixture generated from a native Epic summary has a deprecated
// texture allocation count at the original zero-field position shifted by
// the serialized deprecated-GUID custom-version entries.
$legacy = 'C:/Temp/ue427-legacy-guid-fixtures/legacy-guid-3.uasset';
if (!is_file($legacy)) {
    throw new RuntimeException('Retained source-shaped UE4.27 -3 legacy fixture missing.');
}
$data = file_get_contents($legacy);
if (!is_string($data) || $i32($data, 4) !== 0xFFFFFFFD) {
    throw new RuntimeException('Unexpected -3 legacy fixture.');
}
$customCount = $i32($data, 20);
$end = 24;
for ($i = 0; $i < $customCount; $i++) {
    $end += 16 + 4;
    $length = $i32($data, $end);
    $signed = $length > 0x7FFFFFFF ? $length - 0x100000000 : $length;
    $end += 4 + ($signed >= 0 ? $signed : -$signed * 2);
}
$textureOffset = 172 + ($end - 24);
if ($i32($data, $textureOffset) !== 0) {
    throw new RuntimeException('Baseline deprecated texture allocation value is not zero.');
}
$textureFixture = sys_get_temp_dir() . '/ue427-epic-deprecated-texture-count.uasset';
file_put_contents($textureFixture, substr_replace($data, pack('V', 1), $textureOffset, 4));
try {
    $mutatedReader = new UnrealPackageReader4($textureFixture);
    $errors = $mutatedReader->validatePackage();
    $check('epic_rejects_nonzero_deprecated_texture_count',
        count(array_filter($errors, static fn(string $err): bool =>
            str_contains($err, 'nonzero removed texture allocations count'))) === 1);
} finally {
    @unlink($textureFixture);
}
$all = !in_array(false, array_column($results, 'ok'), true);
echo json_encode(['ok' => $all, 'checks' => $results],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($all ? 0 : 1);
