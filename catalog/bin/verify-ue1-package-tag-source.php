<?php
/**
 * UE1 package tag admission, from the pinned UT99 v400 and Unreal v227 C++.
 *
 * UT99 v400:
 *   Core/Inc/UnObjVer.h:20 (PACKAGE_FILE_TAG)
 *   Core/Src/UnLinker.h:362-369 (ULinkerLoad::ULinkerLoad CheckTag)
 * Unreal v227:
 *   Core/Inc/UnObjVer.h:23 (PACKAGE_FILE_TAG)
 *
 * The special 0x9E2A83C2 tag belongs to a UE2/Killing Floor variant and
 * must not be accepted by the UE1 reader. UE2 admission stays unchanged.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

$failures = [];
$results = [];
$check = static function (string $name, bool $passed) use (&$failures, &$results): void {
    $results[$name] = $passed;
    if (!$passed) {
        $failures[] = $name;
    }
};
$makePackage = static function (int $tag): string {
    return pack('V', $tag)
        . pack('V', 68)
        . pack('V', 0)
        . pack('V6', 0, 0, 0, 0, 0, 0)
        . pack('V4', 1, 2, 3, 4)
        . pack('V', 0);
};
$path = tempnam(sys_get_temp_dir(), 'ue1_tag_');
if (!is_string($path)) {
    throw new RuntimeException('Could not create source-branch tag test fixture.');
}
try {
    file_put_contents($path, $makePackage(0x9E2A83C1));
    $ut99 = new CatalogUE1PackageReader($path);
    $unreal = new CatalogUE1PackageReader($path, true);
    $check('ut99_v400_accepts_epic_standard_tag', $ut99->validatePackage() === []);
    $check('unreal_v227_accepts_epic_standard_tag', $unreal->validatePackage() === []);

    file_put_contents($path, $makePackage(0x9E2A83C2));
    $ue1 = new CatalogUE1PackageReader($path);
    $ue1V227 = new CatalogUE1PackageReader($path, true);
    $ue2 = new CatalogUE2PackageReader($path);
    $check('ut99_v400_rejects_ue2_killing_floor_tag', $ue1->validatePackage() !== []);
    $check('unreal_v227_rejects_ue2_killing_floor_tag', $ue1V227->validatePackage() !== []);
    $check('ue2_killing_floor_tag_remains_admitted', $ue2->validatePackage() === []);

    file_put_contents($path, $makePackage(0x9E2A83C3));
    $unknown = new CatalogUE1PackageReader($path);
    $check('ue1_rejects_unknown_tag', $unknown->validatePackage() !== []);
} finally {
    @unlink($path);
}
echo json_encode(
    ['ok' => $failures === [], 'checks' => $results, 'failures' => $failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;
exit($failures === [] ? 0 : 1);
