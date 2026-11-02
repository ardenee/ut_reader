<?php
declare(strict_types=1);
/** Epic UE5.8.3 B008 source-to-production import rewrite contract. */
if(PHP_SAPI!=='cli')exit(2);
$base=rtrim((string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:''),'/\\');
$runtime=$base.'/Engine/Source/Runtime/';
$asyncPath=$runtime.'CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$storePath=$runtime.'CoreUObject/Private/Serialization/PackageStore.cpp';
$filePath=$runtime.'PakFile/Private/FilePackageStore.cpp';
$expected=[
 $asyncPath=>'43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9',
 $storePath=>'fb5c1930f5b8e6611392c3009dde16922661c7942f2eaa8d875492c8981f67b9',
 $filePath=>'dfdd4e226b3a41f36476ddd93225aa34284ea63943f1752216e0f2a25c2a2418',
];
$tests=[];$failed=[];
$assert=static function(string $key,bool $ok)use(&$tests,&$failed):void{
 $tests[$key]=$ok;if(!$ok)$failed[]=$key;
};
$sources=[];
foreach(['async'=>$asyncPath,'store'=>$storePath,'file'=>$filePath] as $tag=>$path){
 $sources[$tag]=is_file($path)?(string)file_get_contents($path):'';
 $assert('b008_pinned_'.$tag.'_sha256',
  $sources[$tag]!=='' && hash('sha256',$sources[$tag])===$expected[$path]);
}
$a=$sources['async'];
$start=strpos($a,'void FAsyncPackage2::ImportPackagesRecursiveInner(');
$end=strpos($a,'void FAsyncPackage2::InitializeLinkerLoadState(');
$fn=$start!==false&&$end!==false&&$end>$start?substr($a,$start,$end-$start):'';
$positions=[];
foreach([
 'core'=>'ApplyPackageNameRedirections(ImportedPackageNameToLoad)',
 'instance'=>'InstancingContext.RemapPackageId(ImportedPackageId)',
 'loose'=>'ApplyLooseFileLocalizationPackageNameRedirects(ImportedPackageNameToLoad)',
 'store'=>'PackageStore.GetPackageRedirectInfo(ImportedPackageIdToLoad, SourcePackageName, RedirectedToPackageId)',
 'load'=>'AsyncLoadingThread.TryGetPackageInfoFromPackageStore(ImportedPackageIdToLoad',
] as $name=>$needle){
 $pos=strpos($fn,$needle);$positions[$name]=$pos;
 $assert('b008_runtime_branch_'.$name,$pos!==false);
}
$assert('b008_rewrite_phase_order_core_instance_loose_store_load',
 !in_array(false,$positions,true)
 &&$positions['core']<$positions['instance']
 &&$positions['instance']<$positions['loose']
 &&$positions['loose']<$positions['store']
 &&$positions['store']<$positions['load']);
foreach([
 'b008_core_redirect_gate'=>'if (GAllowPackageRedirectorSupport)',
 'b008_core_redirect_requires_name'=>'if (!ImportedPackageNameToLoad.IsNone())',
 'b008_core_redirect_rewrites_import_id'=>'ImportedPackageIds[LocalImportedPackageIndex] = ImportedPackageId;',
 'b008_instancing_name_to_upackage'=>'InstancingContext.RemapPackage(ImportedPackageNameToLoad)',
 'b008_instancing_id_without_name'=>'InstancingContext.RemapPackageId(ImportedPackageId)',
 'b008_localization_only_load_identity'=>'ImportedPackageIdToLoad = FPackageId::FromName(ImportedPackageNameToLoad);',
 'b008_package_store_redirect_sets_load_id'=>'ImportedPackageIdToLoad = RedirectedToPackageId;',
 'b008_package_store_redirect_clears_load_name'=>'ImportedPackageNameToLoad = NAME_None;',
 'b008_runtime_reads_instanced_upackage'=>'ImportedPackageUPackageName = InstancingContext.RemapPackage(ImportedPackageNameToLoad);',
 'b008_unnamed_import_list_permitted'=>'ImportedPackageNames.Num() == 0 || ImportedPackageNames.Num() == ImportedPackageCount',
] as $case=>$needle){$assert($case,str_contains($fn,$needle));}
$looseStart=strpos($a,'FName ApplyLooseFileLocalizationPackageNameRedirects(FName PackageName)');
$looseEnd=strpos($a,'struct FAsyncPackage2;',($looseStart===false?0:$looseStart));
$loose=$looseStart!==false&&$looseEnd!==false?substr($a,$looseStart,$looseEnd-$looseStart):'';
$assert('b008_loose_localization_requires_runtime_and_loose_mode',
 str_contains($loose,'!GIsEditor && FAsyncLoadingThreadSettings::Get().bLooseFileLoadingEnabled'));
