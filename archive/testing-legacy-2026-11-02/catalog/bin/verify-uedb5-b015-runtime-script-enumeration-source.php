<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptRuntimeEnumerationAudit as Audit;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptImportPathHasher as Hash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
$checks=[];$failures=[];
$check=static function(string $id,bool $ok)use(&$checks,&$failures):void{$checks[$id]=$ok;if(!$ok)$failures[]=$id;};
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$p=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$s=is_file($p)?(string)file_get_contents($p):'';
$check('b0151_pinned_epic_source_sha256',hash('sha256',$s)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$section=static function(string $start,string $end)use($s):string{
 $a=strpos($s,$start);if($a===false)return '';
 $b=strpos($s,$end,$a+strlen($start));return $b===false?'':substr($s,$a,$b-$a);
};
$packages=$section('void FindAllRuntimeScriptPackages(','#ifndef ALT2_VERIFY_LINKERLOAD_MATCHES_IMPORTSTORE');
$enumerate=$section('void FGlobalImportStore::FindAllScriptObjects(bool bVerifyOnly)','FCookedScriptObjectsDebug::FCookedScriptObjectsDebug()');
$finish=$section('void FGlobalImportStore::RegistrationComplete()','void FAsyncPackage2::ImportPackagesRecursiveInner(');
$register=$section('void AddScriptObject(FStringView PackageName, FStringView Name, UObject* Object)','void RemoveUnreachableScriptObjects()');
$cell=$section('void AddScriptCellPackage(Verse::VPackage* Package)','inline UObject* GetImportObject(');
$check('b0151_epic_compiledin_upackage_only',
 str_contains($packages,'ForEachObjectOfClass(UPackage::StaticClass()')
 &&str_contains($packages,'Package->HasAnyPackageFlags(PKG_CompiledIn)')
 &&str_contains($packages,'/*bIncludeDerivedClasses*/false'));
$check('b0151_epic_case_sensitive_script_first_verse_vni_second',
 str_contains($packages,'NameView.StartsWith(TEXT("/Script/"), ESearchCase::CaseSensitive)')
 &&str_contains($packages,'else if (NameView.Contains(TEXT("/_Verse/VNI/"), ESearchCase::CaseSensitive))'));
$check('b0151_epic_disjoint_script_and_verse_package_lists',
 str_contains($packages,'OutPackages.Script.Empty(256);')
 &&str_contains($packages,'OutPackages.VerseVNI.Empty(256);')
 &&str_contains($enumerate,'FindAllScriptObjectsInPackages(ScriptPackages.Script, false)')
 &&str_contains($enumerate,'FindAllScriptObjectsInPackages(ScriptPackages.VerseVNI, true)'));
$check('b0151_epic_editor_package_registration_or_missing_warning',
 str_contains($enumerate,'#if WITH_EDITOR')
 &&str_contains($enumerate,'Package->GetPathName(nullptr, Name)')
 &&str_contains($enumerate,'ScriptObjects.Contains(PackageGlobalImportIndex)')
 &&str_contains($enumerate,'ScriptObjects.Add(PackageGlobalImportIndex, Package);'));
$check('b0151_epic_recursive_public_only',
 str_contains($enumerate,'GetObjectsWithOuter(Package, Objects, EGetObjectsFlags::IncludeNestedObjects)')
 &&str_contains($enumerate,'Object->HasAnyFlags(RF_Public)')
 &&str_contains($enumerate,'Object->GetPathName(nullptr, Name)'));
$check('b0151_epic_verse_vni_uht_only',
 str_contains($enumerate,'bIsVerseVNIPackage && !Verse::VerseVM::IsUHTGeneratedVerseVNIObject(Object)')
 &&str_contains($enumerate,'continue;'));
$check('b0151_epic_verify_does_not_repair_missing_initial_registration',
 str_contains($enumerate,'if (bVerifyOnly)')
 &&str_contains($enumerate,'if (!ScriptObjects.Contains(GlobalImportIndex))')
 &&str_contains($enumerate,'is missing a NotifyRegistrationEvent from the initial load phase.')
 &&str_contains($enumerate,'ScriptObjects.Add(GlobalImportIndex, Object)'));
$check('b0151_epic_editor_add_noneditor_check_shipping_no_enumeration',
 str_contains($finish,'#if WITH_EDITOR')
 &&str_contains($finish,'FindAllScriptObjects(/*bVerifyOnly*/false)')
 &&str_contains($finish,'#elif DO_CHECK')
 &&str_contains($finish,'FindAllScriptObjects(/*bVerifyOnly*/true)'));
$check('b0151_epic_finished_event_registration_distinct_from_enumeration',
 str_contains($register,'ScriptObjects.Add(GlobalImportIndex, Object)')
 &&str_contains($s,'if (NotifyRegistrationPhase == ENotifyRegistrationPhase::NRP_Finished)')
 &&str_contains($s,'GlobalImportStore.AddScriptObject(PackageNameBuilder.ToView(), NameBuilder.ToView(), FinishedObject);'));
$check('b0151_epic_verse_vm_cells_use_separate_registry',
 str_contains($s,'ScriptCells.Add(GlobalImportIndex, Cell)')
 &&str_contains($s,'ScriptCells.FindRef(GlobalIndex)')
 &&str_contains($s,'GlobalImportStore.AddScriptCellPackage(Package);')
 &&str_contains($s,'StoreGlobalCell(PackageId, ExportHash, Cell)'));
$check('b0151_epic_compiled_verse_exports_separate_from_script_map',
 str_contains($s,'void FAsyncLoadingThread2::NotifyCompiledVersePackage(')
 &&str_contains($s,'GlobalImportStore.StoreGlobalObject(PackageId, ExportMap[Index].PublicExportHash, Exports[Index]);'));
$scriptPath='/Script/FactoryGame.FGRecipeUnlockedDependency:GetRecipes';
$realScriptIndex='5'.substr(Hash::fromObjectPath($scriptPath),1);
$sample=[
 ['path'=>'/Script/FactoryGame','compiled_in'=>true,'objects'=>[
  ['path'=>$scriptPath,'public'=>true],
  ['path'=>'/Script/FactoryGame.Private','public'=>false],
  ['path'=>'/Script/FactoryGame.Deep:Nest','public'=>true],
 ]],
 ['path'=>'/Project/_Verse/VNI/Package','compiled_in'=>true,'objects'=>[
  ['path'=>'/Project/_Verse/VNI/Package.UHTObject','public'=>true,'uht_generated_verse_vni'=>true],
  ['path'=>'/Project/_Verse/VNI/Package.CompilerObject','public'=>true,'uht_generated_verse_vni'=>false],
  ['path'=>'/Project/_Verse/VNI/Package.Private','public'=>false,'uht_generated_verse_vni'=>true],
 ]],
 ['path'=>'/Script/Uncompiled','compiled_in'=>false,'objects'=>[['path'=>'/Script/Uncompiled.Class','public'=>true]]],
 ['path'=>'/script/WrongCase','compiled_in'=>true,'objects'=>[['path'=>'/script/WrongCase.Class','public'=>true]]],
 ['path'=>'/Game/Regular','compiled_in'=>true,'objects'=>[['path'=>'/Game/Regular.Class','public'=>true]]],
];
$editor=Audit::inspect($sample,[$realScriptIndex],true,true);
$verify=Audit::inspect($sample,[$realScriptIndex],false,true);
$shipping=Audit::inspect($sample,[$realScriptIndex],false,false);
$check('b0151_script_classification_case_compiledin_and_vni',
 $editor['script_packages']===['/Script/FactoryGame']
 &&$editor['verse_vni_packages']===['/Project/_Verse/VNI/Package']
 &&count($editor['excluded_packages'])===3);
$check('b0151_editor_has_two_package_keys_three_public_objects',
 $editor['mode']==='editor_add'&&count($editor['expected_candidates'])===5
 &&count(array_filter($editor['expected_candidates'],
 static fn(array $c):bool=>$c['kind']==='editor_package'))===2);
$check('b0151_nested_private_and_non_uht_vni_filtered',
 count($editor['excluded_objects'])===3
 &&in_array('verse_vni_not_uht_generated',array_column($editor['excluded_objects'],'reason'),true));
$check('b0151_native_typed_script_index_from_authentic_path',
 $realScriptIndex==='567E29465232A0A9'
 &&in_array($realScriptIndex,array_column($editor['expected_candidates'],'script_import_index'),true));
$check('b0151_editor_never_claims_mutated_unreal_registry',
 $editor['would_add_only_in_editor']===true
 &&$editor['mutates_runtime_registry']===false
 &&$editor['runtime_registry_authoritative']===false
 &&$editor['missing_initial_registration_indices']===[]);
$check('b0151_noneditor_check_only_missing_registration_indices',
 $verify['mode']==='noneditor_verify_only'
 &&$verify['would_verify_only']===true
 &&count($verify['expected_candidates'])===3
 &&count($verify['missing_initial_registration_indices'])===2
 &&!in_array($realScriptIndex,$verify['missing_initial_registration_indices'],true));
$check('b0151_shipping_no_source_enumeration',
 $shipping['mode']==='noneditor_no_enumeration'
 &&$shipping['expected_candidates']===[]
 &&$shipping['missing_initial_registration_indices']===[]);
$check('b0151_verse_cells_never_equated_to_script_uobjects',
 !$editor['vm_cells_enumerated']
 &&count(array_filter($editor['expected_candidates'],
 static fn(array $row):bool=>str_contains($row['kind'],'cell')))===0);
$invalid=[];
foreach([
 'untrusted_index'=>fn()=>Audit::inspect($sample,['FFFFFFFFFFFFFFFF'],false,true),
 'missing_verse_vni_uht'=>function()use($sample){$a=$sample;unset($a[1]['objects'][0]['uht_generated_verse_vni']);return Audit::inspect($a,[],true,false);},
 'duplicate_package'=>fn()=>Audit::inspect([$sample[0],$sample[0]],[],true,true),
 'object_outside_observed_package'=>function()use($sample){$a=$sample;$a[0]['objects'][0]['path']='/Script/Other.Class';return Audit::inspect($a,[],true,true);},
 'invalid_path_utf8'=>function()use($sample){$a=$sample;$a[0]['objects'][0]['path']="\xFF";return Audit::inspect($a,[],false,true);},
] as $name=>$fn){
 try{$fn();$invalid[$name]=false;}catch(Throwable){$invalid[$name]=true;}
}
$check('b0151_invalid_external_runtime_observations_fail_closed',
 !in_array(false,$invalid,true));
$collisionInput=$sample;
$collisionInput[0]['objects'][]=[
 'path'=>'/Script/FactoryGame.FGRecipeUnlockedDependency:getrecipes',
 'public'=>true
];
$collision=Audit::inspect($collisionInput,[],false,true);
$check('b0151_collision_is_reported_without_inventing_live_winner',
 count($collision['collisions'])===1
 &&$collision['collisions'][0]['script_import_index']===$realScriptIndex
 &&$collision['runtime_registry_authoritative']===false);
$context=['with_editor'=>true,'is_editor'=>true,
 'package_store_context_authoritative'=>true,'package_redirects'=>[],'localized_packages'=>[]];
$snapshot=['package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,
 'sections'=>['package_summary'=>[['package_id'=>'AABBCCDDEEFF0011']],
 'imports'=>[['type'=>'ScriptImport','dependency_class'=>'script','script_import_hash'=>'167E29465232A0A9']],
 'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
 'soft_package_references'=>[],'dependency_bundle_entries'=>[]]];
$resolved=Resolver::resolve($snapshot,[],$context);
$check('b0151_offline_dependency_does_not_claim_observed_live_object',
 ($resolved[0]['outcome']??null)==='unresolved'
 &&($resolved[0]['reason_code']??null)==='script_object_runtime_context_unavailable'
 &&($resolved[0]['selected_provider_file_id']??null)===null);
echo json_encode(['ok'=>$failures===[],'operation'=>'UE5-583-B015-1',
 'checks'=>$checks,'failures'=>$failures,
 'epic_live_uobject_registry_executed'=>false,
 'external_runtime_observations_supplied'=>false,
 'disposition'=>'partial',
 'example'=>['editor_candidates'=>count($editor['expected_candidates']),
  'noneditor_missing'=>count($verify['missing_initial_registration_indices']),
  'live_authentic_index_hash_only'=>$realScriptIndex]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
