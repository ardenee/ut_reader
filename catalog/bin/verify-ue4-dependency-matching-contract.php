#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) $failures[] = $name;
};

$scriptSnapshot = CatalogCompactIdentityEnricher::enrich([
    'file' => ['id'=>1,'game_id'=>7,'package_name'=>'/Game/Test','original_name'=>'Test.uasset'],
    'names' => [],
    'imports' => [[
        'import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Class','object_name'=>'Material',
        'outer_index'=>-2,'full_path'=>'/Script/Engine.Material','root_package'=>'/Script/Engine',
        'relative_object_path'=>'Material','is_common'=>0,
    ]],
    'exports' => [],
    'dependencies' => [],
    'paths' => ['imports'=>[0=>['full'=>'/Script/Engine.Material','root'=>'/Script/Engine','relative'=>'Material']],'exports'=>[]],
], 'UE4');
$check((int)$scriptSnapshot['imports'][0]['is_common'] === 1, 'ue4_script_package_is_common');

$paddedScriptSnapshot = CatalogCompactIdentityEnricher::enrich([
    'file' => ['id'=>2,'game_id'=>7,'package_name'=>'/Game/Test','original_name'=>'Test.uasset'],
    'names'=>[],
    'imports'=>[[
        'import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Class','object_name'=>'Material',
        'outer_index'=>-2,'full_path'=>' /Script/Engine.Material','root_package'=>' /Script/Engine',
        'relative_object_path'=>'Material','is_common'=>0,
    ]],
    'exports'=>[],'dependencies'=>[],
    'paths'=>['imports'=>[0=>['full'=>' /Script/Engine.Material','root'=>' /Script/Engine','relative'=>'Material']],'exports'=>[]],
], 'UE4');
$check((int)$paddedScriptSnapshot['imports'][0]['is_common'] === 0, 'ue4_padded_script_package_is_not_normalized_to_script');

$consumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'/Script/CoreUObject','class_name'=>'Class','object_name'=>'Material','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Material'],
];
$publicRootExport = [[
    'export_index'=>0,'class_index'=>0,'object_name'=>'Material','outer_index'=>0,'object_flags'=>1,'local_path'=>'Material','class_name'=>'',
]];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer, [], $publicRootExport, '/Game/TestPkg');
$check(($matches[1] ?? null) === 0, 'ue4_exact_public_root_match');

$whitespaceConsumer = $consumer;
$whitespaceConsumer[1]['object_name'] = ' Material ';
$whitespaceMiss = PdoUe4VerifyImportProjectionResolver::resolveInMemory($whitespaceConsumer, [], $publicRootExport, '/Game/TestPkg');
$check(!isset($whitespaceMiss[1]), 'ue4_fname_whitespace_is_identity_not_trimmed');
$whitespaceProvider = $publicRootExport;
$whitespaceProvider[0]['object_name'] = ' Material ';
$whitespaceHit = PdoUe4VerifyImportProjectionResolver::resolveInMemory($whitespaceConsumer, [], $whitespaceProvider, '/Game/TestPkg');
$check(($whitespaceHit[1] ?? null) === 0, 'ue4_exact_whitespace_fname_matches_exact_provider');

$wrongOuter = $publicRootExport;
$wrongOuter[0]['outer_index'] = 1;
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer, [], $wrongOuter, '/Game/TestPkg');
$check(!isset($matches[1]), 'ue4_wrong_outer_rejected');
$wrongOuterDiagnostic = PdoUe4VerifyImportProjectionResolver::diagnoseInMemoryOutcome(
    $consumer, [], $wrongOuter, '/Game/TestPkg'
);
$check(
    ($wrongOuterDiagnostic['rejections'][1]['reason'] ?? null) === 'outer_mismatch',
    'ue4_diagnostic_names_wrong_outer_rejection'
);

