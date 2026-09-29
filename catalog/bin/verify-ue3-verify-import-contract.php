#!/usr/bin/env php
<?php
/**
 * Contract verifier for deterministic UE3 VerifyImportInner projection matching.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
$canonicalRebuild = (string)file_get_contents($root . '/bin/rebuild-legacy-dependencies.php');
$sharedResolver = (string)file_get_contents($root . '/src/Infrastructure/Persistence/PdoDependencyResolver.php');
$ue3Resolver = (string)file_get_contents($root . '/src/Infrastructure/Persistence/PdoUe3VerifyImportProjectionResolver.php');
$workerFingerprint = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
require_once $root . '/src/Infrastructure/Persistence/PdoUe3VerifyImportProjectionResolver.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;

$rfPublic = 0x0000000400000000;
$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$consumer = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Foo', 'outer_index' => 0, 'relative_object_path' => ''],
    ['import_index' => 1, 'class_package' => 'Engine', 'class_name' => 'Texture', 'object_name' => 'Group', 'outer_index' => -1, 'relative_object_path' => 'Group'],
    ['import_index' => 2, 'class_package' => 'Engine', 'class_name' => 'Texture', 'object_name' => 'Wall', 'outer_index' => -2, 'relative_object_path' => 'Group.Wall'],
];
$providerImports = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Engine', 'outer_index' => 0],
    ['import_index' => 1, 'class_package' => 'Core', 'class_name' => 'Class', 'object_name' => 'Texture', 'outer_index' => -1],
];
$providerExports = [
    ['export_index' => 0, 'class_index' => -2, 'object_name' => 'Group', 'outer_index' => 0, 'object_flags' => $rfPublic, 'local_path' => 'Group'],
    ['export_index' => 1, 'class_index' => -2, 'object_name' => 'Wall', 'outer_index' => 1, 'object_flags' => $rfPublic, 'local_path' => 'Group.Wall'],
];

$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'exact_identity_and_outer_resolve',
    ($matches[1] ?? null) === 0 && ($matches[2] ?? null) === 1,
    'UE3 must resolve the parent first and require the child export OuterIndex to reference that exact export.'
);

$pathMismatch = $consumer;
$pathMismatch[1]['relative_object_path'] = 'Derived.Path.Is.Not.Identity';
$pathMismatch[2]['relative_object_path'] = 'Also.Not.Identity';
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $pathMismatch,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'derived_path_is_not_verify_import_identity',
    ($matches[1] ?? null) === 0 && ($matches[2] ?? null) === 1,
    'UE3 VerifyImport matches serialized ObjectName/ClassName/ClassPackage then OuterIndex; a derived catalog path must not prefilter valid exports.'
);

$bucketExports = $providerExports;
$bucketExports[] = ['export_index' => 2, 'class_index' => -2, 'object_name' => 'Wall', 'outer_index' => 0, 'object_flags' => $rfPublic, 'local_path' => 'Wrong.Wall'];
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $bucketExports,
    'Foo'
);
$check(
    'identity_bucket_continues_after_outer_mismatch',
    ($matches[2] ?? null) === 1,
    'UE3 must continue through exports in the same ObjectName/ClassName/ClassPackage bucket when a candidate fails the OuterIndex qualification.'
);

$redirectConsumer = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Foo', 'outer_index' => 0],
    ['import_index' => 1, 'class_package' => 'Engine', 'class_name' => 'SoundCueLocalized', 'object_name' => 'Cue', 'outer_index' => -1],
];
$redirectProviderImports = [
    ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Engine', 'outer_index' => 0],
    ['import_index' => 1, 'class_package' => 'Core', 'class_name' => 'Class', 'object_name' => 'SoundCueLocalized', 'outer_index' => -1],
];
$redirectProviderExports = [
    ['export_index' => 0, 'class_index' => -2, 'object_name' => 'Cue', 'outer_index' => 0, 'object_flags' => $rfPublic],
];
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $redirectConsumer,
    $redirectProviderImports,
    $redirectProviderExports,
    'Foo'
);
$check(
    'ue3_fixup_import_map_precedes_identity_hash',
    ($matches[1] ?? null) === 0,
    'UE3 FixupImportMap remaps SoundCueLocalized identities before HashNames/export matching on both consumer and provider linkers.'
);

$sequenceFixup = CatalogCompactIdentityEnricher::ue3FixupImportMap([
    0 => ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'SequenceObjects', 'outer_index' => 0],
    1 => ['import_index' => 1, 'class_package' => 'SequenceObjects', 'class_name' => 'SequenceAction', 'object_name' => 'Action', 'outer_index' => -1],
]);
$check(
    'ue3_sequenceobjects_fixup_precedes_matching',
    ($sequenceFixup[0]['object_name'] ?? '') === 'Engine'
        && ($sequenceFixup[1]['class_package'] ?? '') === 'Engine',
    'UE3 FixupImportMap moves old SequenceObjects package/class references to Engine before VerifyImport identity matching.'
);

$sequencePath = CatalogCompactIdentityEnricher::ue3EffectiveImportPath($sequenceFixup, 1);
$check(
    'ue3_dependency_identity_uses_post_fixup_outer_chain',
    $sequencePath['root'] === 'Engine'
        && $sequencePath['full'] === 'Engine.Action'
        && $sequencePath['relative'] === 'Action',
    'Persisted UE3 dependency package/path identity must be rebuilt from the post-FixupImportMap outer chain.'
);

$prefabImports = CatalogCompactIdentityEnricher::ue3FixupImportMap([
    0 => ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Engine', 'outer_index' => 0],
    1 => ['import_index' => 1, 'class_package' => 'Core', 'class_name' => 'Class', 'object_name' => 'Sequence', 'outer_index' => -1],
    2 => ['import_index' => 2, 'class_package' => 'Core', 'class_name' => 'Class', 'object_name' => 'Prefab', 'outer_index' => -1],
]);
$prefabExports = [
    0 => ['export_index' => 0, 'class_index' => -2, 'object_name' => 'Prefabs', 'outer_index' => 0, 'object_flags' => $rfPublic],
];
$oldPrefabClass = CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
    $prefabExports[0], $prefabImports, $prefabExports, 'MapPkg', 535
);
$newPrefabClass = CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
    $prefabExports[0], $prefabImports, $prefabExports, 'MapPkg', 536
);
$ut3PrefabClass = CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
    $prefabExports[0], $prefabImports, $prefabExports, 'MapPkg', 512, true
);
$check(
    'pre536_prefab_sequence_class_remap_matches_epic',
    $oldPrefabClass === ['Engine', 'PrefabSequenceContainer']
        && $newPrefabClass === ['Engine', 'Sequence'],
    'UE3 RemapClasses changes old pre-536 Prefabs Sequence exports before export hashing, while version 536+ keeps the serialized Sequence class.'
);
$check(
    'ut3_512_does_not_borrow_later_remapclasses',
    $ut3PrefabClass === ['Engine', 'Sequence'],
    'UT3 package version 512 uses the January 2008 linker, which has FixupImportMap but no RemapClasses pass.'
);
$laterRuntime512 = CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
    $prefabExports[0], $prefabImports, $prefabExports, 'MapPkg', 512, false
);
$check(
    'package_version_alone_does_not_select_ut3_policy',
    $laterRuntime512 === ['Engine', 'PrefabSequenceContainer'],
    'The owning game/source policy, not package version alone, selects the January 2008 UT3 linker behavior.'
);


$laterClassOuterImports = [
    0 => ['import_index' => 0, 'class_package' => 'Core', 'class_name' => 'Package', 'object_name' => 'Engine', 'outer_index' => 0],
    1 => ['import_index' => 1, 'class_package' => 'Core', 'class_name' => 'Class', 'object_name' => 'Texture', 'outer_index' => 1],
];
$laterClassOuterExports = [
    0 => ['export_index' => 0, 'class_index' => 0, 'object_name' => 'SomeClassOuter', 'outer_index' => 0, 'object_flags' => $rfPublic],
    1 => ['export_index' => 1, 'class_index' => -2, 'object_name' => 'Wall', 'outer_index' => 0, 'object_flags' => $rfPublic],
];
$ut3ClassIdentity = CatalogCompactIdentityEnricher::ue3ExportClassIdentity(
    $laterClassOuterExports[1], $laterClassOuterImports, $laterClassOuterExports, 'Foo', 512, true
);
$check(
    'ut3_512_class_import_outer_must_be_import',
    $ut3ClassIdentity === ['', 'Texture'],
    'January 2008 GetExportClassPackage requires the class import OuterIndex to be an import; the later export-outer rule must not leak into UT3.'
);

$invalidRoot = $consumer;
$invalidRoot[0]['class_package'] = 'Engine';
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $invalidRoot,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'root_package_import_requires_core_package',
    !isset($matches[1]) && !isset($matches[2]),
    'UE3 VerifyImportInner only establishes a provider linker from a top-level Core.Package import.'
);

$wrongOuter = $providerExports;
$wrongOuter[1]['outer_index'] = 0;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $wrongOuter,
    'Foo'
);
$check(
    'wrong_outer_rejected',
    !isset($matches[2]),
    'A same-path/class leaf whose serialized OuterIndex does not reference the resolved parent must not satisfy the import.'
);

$private = $providerExports;
$private[1]['object_flags'] = 0;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer,
    $providerImports,
    $private,
    'Foo'
);
$check(
    'private_export_rejected',
    !isset($matches[2]),
    'Ordinary UE3 external matching requires RF_Public.'
);

$wrongLowPublic = $providerExports;
$wrongLowPublic[1]['object_flags'] = 0x00000004;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $consumer, $providerImports, $wrongLowPublic, 'Foo'
);
$check(
    'ue3_rf_public_uses_64bit_source_flag',
    !isset($matches[2]),
    'UE3 RF_Public is 0x0000000400000000; the UE1-style low bit 0x4 must not pass visibility.'
);

$wrongClassPackage = $consumer;
$wrongClassPackage[2]['class_package'] = 'OtherEngine';
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $wrongClassPackage,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'class_package_is_exact',
    !isset($matches[2]),
    'ClassName alone is insufficient; UE3 VerifyImportInner also requires exact ClassPackage.'
);

$cookedOuter = $consumer;
$cookedOuter[2]['outer_index'] = 1;
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $cookedOuter,
    $providerImports,
    $providerExports,
    'Foo'
);
$check(
    'cooked_import_export_outer_not_invented',
    !isset($matches[2]),
    'The audited UE3 source returns on an import whose cooked OuterIndex is an export; the catalog must not invent a fallback.'
);

$missingParentConsumer = $consumer;
$missingParentConsumer[1]['object_name'] = 'MissingGroup';
$missingParentConsumer[1]['relative_object_path'] = 'MissingGroup';
$missingParentConsumer[2]['relative_object_path'] = 'MissingGroup.Wall';
$rootChild = [
    ['export_index' => 1, 'class_index' => -2, 'object_name' => 'Wall', 'outer_index' => 0, 'object_flags' => $rfPublic, 'local_path' => 'MissingGroup.Wall'],
];
$matches = PdoUe3VerifyImportProjectionResolver::resolveInMemory(
    $missingParentConsumer,
    $providerImports,
    $rootChild,
    'Foo'
);
$check(
    'failed_non_root_parent_is_not_package_root',
    !isset($matches[2]),
    'Only a verified Core.Package root may have SourceIndex INDEX_NONE; an unresolved non-root parent must propagate failure.'
);

$check(
    'ue3_db_lookup_uses_serialized_object_identity_not_path_hash',
    str_contains($ue3Resolver, '(value_hash=? AND value_length=?)')
        && str_contains($ue3Resolver, 'e.object_term_id IN (')
        && !str_contains($ue3Resolver, 'l.path_hash_ci IN ('),
    'The persisted UE3 matcher must seed candidates from serialized ObjectName, resolve provider-local FName spellings, then use indexed object_term_id lookup; derived catalog path hashes are not VerifyImport identity.'
);

$check(
    'ue3_parent_resolution_uses_serialized_outer_chain',
    str_contains($sharedResolver, 'self::ue3RootPackageName($importsByIndex')
        && str_contains($sharedResolver, '$isObjectImport = $ue3VerifyImport')
        && str_contains($sharedResolver, '? (int)($import[\'outer_index\'] ?? 0) !== 0'),
    'UE3 package grouping and object-import classification must come from serialized OuterIndex/Core.Package roots, not generated path strings.'
);

$check(
    'ue3_provider_lookup_is_scoped_and_indexed',
    preg_match('/PdoUe3VerifyImportProjectionResolver::resolveProvider\([^;]+\$requiredImportIndexes/s', $sharedResolver) === 1
        && str_contains($ue3Resolver, 'loadExactObjectNameTerms')
        && str_contains($ue3Resolver, 'loadSourceCandidates')
        && str_contains($ue3Resolver, 'rowsByIndexes')
        && str_contains($ue3Resolver, 'loadCaseInsensitiveCandidates')
        && str_contains($ue3Resolver, 'only unresolved names pay for the slower collation')
        && str_contains($sharedResolver, '$storageRoot'),
    'UE3 provider SQL must seed only the required package import closure, use indexed exact term IDs normally, and retain case-insensitive FName fallback only for unresolved names.'
);

$check(
    'worker_fingerprint_tracks_ue3_dependency_runtime',
    str_contains($workerFingerprint, '/src/Infrastructure/Persistence/PdoDependencyResolver.php')
        && str_contains($workerFingerprint, '/src/Infrastructure/Persistence/PdoUe3VerifyImportProjectionResolver.php')
        && str_contains($workerFingerprint, '/src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php')
        && str_contains($workerFingerprint, '/src/Infrastructure/Metadata/CompactDependencyRebuilder.php'),
    'Long-lived detached workers must be marked stale when shared or UE3 dependency-resolution code changes.'
);

$check(
    'ue3_provider_selection_keeps_partial_results_on_one_linker',
    str_contains($sharedResolver, '$bestCandidate = null;')
        && str_contains($sharedResolver, '$bestMatchCount = -1;')
        && str_contains($sharedResolver, 'if ($matchCount > $bestMatchCount)')
        && str_contains($sharedResolver, '$ue3VerifyImportMatches[$packageKey] = $bestMatches;'),
    'UE3 must select one physical provider/linker, then retain that linker successful per-Import VerifyImport results even when sibling Imports fail.'
);

$normalizeLookup = new ReflectionMethod(PdoDependencyResolver::class, 'normalizeLookup');
$numericPackageKey = $normalizeLookup->invoke(null, '123');
$check(
    'numeric_package_names_remain_string_lookup_keys',
    is_string($numericPackageKey) && $numericPackageKey === 'k:123',
    'PHP must not coerce numeric-looking package names into integer array keys before requiredImportIndexes receives them.'
);

$compactRebuilder = (string)file_get_contents($root . '/src/Infrastructure/Metadata/CompactDependencyRebuilder.php');
$backfill = (string)file_get_contents($root . '/bin/backfill-ue3-export-identities.php');
$reader = (string)file_get_contents($root . '/parsers/EpicUE3PackageReader.php');
$diagnostics = (string)file_get_contents($root . '/lib/CatalogDependencyDiagnostics.php');
$check(
    'targeted_verifyimport_uses_complete_import_map',
    str_contains($compactRebuilder, 'in_array($engineKey, [\'UE1\', \'UE2\', \'UE3\', \'UE4\'], true)')
        && str_contains($compactRebuilder, '? $imports')
        && str_contains($compactRebuilder, ': $importsToResolve'),
    'Targeted dependency refreshes may persist only selected rows, but source VerifyImport recursion must receive the complete consumer ImportMap.'
);
$check(
    'ue3_projection_requires_complete_class_identity',
    str_contains($backfill, 'class_package_term_id IS NULL')
        && str_contains($backfill, 'class_name_term_id IS NULL'),
    'UE3 exact matching cannot treat a projection as complete when class package/name identity is absent.'
);
$check(
    'ue3_fname_internal_number_uses_epic_external_number',
    str_contains($reader, '($fname[\'number\']-1)'),
    'UE3 FName internal number 1 must render as external suffix _0 per NAME_INTERNAL_TO_EXTERNAL.'
);
$check(
    'ue3_parser_preserves_full_64bit_object_flags',
    str_contains($reader, '($flags[\'high\'] << 32) | $flags[\'low\']'),
    'FObjectExport::ObjectFlags is a serialized QWORD; the parser must retain both 32-bit halves.'
);
$check(
    'ue3_diagnostics_use_ue3_projection_and_64bit_public_flag',
    str_contains($diagnostics, '$engine===\'UE3\'')
        && str_contains($diagnostics, 'ue_export_path_lookup')
        && str_contains($diagnostics, '0x0000000400000000'),
    'Admin dependency diagnostics must inspect the UE3 projection and the same 64-bit RF_Public flag as the resolver.'
);

$check(
    'canonical_rebuild_keeps_ue3_on_shared_source_policy',
    str_contains($canonicalRebuild, 'PdoCatalogDependencyRebuilder')
        && str_contains($canonicalRebuild, 'PdoUe3VerifyImportProjectionResolver')
        && str_contains($sharedResolver, '$ue3VerifyImport = $engineKey === \'UE3\';')
        && str_contains($sharedResolver, "PdoUe3VerifyImportProjectionResolver::resolveProvider("),
    'rebuild-legacy-dependencies.php must remain the canonical rebuild command and UE3 must dispatch through its VerifyImport projection resolver.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 3);
