<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
$root=dirname(__DIR__);
$engine=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$checks=[];$reports=[];$fail=[];
$check=static function(string $key,bool $pass)use(&$checks,&$fail):void{$checks[$key]=$pass;if(!$pass)$fail[]=$key;};
$target=$engine.'/Engine/Source/Programs/UnrealBuildTool/Configuration/Rules/TargetRules.cs';
$rules=$engine.'/Engine/Source/Programs/UnrealBuildTool/Configuration/Rules/RulesAssembly.cs';
$async=$engine.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$t=is_file($target)?file_get_contents($target):'';
$r=is_file($rules)?file_get_contents($rules):'';
$a=is_file($async)?file_get_contents($async):'';
$check('b0151_default_public_target_uses_bpvm',str_contains($t,'bUseVerseBPVM { get; set; } = true;'));
$check('b0151_default_public_target_disables_versevm',str_contains($r,'Rules.bUseVerseBPVM ? 0 : 1'));
$check('b0151_versevm_scriptcell_branch_compile_time_optional',str_contains($a,'#if WITH_VERSE_VM || defined(__INTELLISENSE__)')&&str_contains($a,'void AddScriptCellPackage(Verse::VPackage* Package)'));
$definitions=dirname(__DIR__,2).'/catalog/tests/fixtures/ue5-b015-editor-compiled-definitions.h';
$defs=is_file($definitions)?file_get_contents($definitions):'';
$check('b0151_actual_editor_defines_default_versevm_off',preg_match('/#define\\s+WITH_VERSE_VM\\s+0\\b/',$defs)===1);
foreach([
 'existing'=>'verify-uedb5-b015-runtime-script-enumeration-acceptance.php',
 'source'=>'verify-uedb5-b015-runtime-script-enumeration-source.php',
 'editor'=>'verify-ue5-script-runtime-trace-inspection.php',
 'vni_predicate'=>'verify-ue5-583-b015-vni-live-predicate.php',
 'noneditor'=>'verify-ue5-583-b015-noneditor-check-missing.php',
 'shipping'=>'verify-ue5-583-b015-shipping-nocheck-runtime.php',
 'scriptcells_source'=>'verify-ue5-583-b015-scriptcells-source-body.php'
] as $key=>$name){
 $out=[];$exit=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/'.$name).' 2>&1',$out,$exit);
 $json=json_decode(implode("\n",$out),true);
 $reports[$key]=is_array($json)?$json:[];
 $check('b0151_default_driver_'.$key.'_passes',$exit===0&&($reports[$key]['ok']??null)===true);
}
$check('b0151_default_editor_original_runtime_observed',($reports['vni_predicate']['genuine_engine_predicate_and_enumeration_executed']??false)===true);
$check('b0151_optional_generated_vni_not_falsely_certified',($reports['vni_predicate']['source_uht_codegen_proven']??true)===false);
$check('b0151_optional_versevm_runtime_not_falsely_certified',($reports['vni_predicate']['with_verse_vm']??true)===false&&($reports['vni_predicate']['scriptcells_executed']??true)===false);
$check('b0151_existing_source_contract_verified',($reports['existing']['checks']['b0151_package_enumeration_and_verse_uht_branches_pinned']??false)===true);
$check('b0151_existing_native_branch_execution_verified',($reports['existing']['checks']['b0151_actual_epic_cpp_functions_executed_for_editor_check_shipping']??false)===true);
echo json_encode(['ok'=>$fail===[],'operation'=>'UE5-583-B015-1','acceptance_scope'=>'default_public_ue5_8_3_bpvm','checks'=>$checks,'failures'=>$fail,'optional_versevm_vni_runtime_status'=>'not_verified_not_required_for_default_build'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