$private = $publicRootExport;
$private[0]['object_flags'] = 0;
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer, [], $private, '/Game/TestPkg');
$check(!isset($matches[1]), 'ue4_private_export_rejected');
$privateDiagnostic = PdoUe4VerifyImportProjectionResolver::diagnoseInMemoryOutcome(
    $consumer, [], $private, '/Game/TestPkg'
);
$check(
    ($privateDiagnostic['rejections'][1]['reason'] ?? null) === 'private_export_rejected',
    'ue4_diagnostic_names_private_export_rejection'
);
$missingObjectDiagnostic = PdoUe4VerifyImportProjectionResolver::diagnoseInMemoryOutcome(
    $consumer, [], [[
        'export_index'=>0,'class_index'=>0,'object_name'=>'DifferentObject','outer_index'=>0,'object_flags'=>1,
    ]], '/Game/TestPkg'
);
$check(
    ($missingObjectDiagnostic['rejections'][1]['reason'] ?? null) === 'object_name_not_found',
    'ue4_diagnostic_names_missing_object_rejection'
);
$wrongClassConsumer = $consumer;
$wrongClassConsumer[1]['class_name'] = 'Material';
$wrongClassDiagnostic = PdoUe4VerifyImportProjectionResolver::diagnoseInMemoryOutcome(
    $wrongClassConsumer, [], [[
        'export_index'=>0,'class_index'=>0,'object_name'=>'Material','outer_index'=>0,'object_flags'=>1,
    ]], '/Game/TestPkg'
);
$check(
    ($wrongClassDiagnostic['rejections'][1]['reason'] ?? null) === 'class_name_mismatch',
    'ue4_diagnostic_names_class_mismatch_rejection'
);
$classPackageConsumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'/Script/Expected','class_name'=>'SomeClass','object_name'=>'Thing','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Thing'],
];
$classPackageProviderImports = [
    ['import_index'=>0,'object_name'=>'/Script/Other','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'SomeClass','outer_index'=>-1],
];
$classPackageDiagnostic = PdoUe4VerifyImportProjectionResolver::diagnoseInMemoryOutcome(
    $classPackageConsumer,
    $classPackageProviderImports,
    [['export_index'=>0,'class_index'=>-2,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>1]],
    '/Game/TestPkg'
);
$check(
    ($classPackageDiagnostic['rejections'][1]['reason'] ?? null) === 'class_package_mismatch',
    'ue4_diagnostic_names_class_package_rejection'
);

$consumerExportGraph = [[
    'export_index'=>0,'class_index'=>0,'object_name'=>'ConsumerChild','outer_index'=>-2,'object_flags'=>1,
]];
$check(
    PdoUe4VerifyImportProjectionResolver::privateImportAllowedInMemory(1, $consumer, $consumerExportGraph),
    'ue4_private_import_allowed_when_consumer_export_shares_outermost'
);
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory(
    $consumer, [], $private, '/Game/TestPkg', $consumerExportGraph
);
$check(($matches[1] ?? null) === 0, 'ue4_private_export_resolves_when_source_graph_exception_applies');

$targetedConsumer = [$consumer[0], $consumer[1]];
$fullGraphImports = $consumer;
$fullGraphImports[] = [
    'import_index'=>2,'class_package'=>'/Script/CoreUObject','class_name'=>'Object','object_name'=>'GraphSibling',
    'outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'GraphSibling',
];
$fullGraphExports = [[
    'export_index'=>0,'class_index'=>0,'object_name'=>'GraphChild','outer_index'=>-3,'object_flags'=>1,
]];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory(
    $targetedConsumer, [], $private, '/Game/TestPkg', $fullGraphExports, $fullGraphImports
);
$check(($matches[1] ?? null) === 0, 'ue4_targeted_resolution_uses_full_consumer_graph_for_private_exception');

$providerImports = [
    ['import_index'=>0,'object_name'=>'/Other/CoreUObject','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'SomeClass','outer_index'=>-1],
];
$shortConsumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'/Script/CoreUObject','class_name'=>'SomeClass','object_name'=>'Thing','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Thing'],
];
$shortProvider = [[
    'export_index'=>0,'class_index'=>-2,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>1,'local_path'=>'Thing','class_name'=>'/Other/CoreUObject.SomeClass',
]];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($shortConsumer, $providerImports, $shortProvider, '/Game/TestPkg');
$check(($matches[1] ?? null) === 0, 'ue4_short_class_package_fallback_when_no_full_match');

$immediateOuterConsumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'ClassPackageNode','class_name'=>'SomeClass','object_name'=>'Thing','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Thing'],
];
$immediateOuterProviderImports = [
    ['import_index'=>0,'object_name'=>'/Different/Root','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'ClassPackageNode','outer_index'=>-1],
    ['import_index'=>2,'object_name'=>'SomeClass','outer_index'=>-2],
];
$immediateOuterProviderExports = [[
    'export_index'=>0,'class_index'=>-3,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>1,
]];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory(
    $immediateOuterConsumer,
    $immediateOuterProviderImports,
    $immediateOuterProviderExports,
    '/Game/TestPkg'
);
$check(($matches[1] ?? null) === 0, 'ue4_imported_class_package_uses_immediate_outer_object_name');

$exportOuterClassConsumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0,'root_package'=>'/Game/TestPkg','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'ClassPackageExport','class_name'=>'SomeClass','object_name'=>'Thing','outer_index'=>-1,'root_package'=>'/Game/TestPkg','relative_object_path'=>'Thing'],
];
$exportOuterClassProviderImports = [
    ['import_index'=>0,'object_name'=>'SomeClass','outer_index'=>1],
];
$exportOuterClassProviderExports = [
    ['export_index'=>0,'class_index'=>0,'object_name'=>'ClassPackageExport','outer_index'=>0,'object_flags'=>1],
    ['export_index'=>1,'class_index'=>-1,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>1],
];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory(
    $exportOuterClassConsumer,
    $exportOuterClassProviderImports,
    $exportOuterClassProviderExports,
    '/Game/TestPkg'
);
$check(($matches[1] ?? null) === 1, 'ue4_imported_class_package_can_use_immediate_export_outer_object_name');

