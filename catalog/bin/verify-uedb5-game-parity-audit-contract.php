#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameParityExpectedDifferences;

$service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameParityAuditService.php');
$v5=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5ParityV5ReadService.php');
$cli=(string)file_get_contents($root.'/bin/audit-uedb5-game-parity.php');
$checks=[];$failures=[];
$record=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok)$failures[]=$name;};
$categories=['dependencies','requires_required_by','base_game_missing','package_aliases','invalid_file_exclusions','duplicate_provider_handling','search_results','verify_import_decisions'];
foreach($categories as $category)$record('category_'.$category,str_contains($service,"['$category']"));
$record('full_game_readiness_requires_v4_v5_and_current_pass2',str_contains($service,'$v4===$verified')&&str_contains($service,'$v5===$verified')&&str_contains($service,'$completed===$verified')&&str_contains($service,'missing_primary_provider_count')&&str_contains($service,'invalid_staged_count')&&str_contains($service,'DEPENDENCY_POLICY'));
$record('audit_refuses_before_ready',str_contains($service,'Game is not ready for Step 9 parity audit'));
$record('v5_search_uses_candidate_then_targeted_uedb_hydration',str_contains($v5,'ue_uedb5_name_candidates')&&str_contains($v5,'ue_uedb5_object_candidates')&&str_contains($v5,'ue_uedb5_dependency_edges')&&str_contains($v5,'rowsByPositions')&&str_contains($v5,'candidateRowsMatch')&&str_contains($v5,'dependencyRowsContainQuery'));
$exactStart=strpos($v5,'public function exactMetadataSearch');$exactEnd=strpos($v5,'private function collectIds',$exactStart);$exactBody=substr($v5,$exactStart,$exactEnd-$exactStart);
$record('v5_search_does_not_materialize_full_snapshots',!str_contains($exactBody,'->snapshot(')&&!str_contains($exactBody,'snapshotMatches'));
$record('v5_parity_reader_never_reads_uedb4',!str_contains($v5,'.uedb4')&&!str_contains($v5,'BlockedCompressedMetadataReader'));
$writePattern='/\b(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i';
$record('parity_service_is_read_only',preg_match($writePattern,$service)===0&&preg_match($writePattern,$v5)===0);
$record('cli_has_preflight',str_contains($cli,"'preflight'"));
$record('search_corpus_respects_production_minimum_length',substr_count($service,'value_length BETWEEN 3 AND 200')===3);
$record('provider_selection_is_compared',str_contains($service,'provider_selection_mismatch_count'));
$record('object_coverage_is_compared',str_contains($service,'object_coverage_mismatch_count'));
$record('base_game_missing_is_compared',str_contains($service,'officialBaseGamePackageNames'));
$record('requires_required_by_graph_is_compared',str_contains($service,'v4_requires_pairs')&&str_contains($service,'v5_requires_pairs'));
$record('invalid_identity_exclusion_is_checked',str_contains($service,'ue_invalid_file_identities'));
$record('duplicate_providers_never_merge',str_contains($service,'one physical provider must independently satisfy a dependency'));
$record('aliases_are_provider_keys',str_contains($service,'source_kind=2')&&str_contains($service,'ue_file_package_aliases'));
$record('private_verifyimport_is_audited',str_contains($service,'private_export_rejected'));

$rule=Uedb5GameParityExpectedDifferences::classify('ut3','dependency_outcome',['outcome'=>'missing'],[
    'outcome'=>'unresolved','reason_code'=>'source_unresolved','source_policy'=>'ue3-ut3-2008-01-01',
    'resolver_detail'=>['source'=>'ue3_cooked_export_outer','confidence'=>'source_unresolved'],
]);
$record('ut3_missing_to_unresolved_is_expected_with_source_evidence',is_array($rule)&&($rule['id']??'')==='ut3_source_unresolved');
$badRule=Uedb5GameParityExpectedDifferences::classify('ut3','dependency_outcome',['outcome'=>'missing'],[
    'outcome'=>'unresolved','reason_code'=>'','source_policy'=>'','resolver_detail'=>[],
]);
$record('ut3_difference_requires_source_evidence',$badRule===null);

$caseRule=Uedb5GameParityExpectedDifferences::classify('ut2003','search_case_normalization',[
    'scope'=>'names','query'=>'Skin','authoritative_name'=>'skin',
],['scope'=>'names','normalized_authoritative_match'=>true]);
$record('normalized_fname_case_only_search_addition_is_expected',
    is_array($caseRule)&&($caseRule['id']??'')==='normalized_fname_search_case');
$exactCaseRule=Uedb5GameParityExpectedDifferences::classify('ut2003','search_case_normalization',[
    'scope'=>'names','query'=>'Skin','authoritative_name'=>'Skin',
],['scope'=>'names','normalized_authoritative_match'=>true]);
$record('exact_case_search_difference_is_not_allowed',$exactCaseRule===null);
$exportCaseRule=Uedb5GameParityExpectedDifferences::classify('ut3','search_case_normalization',[
    'scope'=>'exports','query'=>'Cube1','authoritative_name'=>'cube1',
],['scope'=>'exports','normalized_authoritative_match'=>true]);
$record('normalized_export_case_only_search_addition_is_expected',
    is_array($exportCaseRule)&&($exportCaseRule['id']??'')==='normalized_export_search_case');
$record('case_rule_requires_authoritative_v4_scan',str_contains($service,'v4CaseOnlyMetadataEvidence')&&str_contains($service,"\$countColumn='export_count'")&&str_contains($service,'->page($fileId,$section'));
$record('search_reports_expected_case_differences',str_contains($service,'expected_difference_query_count')&&str_contains($service,'expected_missing_in_v4'));
$record('package_identity_audit_detects_missing_v5_key',str_contains($service,'e.required_package_key_kind IS NULL')&&str_contains($service,'e.required_package_key IS NULL'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
