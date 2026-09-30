#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
require_once $root . '/src/Infrastructure/Metadata/Uedb5Ut99SnapshotBuilder.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};
$u32 = static fn(int $value): string => pack('V', $value & 0xFFFFFFFF);
$compact = static function (int $value): string {
    $negative = $value < 0;
    $magnitude = abs($value);
    $first = $magnitude & 0x3F;
    $magnitude >>= 6;
    if ($negative) { $first |= 0x80; }
    if ($magnitude !== 0) { $first |= 0x40; }
    $bytes = chr($first);
    while ($magnitude !== 0) {
        $next = $magnitude & 0x7F;
        $magnitude >>= 7;
        if ($magnitude !== 0) { $next |= 0x80; }
        $bytes .= chr($next);
    }
    return $bytes;
};
$fixture = static function (int $version, int $licensee = 0) use ($u32, $compact): string {
    $packedVersion = ($licensee << 16) | ($version & 0xFFFF);
    $header = $u32(0x9E2A83C1) . $u32($packedVersion) . $u32(0);
    $nameBytes = $version < 64
        ? "Test\0" . $u32(0x00000003)
        : $compact(5) . "Test\0" . $u32(0x00000003);

    if ($version < 68) {
        $summarySize = 44;
        $heritageOffset = $summarySize;
        $nameOffset = $heritageOffset + 16;
        $header .= $u32(1) . $u32($nameOffset) . $u32(0) . $u32(0) . $u32(0) . $u32(0);
        $header .= $u32(1) . $u32($heritageOffset);
        $guid = $u32(1) . $u32(2) . $u32(3) . $u32(4);
        return $header . $guid . $nameBytes;
    }
    $summarySize = 64;
    $nameOffset = $summarySize;
    $header .= $u32(1) . $u32($nameOffset) . $u32(0) . $u32(0) . $u32(0) . $u32(0);
    $header .= $u32(0x11111111) . $u32(0x22222222) . $u32(0x33333333) . $u32(0x44444444);
    $header .= $u32(1) . $u32(0) . $u32(1);
    return $header . $nameBytes;
};

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-ut99-' . bin2hex(random_bytes(5));
if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
    throw new RuntimeException('Could not create UT99 UEDB5 verifier temp directory.');
}
$writer = new Uedb5MetadataSnapshotWriter($temp);
$reader = new Uedb5MetadataReader($temp);
try {
    foreach ([[61,0],[68,0],[69,127]] as $case => [$version,$licensee]) {
        $source = $temp . DIRECTORY_SEPARATOR . 'source-' . $version . '.unr';
        file_put_contents($source, $fixture($version, $licensee));
        $package = new CatalogUE1PackageReader($source);
        $snapshot = Uedb5Ut99SnapshotBuilder::build($package, [
            'id' => 1000 + $case,
            'game_id' => 3,
            'package_name' => 'Fixture' . $version,
            'original_name' => 'Fixture' . $version . '.unr',
        ]);
        $summary = (array)$snapshot['sections']['summary'][0];
        $name = (array)$snapshot['sections']['names'][0];
        $check('v' . $version . '_reader_has_no_issues', $package->validatePackage() === []);
        $check('v' . $version . '_source_row_offset_preserved', (int)$name['serialized_offset'] > 0);
        $check('v' . $version . '_name_flags_preserved', ($name['flags'] ?? '') === '00000003');
        if ($version < 68) {
            $check('pre68_uses_heritage_layout', ($summary['summary_layout'] ?? '') === 'heritage-table');
            $check('pre68_heritage_is_present', ($summary['heritage_count'] ?? null) === 1);
            $check('pre68_generation_count_is_absent', ($summary['generation_count'] ?? null) === null);
        } else {
            $check('v' . $version . '_uses_guid_generations', ($summary['summary_layout'] ?? '') === 'guid-generations');
            $check('v' . $version . '_generation_count_preserved', ($summary['generation_count'] ?? null) === 1);
        }
        if ($version === 69) {
            $check('v69_uses_supplemental_policy', ($snapshot['source_policy'] ?? '') === Uedb5Ut99SnapshotBuilder::POLICY_V69);
            $check('v69_licensee_bits_preserved', ($summary['licensee_version'] ?? null) === 127);
        } else {
            $check('v' . $version . '_uses_retail_policy', ($snapshot['source_policy'] ?? '') === Uedb5Ut99SnapshotBuilder::POLICY_RETAIL);
        }
        $written = $writer->write($snapshot, 1024);
        $roundTrip = $reader->snapshot(3, 1000 + $case);
        $check('v' . $version . '_uedb5_roundtrip_family', ($roundTrip['package_family'] ?? '') === Uedb5Ut99SnapshotBuilder::PACKAGE_FAMILY);
        $check('v' . $version . '_uedb5_roundtrip_policy', ($roundTrip['source_policy'] ?? '') === ($snapshot['source_policy'] ?? ''));
        $check('v' . $version . '_uedb5_roundtrip_name', (($roundTrip['sections']['names'][0]['text'] ?? '') === 'Test'));
        $check('v' . $version . '_container_hash_is_binary_sha256', strlen((string)($written['payload_sha256'] ?? '')) === 32);
    }

    $licensed68Path = $temp . DIRECTORY_SEPARATOR . 'source-68-licensee7.unr';
    file_put_contents($licensed68Path, $fixture(68, 7));
    $licensed68 = new CatalogUE1PackageReader($licensed68Path);
    $licensed68Snapshot = Uedb5Ut99SnapshotBuilder::build($licensed68, [
        'id'=>1999,'game_id'=>3,'package_name'=>'Licensed68','original_name'=>'Licensed68.unr'
    ]);
    $check('v68_licensee_bits_use_supplemental_policy',
        ($licensed68Snapshot['source_policy'] ?? '') === Uedb5Ut99SnapshotBuilder::POLICY_SUPPLEMENTAL);
    $check('v68_licensee_bits_are_preserved',
        (($licensed68Snapshot['sections']['summary'][0]['licensee_version'] ?? null) === 7));

    $unsupportedPath = $temp . DIRECTORY_SEPARATOR . 'source-71.unr';
    file_put_contents($unsupportedPath, $fixture(71, 0));
    $unsupported = new CatalogUE1PackageReader($unsupportedPath);
    $check('v71_still_parses_structurally', $unsupported->validatePackage() === []);
    $rejected = false;
    try {
        Uedb5Ut99SnapshotBuilder::build($unsupported, ['id'=>2000,'game_id'=>3,'package_name'=>'Unsupported','original_name'=>'Unsupported.unr']);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'outside source-proven V5 range 60-69');
    }
    $check('v71_is_not_claimed_source_compliant', $rejected);
} finally {
    if (is_dir($temp)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($temp);
    }
}

echo json_encode(['ok'=>$failures === [],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