$redirectConsumer = [
    ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/Redirected','outer_index'=>0,'root_package'=>'/Game/Redirected','relative_object_path'=>''],
    ['import_index'=>1,'class_package'=>'/Script/Engine','class_name'=>'Blueprint','object_name'=>'RedirectedAsset','outer_index'=>-1,'root_package'=>'/Game/Redirected','relative_object_path'=>'RedirectedAsset'],
];
$redirectProviderImports = [
    ['import_index'=>0,'object_name'=>'/Script/CoreUObject','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'ObjectRedirector','outer_index'=>-1],
];
$redirectProviderExports = [[
    'export_index'=>0,'class_index'=>-2,'object_name'=>'RedirectedAsset','outer_index'=>0,'object_flags'=>1,
]];
$redirectOutcome = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
    $redirectConsumer,
    $redirectProviderImports,
    $redirectProviderExports,
    '/Game/Redirected'
);
$check(!isset($redirectOutcome['matches'][1]), 'ue4_redirector_is_not_exact_original_import_match');
$check(($redirectOutcome['redirectors'][1] ?? null) === 0, 'ue4_verifyimport_second_pass_detects_object_redirector');

$redirectDescendantConsumer = $redirectConsumer;
$redirectDescendantConsumer[] = [
    'import_index'=>2,'class_package'=>'/Script/Engine','class_name'=>'SceneComponent','object_name'=>'Sprite',
    'outer_index'=>-2,'root_package'=>'/Game/Redirected','relative_object_path'=>'RedirectedAsset.Sprite',
];
$redirectDescendantOutcome = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
    $redirectDescendantConsumer,
    $redirectProviderImports,
    $redirectProviderExports,
    '/Game/Redirected'
);
$check(
    ($redirectDescendantOutcome['redirector_ancestry'][2] ?? null) === 1
        && !isset($redirectDescendantOutcome['redirector_ancestry'][1]),
    'ue4_redirector_outer_makes_descendant_payload_unresolved'
);
$check(
    !isset(PdoUe4VerifyImportProjectionResolver::resolveInMemory(
        $redirectConsumer,
        $redirectProviderImports,
        $redirectProviderExports,
        '/Game/Redirected'
    )[1]),
    'ue4_legacy_match_api_never_returns_redirector_as_original_target'
);
$privateRedirectProvider = $redirectProviderExports;
$privateRedirectProvider[0]['object_flags'] = 0;
$privateRedirectOutcome = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
    $redirectConsumer,
    $redirectProviderImports,
    $privateRedirectProvider,
    '/Game/Redirected'
);
$check(!isset($privateRedirectOutcome['redirectors'][1]), 'ue4_private_redirector_without_graph_exception_is_not_accepted');

$namedRedirectorConsumer = $redirectConsumer;
$namedRedirectorConsumer[1]['object_name'] = 'ObjectRedirector';
$namedRedirectorOutcome = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
    $namedRedirectorConsumer,
    $redirectProviderImports,
    [[
        'export_index'=>0,'class_index'=>-2,'object_name'=>'ObjectRedirector','outer_index'=>0,'object_flags'=>1,
    ]],
    '/Game/Redirected'
);
$check(!isset($namedRedirectorOutcome['redirectors'][1]), 'ue4_wrapper_does_not_retry_when_import_object_name_is_objectredirector');

$incompleteRedirectConsumer = $redirectConsumer;
$incompleteRedirectConsumer[1]['class_name'] = '';
$incompleteRedirectOutcome = PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(
    $incompleteRedirectConsumer,
    $redirectProviderImports,
    $redirectProviderExports,
    '/Game/Redirected'
);
$check(!isset($incompleteRedirectOutcome['redirectors'][1]), 'ue4_wrapper_does_not_retry_after_irrelevant_incomplete_import_identity');

