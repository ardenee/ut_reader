<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$relative='Engine/Source/Runtime/CoreUObject/';
$p=$sourceRoot.'/'.$relative.'Private/Serialization/AsyncLoading2.cpp';
$h=$sourceRoot.'/'.$relative.'Public/Serialization/AsyncLoading2.h';
$source=is_file($p)?(string)file_get_contents($p):'';
$header=is_file($h)?(string)file_get_contents($h):'';
$checks=[];$failures=[];
$check=static function(string $key,bool $ok)use(&$checks,&$failures):void{
 $checks[$key]=$ok;if(!$ok)$failures[]=$key;
};
$check('b003_epic_async_source_pinned',hash('sha256',$source)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$check('b003_epic_package_index_header_pinned',hash('sha256',$header)==='088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2');
$section=static function(string $a,string $b)use($source):string{
 $start=strpos($source,$a);if($start===false)return '';
 $end=strpos($source,$b,$start+strlen($a));return $end===false?'':substr($source,$start,$end-$start);
};
$notify=$section('void FAsyncLoadingThread2::NotifyRegistrationEvent(','void FAsyncLoadingThread2::NotifyScriptVersePackage(');
$register=$section('void AddScriptObject(FStringView PackageName, FStringView Name, UObject* Object)','void RemoveUnreachableScriptObjects()');
$remove=$section('void RemoveUnreachableScriptObjects()','void Shrink()');
$search=$section('void FGlobalImportStore::FindAllScriptObjects(bool bVerifyOnly)','FCookedScriptObjectsDebug::FCookedScriptObjectsDebug()');
$finish=$section('void FGlobalImportStore::RegistrationComplete()','void FAsyncPackage2::ImportPackagesRecursiveInner');
$runtimePackages=$section('void FindAllRuntimeScriptPackages(FRuntimeScriptPackages& OutPackages)','#ifndef ALT2_VERIFY_LINKERLOAD_MATCHES_IMPORTSTORE');
$scriptFind=$section('inline UObject* FindScriptImportObject(FPackageObjectIndex GlobalIndex) const','Verse::VCell* FindPublicExportCell(');
$check('b003_finished_registration_only_no_nonfinished_mutation',
 str_contains($notify,'NotifyRegistrationPhase == ENotifyRegistrationPhase::NRP_Finished')
 &&str_contains($notify,'ensureMsgf(FinishedObject')
 &&str_contains($notify,'GlobalImportStore.AddScriptObject(PackageNameBuilder.ToView(), NameBuilder.ToView(), FinishedObject);'));
$check('b003_live_path_join_and_script_hash_key',
 str_contains($register,'FPathViews::Append(FullName, PackageName);')
 &&str_contains($register,'FPathViews::Append(FullName, Name);')
 &&str_contains($register,'FPackageObjectIndex::FromScriptPath(FullName)')
 &&str_contains($header,'GenerateImportHashFromObjectPath(ScriptObjectPath)'));
$check('b003_editor_only_package_global_identity',
 str_contains($register,'#if WITH_EDITOR')
 &&str_contains($register,'FPackageObjectIndex::FromScriptPath(PackageName)')
 &&str_contains($register,'ScriptObjects.Add(PackageGlobalImportIndex, Object->GetOutermost());'));
$check('b003_registration_always_adds_finished_object',
 str_contains($register,'ScriptObjects.Add(GlobalImportIndex, Object);'));
$check('b003_nested_public_only_and_full_object_path',
 str_contains($register,'ForEachObjectWithOuter(Object,')
 &&str_contains($register,'EGetObjectsFlags::IncludeNestedObjects')
 &&str_contains($register,'SubObject->HasAnyFlags(RF_Public)')
 &&str_contains($register,'SubObject->GetPathName(nullptr, SubObjectName)')
 &&str_contains($register,'FPackageObjectIndex::FromScriptPath(SubObjectName)')
 &&str_contains($register,'ScriptObjects.Add(SubObjectGlobalImportIndex, SubObject);'));
$check('b003_unregister_unreachable_lifecycle',
 str_contains($remove,'ScriptObjects.ParallelRemoveIf(')
 &&str_contains($remove,'ScriptObject->IsUnreachable()')
 &&str_contains($source,'GlobalImportStore.RemoveUnreachableScriptObjects();'));
$check('b003_runtime_find_is_live_script_hash_map',
 str_contains($scriptFind,'ScriptObjects.FindRef(GlobalIndex)')
 &&str_contains($scriptFind,'GlobalIndex.IsScriptImport()'));
$check('b003_compiled_in_package_discovery_and_verse_vni_filter',
 str_contains($runtimePackages,'Package->HasAnyPackageFlags(PKG_CompiledIn)')
 &&str_contains($runtimePackages,'/Script/')
 &&str_contains($runtimePackages,'/_Verse/VNI/')
 &&str_contains($search,'FindAllRuntimeScriptPackages(ScriptPackages)')
 &&str_contains($search,'GetObjectsWithOuter(Package, Objects, EGetObjectsFlags::IncludeNestedObjects)')
 &&str_contains($search,'Object->HasAnyFlags(RF_Public)')
 &&str_contains($search,'Verse::VerseVM::IsUHTGeneratedVerseVNIObject(Object)'));
$check('b003_registration_complete_editor_add_check_only_verify',
 str_contains($finish,'#if WITH_EDITOR')
 &&str_contains($finish,'FindAllScriptObjects(/*bVerifyOnly*/false)')
 &&str_contains($finish,'#elif DO_CHECK')
 &&str_contains($finish,'FindAllScriptObjects(/*bVerifyOnly*/true)'));
$check('b003_separate_verse_vm_cell_registration',
 str_contains($source,'GlobalImportStore.AddScriptCellPackage(Package);')
 &&str_contains($source,'ScriptCells.Add(GlobalImportIndex, Cell);'));
$check('b003_hash_mask_and_script_path_encoding_distinct_from_verse',
 str_contains($source,'FullImportPath[I] = TEXT(\'/\');')
 &&str_contains($source,'TChar<TCHAR>::ToLower(ObjectPath[I])')
 &&str_contains($source,'Hash &= ~(3ull << 62ull)')
 &&str_contains($source,'GenerateImportHashFromVersePath(FUtf8StringView VersePath)'));
$script=['type'=>'ScriptImport','dependency_class'=>'script','script_import_hash'=>'167E29465232A0A9'];
$snapshot=[
 'package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,
 'sections'=>[
  'package_summary'=>[['package_id'=>'AA00000000000000']],
  'imports'=>[$script],'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
  'soft_package_references'=>[],'dependency_bundle_entries'=>[]
 ]
];
$export=[
 'package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,
 'sections'=>['package_summary'=>[['package_id'=>'FEDCBA9876543210']],
  'imports'=>[],'exports'=>[['public_export_hash'=>'167E29465232A0A9','filter_flags'=>0]],
  'cell_imports'=>[],'cell_exports'=>[],
  'soft_package_references'=>[],'dependency_bundle_entries'=>[]]
];
$ctx=['with_editor'=>true,'package_store_context_authoritative'=>true,
 'package_redirects'=>[],'localized_packages'=>[],
 'package_identity_context_authoritative'=>true,'package_identity_rewrites'=>[]];
$rows=Resolver::resolve($snapshot,[['provider_id'=>987,'snapshot'=>$export]],$ctx);
$scriptRow=$rows[0]??[];
$check('b003_staged_export_hash_does_not_fake_live_script_object',
 ($scriptRow['dependency_kind']??null)==='ScriptImport'
 &&($scriptRow['outcome']??null)==='unresolved'
 &&($scriptRow['reason_code']??null)==='script_object_runtime_context_unavailable'
 &&($scriptRow['selected_provider_file_id']??null)===null
 &&($scriptRow['selected_provider_object']??null)===null);
$check('b003_preserves_cooked_script_index_without_runtime_assertion',
 ($scriptRow['required_object_identity']??null)==='167E29465232A0A9'
 &&($scriptRow['required_package_id']??null)===null);
echo json_encode(['ok'=>$failures===[],'operation'=>'UE5-583-SCRIPT-004',
 'checks'=>$checks,'failures'=>$failures,'source_defined_registry_branches_audited'=>true,
 'actual_epic_uobject_registry_executed'=>false,
 'disposition'=>'partial'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
