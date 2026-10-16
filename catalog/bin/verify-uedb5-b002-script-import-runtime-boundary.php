<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder as Projection;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
$root=dirname(__DIR__,2);$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$cpp=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$source=is_file($cpp)?(string)file_get_contents($cpp):'';
$checks=[];$fail=[];
$check=static function(string $id,bool $v)use(&$checks,&$fail):void{$checks[$id]=$v;if(!$v)$fail[]=$id;};
$check('b002_script3_epic_async_source_pinned',hash('sha256',$source)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$section=static function(string $a,string $b)use($source):string{
 $start=strpos($source,$a);if($start===false)return '';
 $end=strpos($source,$b,$start+strlen($a));return $end===false?'':substr($source,$start,$end-$start);
};
$store=$section('inline UObject* FindScriptImportObject(FPackageObjectIndex GlobalIndex) const','Verse::VCell* FindPublicExportCell(');
$import=$section('inline UObject* GetImportObject(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)','FString GetImportName(');
$name=$section('FString GetImportName(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)','void GetUnresolvedCDOs(');
$check('b002_script3_live_find_ref_only',
 str_contains($store,'check(GlobalIndex.IsScriptImport());')
 &&str_contains($store,'ScriptObjects.FindRef(GlobalIndex)')
 &&str_contains($store,'return Object;'));
$check('b002_script3_dispatches_script_without_public_export_fallback',
 str_contains($import,'if (GlobalIndex.IsScriptImport())')
 &&str_contains($import,'GlobalImportStore.FindScriptImportObject(GlobalIndex)')
 &&str_contains($import,'else if (GlobalIndex.IsPackageImport())')
 &&str_contains($import,'GlobalImportStore.FindPublicExportObject('));
$check('b002_script3_debug_name_does_not_satisfy_object_resolution',
 str_contains($name,'if (Import)')
 &&str_contains($name,'#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING')
 &&str_contains($name,'CookedScriptObjectsDebug.GetPathName(GlobalIndex)'));
$check('b002_script3_live_registration_independent_of_cooked_diagnostics',
 str_contains($source,'GlobalImportStore.AddScriptObject(PackageNameBuilder.ToView(), NameBuilder.ToView(), FinishedObject);')
 &&str_contains($source,'#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING'));
$mk=static fn(array $ordinary,array $optional=[]):array=>[
 'package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,
 'file'=>['id'=>210033,'game_id'=>7,'package_name'=>'/Game/Scripts'],
 'sections'=>[
  'package_summary'=>[['package_id'=>'ABABABABABABABAB']],
  'imports'=>$ordinary,'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
  'soft_package_references'=>[],'dependency_bundle_entries'=>[],
  'optional_segment'=>$optional===[]?[]:[[
   'imports'=>$optional,'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
   'soft_package_references'=>[],'dependency_bundle_entries'=>[]]]
 ]];
$importEntry=['type'=>'ScriptImport','dependency_class'=>'script','script_import_hash'=>'1D39669A89BAECB6'];
$consumer=$mk([$importEntry],[$importEntry]);
$ctx=[
 'package_identity_context_authoritative'=>true,'package_identity_rewrites'=>[],
 'package_store_context_authoritative'=>true,'package_redirects'=>[],
 'localized_packages'=>[],'with_editor'=>true,'is_editor'=>true
];
$rows=Resolver::resolve($consumer,[],$ctx);
$find=static function(array $data,string $section):array{
 foreach($data as $r)if(($r['source_section']??null)===$section)return $r;
 return [];
};
$main=$find($rows,'imports');$optional=$find($rows,'optional_segment_imports');
$check('b002_script3_main_result_is_unresolved_without_false_provider',
 ($main['dependency_kind']??null)==='ScriptImport'
 &&($main['dependency_class']??null)==='script'
 &&($main['outcome']??null)==='unresolved'
 &&($main['reason_code']??null)==='script_object_runtime_context_unavailable'
 &&($main['selected_provider_file_id']??null)===null
 &&($main['selected_provider_object']??null)===null);
$check('b002_script3_optional_editor_without_registry_is_unresolved',
 ($optional['outcome']??null)==='unresolved'
 &&($optional['reason_code']??null)==='script_object_runtime_context_unavailable');
$runtimeOff=$ctx;$runtimeOff['with_editor']=false;
$off=Resolver::resolve($consumer,[],$runtimeOff);
$check('b002_script3_optional_segment_non_editor_remains_unresolved',
 ($find($off,'optional_segment_imports')['reason_code']??null)==='optional_segment_not_loaded_non_editor');
$unknown=Resolver::resolve($consumer,[]);
$check('b002_script3_unknown_editor_does_not_invent_loaded_object',
 ($find($unknown,'imports')['reason_code']??null)==='script_object_runtime_context_unavailable'
 &&($find($unknown,'optional_segment_imports')['reason_code']??null)==='optional_segment_editor_build_context_required');
$consumer['sections']['dependency_results']=$rows;
$projected=Projection::build($consumer);
$importEdge=$projected['dependency_edges'][0]??[];
$check('b002_script3_sql_unresolved_does_not_claim_package_or_object',
 ($importEdge['required_package_key']??null)===null
 &&($importEdge['required_object_key']??null)===null
 &&($importEdge['resolved_file_id']??null)===null
 &&count($projected['dependency_packages'])===0);
$check('b002_script3_script_hash_not_conflated_with_zen_public_export_hash',
 ($main['required_object_identity']??null)==='1D39669A89BAECB6'
 &&($main['required_package_id']??null)===null);
echo json_encode(['ok'=>$fail===[],'original_operation'=>'UE5-583-SCRIPT-003',
 'checks'=>$checks,'failures'=>$fail,'disposition'=>'partial',
 'runtime_global_import_store_not_executed'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
