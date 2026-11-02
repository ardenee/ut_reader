#!/usr/bin/env php
<?php
/** Static contract for generation-specific Unreal dependency identity normalization. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repo = dirname($root);
$read = static fn(string $p): string => (string)@file_get_contents($repo . '/' . $p);
$legacy = $read('catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php');
$ue3 = $read('catalog/parsers/EpicUE3PackageReader.php');
$ue4 = $read('UE4/UnrealPackageReader.php');
$builder = $read('catalog/src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php');
$coverage = $read('catalog/src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php');
$checks=[];
$check=static function(string $n,bool $ok,string $d)use(&$checks){$checks[]=['check'=>$n,'ok'=>$ok,'detail'=>$d];};
foreach (['legacy_ue1_ue2'=>$legacy,'ue3'=>$ue3,'ue4_family'=>$ue4] as $name=>$source) {
    $check(
        $name . '_exposes_unreal_import_identity',
        $source !== ''
            && str_contains($source, 'classPackage')
            && str_contains($source, 'className')
            && str_contains($source, 'outerIndex')
            && str_contains($source, 'objectName'),
        'Reader must preserve FObjectImport class package/name, Outer PackageIndex, and ObjectName/FName identity.'
    );
}
$check(
    'normalized_paths_follow_packageindex_outer_chain',
    str_contains($builder, 'scanner_ref_path')
        && str_contains($builder, "'outer_index'")
        && str_contains($builder, "'relative_object_path'")
        && str_contains($builder, "'local_path'"),
    'Normalized dependency identity must be reconstructed from Unreal PackageIndex Outer chains, not terminal names.'
);
$check(
    'normalized_import_class_identity_is_retained',
    str_contains($builder, "'class_package'")
        && str_contains($builder, "'class_name'")
        && str_contains($builder, "'class_package_name_index'")
        && str_contains($builder, "'class_name_index'"),
    'Current-format Import rows retain both class FNames and their direct Name indexes.'
);
$check(
    'provider_coverage_checks_path_and_class',
    str_contains($coverage, 'requiredClassesByPath')
        && str_contains($coverage, 'path_hash_ci')
        && str_contains($coverage, 'class_name')
        && str_contains($coverage, 'class_package'),
    'Compatibility requires the normalized object path and, when available, Unreal class identity.'
);
$ok=!in_array(false,array_column($checks,'ok'),true);
echo json_encode(['ok'=>$ok,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