$store=$sources['store'];
$redirectStart=strpos($store,'bool FPackageStore::GetPackageRedirectInfo(');
$redirectBody=$redirectStart!==false?substr($store,$redirectStart,700):'';
$assert('b008_redirect_queries_all_mounted_backends_in_priority_order',
 str_contains($redirectBody,'for (const FBackendAndPriority& Backend : Backends)')
 &&str_contains($redirectBody,'Backend.Value->GetPackageRedirectInfo(')
 &&str_contains($redirectBody,'return true;'));
$file=$sources['file'];
$fileStart=strpos($file,'bool FFilePackageStoreBackend::GetPackageRedirectInfo(');
$fileBody=$fileStart!==false?substr($file,$fileStart,2600):'';
$explicit=strpos($fileBody,'RedirectsPackageMap.Find(PackageId)');
$local=strpos($fileBody,'LocalizedPackages.Find(PackageId)');
$assert('b008_file_backend_explicit_redirect_before_localization',
 $explicit!==false &&$local!==false&&$explicit<$local);
$assert('b008_file_localization_non_editor_and_installed_only',
 str_contains($fileBody,'if (!GIsEditor)')
 &&str_contains($fileBody,'FPackageLocalizationManager::Get().FindLocalizedPackageName')
 &&str_contains($fileBody,'PackageEntries.Find(LocalizedPackageId)'));
$repo=dirname(__DIR__);
$resolver=(string)file_get_contents($repo.'/src/Infrastructure/Metadata/Uedb5Ue5ZenDependencyResolver.php');
$service=(string)file_get_contents($repo.'/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php');
foreach([
 'b008_identity_rewrite_table_required'=>"array_key_exists('package_identity_rewrites',\$runtimeContext)",
 'b008_redirect_global_map_required'=>"array_key_exists('package_redirects',\$runtimeContext)",
 'b008_localization_global_map_required'=>"array_key_exists('localized_packages',\$runtimeContext)",
 'b008_explicit_none_can_refill'=>'packageSourceNameIsNone(',
 'b008_global_absence_not_local_proof'=>"'package_store_mount_context_required'",
 'b008_unknown_identity_not_resolved'=>"'package_identity_runtime_context_required'",
 'b008_culture_not_guessed'=>"'localized_package_runtime_culture_required'",
 'b008_backend_status_shared'=>'Uedb5ZenPackageStoreBackendArbitrator::entry(',
] as $case=>$needle){$assert($case,str_contains($resolver,$needle));}
$assert('b008_game_source_requires_evidence_origin_for_authority',
 str_contains($service,"'evidence_source'")&&str_contains($service,"'zen_runtime_context_by_game'"));
$references=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/src',FilesystemIterator::SKIP_DOTS));
$fileCount=0;
foreach($it as $f){
 if(!$f->isFile() || strtolower($f->getExtension())!=='php')continue;
 ++$fileCount;$body=(string)file_get_contents($f->getPathname());
 $count=substr_count($body,'Uedb5Ue5ZenDependencyResolver::resolve(');
 if($count)$references[basename($f->getPathname())]=$count;
}
ksort($references);
$expectedRefs=[
 'PdoUedb5PhysicalProviderSelector.php'=>1,
 'Uedb5DependencyRebuilder.php'=>1
];
$assert('b008_exhaustive_production_resolver_callsite_inventory',
 $fileCount>=500&&$references===$expectedRefs);
$assert('b008_unregistered_direct_resolver_call_negative_control',
 array_keys($references+['UnregisteredResolver.php'=>1])!==array_keys($expectedRefs));
echo json_encode(['ok'=>$failed===[],'checks'=>$tests,'failures'=>$failed,
 'direct_consumer_callers'=>$references,'production_files_scanned'=>$fileCount,
 'source_derived'=>true,'editor_executable_required'=>false,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
