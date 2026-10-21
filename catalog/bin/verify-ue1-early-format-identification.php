<?php
/**
 * UE1 original Unreal v0.867 file-identification boundary.
 * Source: Src/Inc/UnBuild.h:108; Src/Inc/UnLinker.h:258-310,423-454.
 * UnrealDB does not yet implement the pre-PACKAGE_FILE_TAG object format:
 * identifying it must not be mistaken for successfully parsing it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/GameProfiles.php';
require_once dirname(__DIR__) . '/src/Application/Catalog/CatalogPackageHeaderInspector.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;

$failures = [];
$checks = [];
$check = static function (string $label, bool $pass) use (&$checks, &$failures): void {
    $checks[$label] = $pass;
    if (!$pass) { $failures[] = $label; }
};
$path = tempnam(sys_get_temp_dir(), 'ue1_unrealfile_');
if (!is_string($path)) { throw new RuntimeException('Could not create test file.'); }
try {
    // 32-byte fixed string; version, objects, names, offsets, dependencies.
    $old = pack('a32', "Unrealfile\x1A") . pack('V6', 23, 0, 0, 0, 0, 0);
    file_put_contents($path, $old);
    $classified = gp_read_legacy_summary($path);
    $inspected = CatalogPackageHeaderInspector::inspect($path, ['engine_key' => 'UE1', 'extension' => 'unr']);
    $check('early_format_not_falsely_accepted', ($classified['ok'] ?? null) === false);
    $check('early_format_has_specific_diagnostic', ($classified['error_code'] ?? '') === 'unreal.early_unrealfile_unsupported');
    $check('early_format_carries_engine_identity', ($classified['engine_hint'] ?? '') === 'UE1');
    $check('early_format_carries_source_version', ($classified['version'] ?? null) === 23);
    $check('header_inspector_reports_early_format_limit', ($inspected['ok'] ?? null) === false && str_contains((string)($inspected['error'] ?? ''), 'Early Unrealfile'));

    file_put_contents($path, pack('a32', "Unrealfile\x1B") . pack('V6', 23, 0, 0, 0, 0, 0));
    $notOld = gp_read_legacy_summary($path);
    $check('near_miss_is_not_early_unrealfile', ($notOld['error_code'] ?? '') === 'unreal.magic_not_found');

    file_put_contents($path, pack('Vvv', 0x9E2A83C1, 68, 0) . str_repeat("\0", 50));
    $newer = gp_read_legacy_summary($path);
    $check('later_ue1_standard_header_remains_supported', ($newer['ok'] ?? false) === true && ($newer['version'] ?? null) === 68 && ($newer['engine_hint'] ?? '') === 'UE1');

    file_put_contents($path, pack('Vvv', 0x9E2A83C2, 68, 0) . str_repeat("\0", 50));
    $wrongEngine = CatalogPackageHeaderInspector::inspect($path, ['engine_key' => 'UE1', 'extension' => 'u']);
    $check('ue1_inspector_does_not_admit_ue2_tag', ($wrongEngine['ok'] ?? null) === false);
    $ue2 = CatalogPackageHeaderInspector::inspect($path, ['engine_key' => 'UE2', 'extension' => 'u']);
    $check('ue2_inspector_retains_killing_floor_tag', ($ue2['ok'] ?? null) === true);
} finally {
    @unlink($path);
}
echo json_encode(['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