$providerImportsWithExact = [
    ['import_index'=>0,'object_name'=>'/Other/CoreUObject','outer_index'=>0],
    ['import_index'=>1,'object_name'=>'SomeClass','outer_index'=>-1],
    ['import_index'=>2,'object_name'=>'/Script/CoreUObject','outer_index'=>0],
    ['import_index'=>3,'object_name'=>'SomeClass','outer_index'=>-3],
];
$fullSuppressesShort = [
    ['export_index'=>0,'class_index'=>-2,'object_name'=>'Thing','outer_index'=>0,'object_flags'=>1,'local_path'=>'Thing','class_name'=>'/Other/CoreUObject.SomeClass'],
    ['export_index'=>1,'class_index'=>-4,'object_name'=>'Thing','outer_index'=>1,'object_flags'=>1,'local_path'=>'Outer.Thing','class_name'=>'/Script/CoreUObject.SomeClass'],
];
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($shortConsumer, $providerImportsWithExact, $fullSuppressesShort, '/Game/TestPkg');
$check(!isset($matches[1]), 'ue4_full_class_package_candidate_suppresses_short_fallback_before_outer');

$exportOuterConsumer = $consumer;
$exportOuterConsumer[1]['outer_index'] = 1;
$matches = PdoUe4VerifyImportProjectionResolver::resolveInMemory($exportOuterConsumer, [], $publicRootExport, '/Game/TestPkg');
$check(!isset($matches[1]), 'v4_does_not_guess_ue4_export_outer_package_context');

$resolverSource = file_get_contents($root . '/src/Infrastructure/Persistence/PdoDependencyResolver.php') ?: '';
$ue4Start = strpos($resolverSource, '$ue4VerifyImportMatches = []');
$ue4End = $ue4Start !== false ? strpos($resolverSource, '$resolved = [];', $ue4Start) : false;
$ue4Block = ($ue4Start !== false && $ue4End !== false)
    ? substr($resolverSource, $ue4Start, $ue4End - $ue4Start)
    : '';
$check(
    str_contains($ue4Block, '$candidate = $packageMatches[$packageKey] ?? null;')
        && str_contains($ue4Block, 'PdoUe4VerifyImportProjectionResolver::resolveProviderOutcome(')
        && str_contains($ue4Block, '$consumerExports')
        && !str_contains($ue4Block, '$bestMatchCount')
        && !str_contains($ue4Block, '$bestRedirectorCount'),
    'ue4_provider_selected_before_verifyimport'
);

$auditSource = file_get_contents($root . '/bin/audit-ue4-missing-verifyimport.php') ?: '';
$check(
    str_contains($auditSource, "'SELECT l.file_id,l.import_index,CONVERT(pkg.value_prefix USING utf8mb4) required_package '")
        && str_contains($auditSource, '$missingRequiredSet')
        && str_contains($auditSource, 'if (!isset($missingRequiredSet[$index])) continue;')
        && str_contains($auditSource, 'Reason counts/examples include only object Imports whose persisted ue_dependency_links.status is missing (0).'),
    'ue4_rejection_reason_audit_only_counts_persisted_missing_object_imports'
);

$dependencyResolverSource = file_get_contents($root . '/src/Infrastructure/Persistence/PdoDependencyResolver.php') ?: '';
$check(
    str_contains($dependencyResolverSource, "'source' => 'ue4_object_redirector_target_unavailable'")
        && str_contains($dependencyResolverSource, "'confidence' => 'payload_unresolved'")
        && str_contains($dependencyResolverSource, '$ue4VerifyImportRedirectors')
        && str_contains($dependencyResolverSource, '$ue4VerifyImportRedirectorAncestry')
        && str_contains($dependencyResolverSource, "'source' => 'ue4_object_redirector_ancestor_target_unavailable'"),
    'ue4_redirector_outcome_persists_as_unresolved_without_fabricated_target'
);

$result = [
    'ok' => $failures === [],
    'checks' => 27,
    'failures' => $failures,
    'contract' => [
        'consumer_imports_only_create_requirements',
        'object_class_class_package_outer_and_public_must_match',
        'private_exports_follow_ue4_editor_consumer_graph_exceptions',
        'targeted_resolution_keeps_full_consumer_graph_for_private_exceptions',
        'imported_class_package_uses_immediate_outer_resource_object_name',
        'object_redirector_second_pass_is_detected_but_not_returned_as_original_target',
        'redirector_outer_descendants_are_payload_unresolved',
        'short_class_package_fallback_only_without_any_full_package_match',
        'one_physical_provider_is_used_without_invalidating_successful_siblings',
        'v4_does_not_guess_package_name_for_modern_export_outer_imports',
        'read_only_diagnostics_explain_object_class_outer_and_private_rejections',
        'rejection_reason_audit_only_counts_persisted_missing_object_imports',
    ],
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
