<?php
declare(strict_types=1);

/**
 * Source-shaped nonempty deprecated custom-version fixtures for Epic 4.27.2.
 * Native initial 200 bytes come exclusively from FPackageFileSummary's
 * FMemoryWriter. We replace its empty custom-version preamble with source
 * CustomVersion.cpp::FGuidCustomVersion_DEPRECATED wire fields.
 * The tests are only evidence after independent Epic FArchive decoding.
 */
$base = $argv[1] ?? 'C:/Temp/ue427-native-fixtures/native-little.uasset';
$dest = $argv[2] ?? 'C:/Temp/ue427-legacy-guid-fixtures';
$raw = @file_get_contents($base);
if (!is_string($raw) || strlen($raw) !== 4096 ||
    substr($raw, 0, 4) !== pack('V', 0x9E2A83C1) ||
    unpack('V', substr($raw, 4, 4))[1] !== 0xFFFFFFF9 ||
    unpack('V', substr($raw, 20, 4))[1] !== 0 ||
    unpack('V', substr($raw, 24, 4))[1] !== 4096) {
    throw new RuntimeException('Expected unchanged Epic-native -7, 4096-byte summary baseline.');
}
if (!is_dir($dest) && !mkdir($dest, 0775, true)) {
    throw new RuntimeException('Could not create fixture folder.');
}
$guidBytes = static function (array $words): string {
    return implode('', array_map(static fn(int $word): string => pack('V', $word), $words));
};
$str = static function (string $s, bool $wide): string {
    if ($wide) {
        $utf16 = mb_convert_encoding($s . "\0", 'UTF-16LE', 'UTF-8');
        return pack('V', (0x100000000 - intdiv(strlen($utf16), 2)) & 0xFFFFFFFF) . $utf16;
    }
    return pack('V', strlen($s) + 1) . $s . "\0";
};
$templates = [
    '-3' => [
        [[0x01234567, 0x89ABCDEF, 0x10203040, 0x50607080], 42, 'EpicLegacyAlpha', false],
        [[0xFFFFFFFF, 0x8A7B6C5D, 0x0, 0xDEADBEEF], -17, 'Unicode-Echo', true],
    ],
    '-4' => [
        [[0x13579BDF, 0x2468ACE0, 0xA1B2C3D4, 0x55667788], 123, 'WithoutUE3Word', false],
    ],
    '-5' => [
        [[0xFEEDBEEF, 0xABCDEF01, 0x00000001, 0x80000000], 507, 'DeprecatedFive', false],
    ],
];
$paths = [];
foreach ($templates as $marker => $items) {
    // FPackageFileSummary.cpp has 28 final bytes following
    // AdditionalPackagesToCook on this exact native 200-byte zero-table summary.
    // For -3/-4/-5 the pre-v7 NumTextureAllocations=0 adds 4 bytes there.
    $prefix = substr($raw, 0, 200);
    $prefix = substr_replace($prefix, pack('V', (int)$marker), 4, 4);
    $prefix = substr_replace($prefix, "\0\0\0\0", 172, 0);
    $entries = '';
    foreach ($items as [$words, $version, $friendly, $wide]) {
        $entries .= $guidBytes($words) . pack('V', $version & 0xFFFFFFFF) . $str($friendly, $wide);
    }
    $prefix = substr_replace($prefix, pack('V', count($items)) . $entries, 20, 4);
    if ((int)$marker === -4) {
        // UE4's legacy marker -4 omitted the old UE3 version word.
        $prefix = substr_replace($prefix, '', 8, 4);
    }
    if (strlen($prefix) > 4096) throw new RuntimeException('Summary outgrew header');
    $name = 'legacy-guid-' . abs((int)$marker) . '.uasset';
    $path = $dest . '/' . $name;
    $file = str_pad($prefix, 4096, "\0");
    if (file_put_contents($path, $file) !== 4096) throw new RuntimeException('Failed: ' . $path);
    $paths[] = str_replace('\\', '/', $path);
}
$manifest = $dest . '/list.txt';
file_put_contents($manifest, implode("\n", $paths) . "\n");
echo json_encode(['ok' => true, 'native_baseline' => $base,
    'fixtures' => $paths, 'native_input_manifest' => $manifest,
    'expected' => $templates], JSON_PRETTY_PRINT), PHP_EOL;
