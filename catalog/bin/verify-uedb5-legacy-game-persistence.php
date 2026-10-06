#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2003SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2004SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

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
    $packed = ($licensee << 16) | ($version & 0xFFFF);
    $header = $u32(0x9E2A83C1) . $u32($packed) . $u32(0);
    $name = $version < 64
        ? "Test\0" . $u32(3)
        : $compact(5) . "Test\0" . $u32(3);
    if ($version < 68) {
        $summarySize = 44;
        $heritageOffset = $summarySize;
        $nameOffset = $heritageOffset + 16;
        $header .= $u32(1) . $u32($nameOffset) . $u32(0) . $u32(0) . $u32(0) . $u32(0);
        $header .= $u32(1) . $u32($heritageOffset);
        return $header . $u32(1) . $u32(2) . $u32(3) . $u32(4) . $name;
    }
    $summarySize = 64;
    $header .= $u32(1) . $u32($summarySize) . $u32(0) . $u32(0) . $u32(0) . $u32(0);
    $header .= $u32(1) . $u32(2) . $u32(3) . $u32(4);
    $header .= $u32(1) . $u32(0) . $u32(1);
    return $header . $name;
};

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-legacy-games-' . bin2hex(random_bytes(5));
if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
    throw new RuntimeException('Could not create legacy-game verifier directory.');
}
$writer = new Uedb5MetadataSnapshotWriter($temp);
$reader = new Uedb5MetadataReader($temp);

$accept = static function (
    string $label,
    string $builder,
    string $readerClass,
    int $gameId,
    int $version,
    string $expectedPolicy
) use ($fixture, $temp, $writer, $reader, $check): void {
    $id = $gameId * 1000 + $version;
    $path = $temp . DIRECTORY_SEPARATOR . $label . '-' . $version . '.upk';
    file_put_contents($path, $fixture($version));
    $package = new $readerClass($path);
    $snapshot = $builder::build($package, [
        'id' => $id,
        'game_id' => $gameId,
        'package_name' => 'Fixture' . $version,
        'original_name' => 'Fixture' . $version . '.upk',
    ]);
    $check($label . '_v' . $version . '_policy', ($snapshot['source_policy'] ?? '') === $expectedPolicy);
    $check($label . '_v' . $version . '_family', ($snapshot['package_family'] ?? '') === 'classic-linkerload');
    $check($label . '_v' . $version . '_name_offset', (int)($snapshot['sections']['names'][0]['serialized_offset'] ?? -1) > 0);
    $check($label . '_v' . $version . '_name_flags', ($snapshot['sections']['names'][0]['flags'] ?? '') === '00000003');
    $writer->write($snapshot, 1024);
    $roundTrip = $reader->snapshot($gameId, $id);
    $check($label . '_v' . $version . '_roundtrip_policy', ($roundTrip['source_policy'] ?? '') === $expectedPolicy);
};

try {
    $accept('unreal60', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 60, Uedb5UnrealSnapshotBuilder::POLICY_V224_PARTIAL);
    $accept('unreal68', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 68, Uedb5UnrealSnapshotBuilder::POLICY_V224_PARTIAL);
    $accept('unreal69', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 69, Uedb5UnrealSnapshotBuilder::POLICY_V227_PARTIAL);
    $accept('unreal54', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 54, Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY);
    $accept('unreal71', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 71, Uedb5UnrealSnapshotBuilder::POLICY_POST_V69_UNRESOLVED);
    $accept('unreal76', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 76, Uedb5UnrealSnapshotBuilder::POLICY_POST_V69_UNRESOLVED);
    $accept('unreal77', Uedb5UnrealSnapshotBuilder::class, CatalogUE1PackageReader::class, 12, 77, Uedb5UnrealSnapshotBuilder::POLICY_POST_V69_UNRESOLVED);

    $accept('unreal2_60', Uedb5Unreal2SnapshotBuilder::class, CatalogUE2PackageReader::class, 2, 60, Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000);
    $accept('unreal2_69', Uedb5Unreal2SnapshotBuilder::class, CatalogUE2PackageReader::class, 2, 69, Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000);
    $accept('unreal2_126', Uedb5Unreal2SnapshotBuilder::class, CatalogUE2PackageReader::class, 2, 126, Uedb5Unreal2SnapshotBuilder::SOURCE_POLICY);
    $accept('unreal2_127', Uedb5Unreal2SnapshotBuilder::class, CatalogUE2PackageReader::class, 2, 127, Uedb5Unreal2SnapshotBuilder::POLICY_FORWARD_COMPAT);
    $accept('unreal2_128', Uedb5Unreal2SnapshotBuilder::class, CatalogUE2PackageReader::class, 2, 128, Uedb5Unreal2SnapshotBuilder::POLICY_FORWARD_COMPAT);
    $accept('unreal2_129', Uedb5Unreal2SnapshotBuilder::class, CatalogUE2PackageReader::class, 2, 129, Uedb5Unreal2SnapshotBuilder::POLICY_FORWARD_COMPAT);

    $accept('ut2003_60', Uedb5Ut2003SnapshotBuilder::class, CatalogUE2PackageReader::class, 4, 60, Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY);
    $accept('ut2003_120', Uedb5Ut2003SnapshotBuilder::class, CatalogUE2PackageReader::class, 4, 120, Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY);
    $accept('ut2003_121', Uedb5Ut2003SnapshotBuilder::class, CatalogUE2PackageReader::class, 4, 121, Uedb5Ut2003SnapshotBuilder::POLICY_FORWARD_COMPAT);
    $accept('ut2003_128', Uedb5Ut2003SnapshotBuilder::class, CatalogUE2PackageReader::class, 4, 128, Uedb5Ut2003SnapshotBuilder::POLICY_FORWARD_COMPAT);
    $accept('ut2003_129', Uedb5Ut2003SnapshotBuilder::class, CatalogUE2PackageReader::class, 4, 129, Uedb5Ut2003SnapshotBuilder::POLICY_FORWARD_COMPAT);

    $accept('ut2004_60', Uedb5Ut2004SnapshotBuilder::class, CatalogUE2PackageReader::class, 5, 60, Uedb5Ut2004SnapshotBuilder::POLICY_V128);
    $accept('ut2004_128', Uedb5Ut2004SnapshotBuilder::class, CatalogUE2PackageReader::class, 5, 128, Uedb5Ut2004SnapshotBuilder::POLICY_V128);
    $accept('ut2004_129', Uedb5Ut2004SnapshotBuilder::class, CatalogUE2PackageReader::class, 5, 129, Uedb5Ut2004SnapshotBuilder::POLICY_V129);
    $accept('ut2004_130', Uedb5Ut2004SnapshotBuilder::class, CatalogUE2PackageReader::class, 5, 130, Uedb5Ut2004SnapshotBuilder::POLICY_V129);
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

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
