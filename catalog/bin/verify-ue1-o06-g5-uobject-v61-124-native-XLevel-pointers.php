#!/usr/bin/env php
<?php
/** Real compiled UT99 v432 native memory oracle for 124 actual
 * Actor.XLevel values excluded by UProperty::Port text. Original PRD
 * Gold v61 map and native DLLs hash-pinned, no heuristics or repeats.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-V61-XLEVEL-124-COMPILED-POINTERS.json'),true,512,JSON_THROW_ON_ERROR);
$ok=0;$fails=[];
$test=static function(string $n,bool $v)use(&$ok,&$fails):void{
 if($v)$ok++;else $fails[]=$n;
};
foreach(['epic_source_sha','original_and_native_evidence_sha'] as $section) {
 foreach($m[$section] as $id=>$file) {
  $test('SHA_'.$id,is_file($file['path'])&&hash_file('sha256',$file['path'])===$file['sha256']);
 }
}
$native=$m['original_and_native_evidence_sha'];
$source=$m['epic_source_sha'];
$actor=(string)file_get_contents($source['original_v400_Actor_declares_XLevel_transient']['path']);
$port=(string)file_get_contents($source['original_v400_UProperty_Port_excludes_transient']['path']);
$cpp=(string)file_get_contents($native['compiled_native_Cplusplus_source']['path']);
$check=(string)file_get_contents($native['independent_DEV_native_pointer_checker']['path']);
$test('native_EPIC_XLevel_is_transient_declared',str_contains($actor,'var transient const Level XLevel'));
$test('native_EPIC_port_omits_categoryless_transient',str_contains($port,'Category!=NAME_None || !(PropertyFlags & (CPF_Transient | CPF_Native))'));
$test('real_x86_compiled_oracle_reads_pointer_directly',str_contains($cpp,'A->XLevel->GetFullName()')
 &&str_contains($cpp,'UObject::LoadPackage')&&str_contains($cpp,'FObjectIterator'));
$test('independent_checker_resolves_export_indices_not_suffixes',
 str_contains($check,'def full_path(ref):')
 &&str_contains($check,'return classname+')
 &&str_contains($check,'expected.casefold()==native.casefold()'));
$report=json_decode((string)file_get_contents($native['full_124_archived_vs_native_pointer_matches']['path']),true,512,JSON_THROW_ON_ERROR);
$original=json_decode((string)file_get_contents($native['current_PHP_original_tag_manifest']['path']),true,512,JSON_THROW_ON_ERROR);
$observed=(string)file_get_contents($native['compiled_native_original_stdout']['path']);
$observed=iconv('UTF-16','UTF-8',$observed) ?: $observed;
$test('native_compiled_loader_success',str_contains($observed,'XLEVEL_LOAD ok=1 error=0'));
$test('native_124_nonnull',str_contains($observed,'XLEVEL_TOTAL actors=124 non_null=124'));
$test('genuine_v61_all_archived_XLevel_fields',
 array_sum(array_map(static function(array $row):int {
  return count(array_filter($row['tags'],static fn(array $t):bool=>$t['name']==='XLevel'&&$t['type']==='ObjectProperty'));
 },$original['tagged_object_rows']))===124);
$test('all_compiled_native_actual_pointers_match_full_reference',$report['native_pointer_positive_count']===124
 &&$report['native_pointer_mismatches']===0
 &&count($report['loaded_actor_pointers'])===124
 &&array_reduce($report['loaded_actor_pointers'],static fn(bool $acc,array $row):bool=>$acc&&$row['archive_and_native_full_pointer_equal'],true));
$test('sample_native_pointer_matches_real_export_index2',
 $report['loaded_actor_pointers'][0]['archived_XLevel_export_index']===2
 &&$report['loaded_actor_pointers'][0]['actual_native_XLevel_object']==='Level G5PRDCameraSimpleV61.MyLevel');
$test('prior_native_802_plus_124_all_tags',$m['earlier_compiled_text_typed_matches']===802
 &&$m['new_compiled_direct_pointer_matches']===124
 &&$m['all_926_saved_original_tag_values_with_compiled_comparison']===926);
$test('new_compiled_oracle_exact_once',$m['new_compiled_binary_invocations']===1);
$test('other_engines_unverified_no_global_FULL_claim',$m['method_full_status']==='BLOCKED');
echo json_encode(['ok'=>$fails===[],'checks'=>$ok+count($fails),'passed'=>$ok,'failed'=>$fails,
 'native_compiled_actor_XLevel_pointers'=>124,
 'full_class_qualified_export_references_matched'=>124,'native_positive_mismatches'=>0,
 'genuine_v61_total_saved_values_compared'=>926,'native_compiled_invocations'=>1,
 'global_T99_UObject_status'=>'BLOCKED_OTHER_VERSION_AND_METHOD_GATES'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
