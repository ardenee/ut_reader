<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptImportDiagnosticPreview as Preview;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader as Cooked;

$root = rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$path = $root.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$source = is_file($path)?(string)file_get_contents($path):'';
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$check('script5_epic_async_source_pinned',
 hash('sha256',$source)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$section=static function(string $a,string $b)use($source):string{
 $start=strpos($source,$a);
 if($start===false)return '';
 $end=strpos($source,$b,$start+strlen($a));
 return $end===false?'':substr($source,$start,$end-$start);
};
$lookup=$section('inline UObject* GetImportObject(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)',
                 'void GetUnresolvedCDOs(');
$find=$section('inline UObject* FindScriptImportObject(FPackageObjectIndex GlobalIndex) const',
               'Verse::VCell* FindPublicExportCell(');
$trim=$section('void FCookedScriptObjectsDebug::TrimDebugData(',
               'void FCookedScriptObjectsDebug::GetPathNameInternal(');
$pathName=$section('void FCookedScriptObjectsDebug::GetPathNameInternal(',
                    '#endif // ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING');
$startup=$section('void FCookedScriptObjectsDebug::OnCompiledInUObjectsRegistered(',
                   'void FCookedScriptObjectsDebug::LoadDebugData()');
$complete=$section('void FGlobalImportStore::RegistrationComplete()',
                    'void FAsyncPackage2::ImportPackagesRecursiveInner(');
$check('script5_live_script_lookup_is_only_findref',
 str_contains($find,'GlobalIndex.IsScriptImport()')
 &&str_contains($find,'ScriptObjects.FindRef(GlobalIndex)')
 &&!str_contains($find,'CookedScriptObjectsDebug'));
$check('script5_get_import_object_separates_script_and_package_paths',
 str_contains($lookup,'if (GlobalIndex.IsScriptImport())')
 &&str_contains($lookup,'GlobalImportStore.FindScriptImportObject(GlobalIndex)')
 &&str_contains($lookup,'else if (GlobalIndex.IsPackageImport())')
 &&str_contains($lookup,'GlobalImportStore.FindPublicExportObject('));
$check('script5_importname_prefers_live_object_name_then_optional_fallback',
 str_contains($lookup,'if (Import)')
 &&str_contains($lookup,'return Import->GetFName().ToString();')
 &&str_contains($lookup,'#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING')
 &&str_contains($lookup,'else if (GlobalIndex.IsScriptImport())')
 &&str_contains($lookup,'CookedScriptObjectsDebug.GetPathName(GlobalIndex)')
 &&str_contains($lookup,'return FString();'));
$check('script5_optional_precdo_flag_controls_chunk_loading',
 str_contains($startup,'Status == ECompiledInUObjectsRegisteredStatus::PreCDO')
 &&str_contains($startup,'if (GEnableScriptImportsDebugging)')
 &&str_contains($startup,'LoadDebugData();')
 &&str_contains($source,'FPlatformProperties::RequiresCookedData()'));
$check('script5_trim_only_when_compiled_diagnostics_enabled',
 str_contains($complete,'#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING')
 &&str_contains($complete,'CookedScriptObjectsDebug.TrimDebugData(ScriptObjects);'));
$check('script5_trim_marks_registered_but_preserves_outers',
 str_contains($trim,'RuntimeScriptObjects.ForEach(')
 &&str_contains($trim,'Remove[Id.AsInteger()] = true;')
 &&str_contains($trim,'KeepOuters(I);')
 &&str_contains($trim,'RemainingObjects.Add(')
 &&str_contains($trim,'OuterId.AsInteger()'));
$check('script5_names_use_dot_colon_question_for_descendant_depth',
 str_contains($pathName,'if (NumParts == 1)')
 &&str_contains($pathName,"PathName.AppendChar('.');")
 &&str_contains($pathName,'if (NumParts == 2)')
 &&str_contains($pathName,'PathName.AppendChar(SUBOBJECT_DELIMITER_CHAR);')
 &&str_contains($pathName,'if (NumParts > 2)')
 &&str_contains($pathName,"PathName.AppendChar('?');")
 &&str_contains($pathName,'return FString();'));
$mk=static function(string $id,string $name,string $outer):array{
 return ['name'=>$name,'global_index'=>$id,'outer_index'=>$outer,
  'runtime_resolved'=>false,'source'=>'cooked_script_objects_chunk'];
};
$null='FFFFFFFFFFFFFFFF';
$rootIndex='4000000000000001';$childIndex='4000000000000002';$grandIndex='4000000000000003';
$deepIndex='4000000000000004';$separateIndex='4000000000000005';
$fixture=[
 $rootIndex=>$mk($rootIndex,'/Script/Demo',$null),
 $childIndex=>$mk($childIndex,'Thing',$rootIndex),
 $grandIndex=>$mk($grandIndex,'Nested',$childIndex),
 $deepIndex=>$mk($deepIndex,'Deep',$grandIndex),
 $separateIndex=>$mk($separateIndex,'Detached','40000000000000FF')
];
$check('script5_full_depth_source_delimiters',
 Preview::getCookedDiagnosticPathName($fixture,$deepIndex)==='/Script/Demo.Thing:Nested?Deep');
$check('script5_missing_outer_source_appends_only_present_name',
 Preview::getCookedDiagnosticPathName($fixture,$separateIndex)==='Detached');
$check('script5_unknown_entry_empty_string',
 Preview::getCookedDiagnosticPathName($fixture,'4000000000000006')==='');
$check('script5_one_name_and_two_names',
 Preview::getCookedDiagnosticPathName($fixture,$rootIndex)==='/Script/Demo'
 &&Preview::getCookedDiagnosticPathName($fixture,$childIndex)==='/Script/Demo.Thing');
$withoutRegistry=Preview::retainMissingWithOuters($fixture,[]);
$check('script5_no_runtime_evidence_retains_all_cooked',
 count($withoutRegistry)===5 &&count($fixture)===5);
$trimmed=Preview::retainMissingWithOuters($fixture,[$rootIndex,$grandIndex,$separateIndex]);
$check('script5_registered_outer_retained_for_missing_nested',
 count($trimmed)===4 &&isset($trimmed[$rootIndex],$trimmed[$grandIndex])
 &&!isset($trimmed[$separateIndex])
 &&Preview::getCookedDiagnosticPathName($trimmed,$deepIndex)==='/Script/Demo.Thing:Nested?Deep');
$onlyRoot=Preview::retainMissingWithOuters($fixture,[$rootIndex]);
$check('script5_known_outer_kept_and_other_unregistered_preserved',
 isset($onlyRoot[$rootIndex]) &&isset($onlyRoot[$childIndex],$onlyRoot[$separateIndex]));
$allRuntime=Preview::retainMissingWithOuters($fixture,array_keys($fixture));
$check('script5_all_known_runtime_indices_leave_no_diagnostics',
 $allRuntime===[] &&Preview::getCookedDiagnosticPathName($allRuntime,$rootIndex)==='');
$noBad=false;
try{Preview::getCookedDiagnosticPathName($fixture,'not-an-index');}catch(Throwable){$noBad=true;}
$check('script5_reject_invalid_identity',$noBad);
$cycles=$fixture;
$cycles[$rootIndex]['outer_index']=$deepIndex;
$rejected=false;
try{Preview::getCookedDiagnosticPathName($cycles,$rootIndex);}catch(Throwable){$rejected=true;}
$check('script5_bounded_outer_cycle_never_hangs',$rejected);
$authPath=(string)(getenv('UNREALDB_B001_REAL_GLOBAL_TOC')?:
 '//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc');
try{
 $toc=new Toc($authPath);$decoded=Cooked::fromToc($toc);
 $objects=$decoded['objects'];
 $check('script5_authentic_54968_scripts_pinned',
  $toc->tocSha256()==='0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6'
  &&$decoded['count']===54968&&$decoded['runtime_authoritative']===false);
 $id='567E29465232A0A9';
 $preview=Preview::getCookedDiagnosticPathName($objects,$id);
 $check('script5_authentic_getrecipes_has_nonempty_diagnostic_path',
  $preview==='/Script/FactoryGame.FGRecipeUnlockedDependency:GetRecipes'
  &&Preview::getCookedDiagnosticPathName($objects,'FFFFFFFFFFFFFFFF')==='');
 $trimNone=Preview::retainMissingWithOuters($objects,[]);
 $check('script5_authentic_none_registered_keeps_cooked_map',
  count($trimNone)===54968
  &&Preview::getCookedDiagnosticPathName($trimNone,$id)===$preview);
 $sample=['index'=>$id,'preview_name'=>$preview];
}catch(Throwable $e){$fail[]='script5_authentic_exception';$authError=$e->getMessage();}
echo json_encode(['ok'=>$fail===[],'original_operation'=>'UE5-583-SCRIPT-005',
 'checks'=>$checks,'failures'=>$fail,
 'sample'=>$sample??null,'authentic_exception'=>$authError??null,
 'live_epic_runtime_executed'=>false,'diagnostic_preview_only'=>true],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
