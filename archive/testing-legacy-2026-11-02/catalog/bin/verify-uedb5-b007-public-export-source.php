<?php
declare(strict_types=1);
/** B007 pinned Epic public export import-contract regression. */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as R;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Z;
$path=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\').'/Engine/Source/Runtime/CoreUObject/';
$cppPath=$path.'Private/Serialization/AsyncLoading2.cpp';
$headerPath=$path.'Public/Serialization/AsyncLoading2.h';
$cpp=is_file($cppPath)?(string)file_get_contents($cppPath):'';
$header=is_file($headerPath)?(string)file_get_contents($headerPath):'';
$checks=[];$fails=[];
$check=static function(string $name,bool $passed)use(&$checks,&$fails):void{
 $checks[$name]=$passed;if(!$passed)$fails[]=$name;
};
$check('b007_epic_cpp_pinned',hash('sha256',$cpp)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$check('b007_epic_public_header_pinned',hash('sha256',$header)==='088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2');
$from=strpos($header,'static FPublicExportKey FromPackageImport(');
$fromText=$from===false?'':substr($header,$from,650);
foreach(['GetImportedPackageIndex()','GetImportedPublicExportHashIndex()','return MakeKey(PackageId, ExportHash);'] as $part){
 $check('b007_from_import_'.substr(md5($part),0,8),str_contains($fromText,$part));
}
$check('b007_zero_hash_fatal_in_epic_key',str_contains($header,'check(ExportHash);'));
$check('b007_invalid_package_id_fatal_in_epic_key',str_contains($header,'check(PackageId.IsValid());'));
$check('b007_key_equality_requires_both_fields',str_contains($header,'GetExportHash() == Other.GetExportHash() &&')&&str_contains($header,'GetPackageId() == Other.GetPackageId()'));
$check('b007_runtime_first_package_id_then_hash',
 str_contains($cpp,'FindPackageRef(Key.GetPackageId())')
 &&str_contains($cpp,'GetPublicExport(Key.GetExportHash())'));
$check('b007_global_hash_collision_replaces_existing',
 str_contains($cpp,'ExistingValue = ObjectIndex;')
 &&str_contains($cpp,'The existing object will be replaced'));
$check('b007_global_export_store_disallows_hash_zero',str_contains($cpp,'check(ExportHash != 0);'));
$start=strpos($cpp,'int32 FAsyncPackage2::GetPublicExportIndex(');
$end=strpos($cpp,'void FAsyncPackage2::ConditionalCreateExport(',($start?:0));
$loop=($start!==false&&$end!==false)?substr($cpp,$start,$end-$start):'';
$first=strpos($loop,'HeaderData.ExportMap[ExportIndex].PublicExportHash == ExportHash');
$option=strpos($loop,'OptionalSegmentHeaderDataPtr->ExportMap[ExportIndex].PublicExportHash == ExportHash');
$check('b007_header_main_before_optional',$first!==false&&$option!==false&&$first<$option);
$check('b007_header_immediately_returns_first_match',
 str_contains($loop,'return ExportIndex;')&&str_contains($loop,'return HeaderData.ExportMap.Num() + ExportIndex;'));
$check('b007_optional_export_editor_only',str_contains($loop,'#if WITH_EDITOR'));
$check('b007_live_store_lookup_before_header_fallback',
 str_contains($cpp,'GlobalImportStore.FindPublicExportObject(FPublicExportKey::FromPackageImport(')
 &&str_contains($cpp,'if (UObject* FromImportStore = ImportStore.GetImportObject(Header, ObjectIndex))')
 &&str_contains($cpp,'SourcePackage->GetPublicExportIndex(ExportHash, SourcePackageHeader)'));
$pkg='1234567890ABCDEF';$hash='1122334455667788';
$snapshot=static function(string $id,array $imports,array $exports):array{
 return ['package_family'=>Z::PACKAGE_FAMILY,'source_policy'=>Z::SOURCE_POLICY,
 'sections'=>[
 'package_summary'=>[['package_id'=>$id]],'imports'=>$imports,'exports'=>$exports,
 'cell_imports'=>[],'cell_exports'=>[],'soft_package_references'=>[],
 'dependency_bundle_entries'=>[],'package_redirects'=>[],'localized_packages'=>[]
 ]];
};
$import=static fn(string $id,string $h):array=>[
 'type'=>'PackageImport','dependency_class'=>'hard',
 'provider_package_id'=>$id,'provider_public_export_hash'=>$h
];
$provider=$snapshot($pkg,[],[
 ['public_export_hash'=>$hash,'filter_flags'=>0,'object_name'=>['text'=>'First']],
 ['public_export_hash'=>$hash,'filter_flags'=>0,'object_name'=>['text'=>'Second']]
]);
$consumer=$snapshot('AAAAAAAAAAAAAAAA',[$import($pkg,$hash)],[]);
$context=['package_identity_context_authoritative'=>true,'package_identity_rewrites'=>[],
 'package_store_context_authoritative'=>true,'package_redirects'=>[],'localized_packages'=>[],
 'with_editor'=>false];
$rows=R::resolve($consumer,[['provider_id'=>72,'snapshot'=>$provider]],$context);
$row=(array)($rows[0]??[]);
$check('b007_duplicated_live_export_remains_unresolved',
 ($row['outcome']??'')==='unresolved'
 &&($row['reason_code']??'')==='public_export_hash_runtime_collision_ambiguous'
 &&($row['candidate_export_indices']??[])===[0,1]);
$check('b007_source_header_first_index_provenance_not_runtime_resolution',
 ($row['source_header_first_export']['segment']??'')==='main'
 &&($row['source_header_first_export']['index']??null)===0
 &&($row['source_header_first_export']['runtime_lookup_authoritative']??null)===false);
$badConsumer=$snapshot('AAAAAAAAAAAAAAAA',[$import($pkg,'0000000000000000')],[]);
$rejectZero=false;
try{R::resolve($badConsumer,[['provider_id'=>72,'snapshot'=>$provider]],$context);}
catch(RuntimeException $ex){$rejectZero=str_contains($ex->getMessage(),'PublicExportHash')&&str_contains($ex->getMessage(),'zero');}
$check('b007_invalid_zero_import_hash_rejected_like_epic',$rejectZero);
$badPackage=$snapshot('AAAAAAAAAAAAAAAA',[$import('0000000000000000',$hash)],[]);
$zeroPackageRejected=false;
try{R::resolve($badPackage,[['provider_id'=>72,'snapshot'=>$provider]],$context);}
catch(RuntimeException $e){$zeroPackageRejected=str_contains($e->getMessage(),'FPackageId')&&str_contains($e->getMessage(),'zero');}
$check('b007_import_zero_fpackageid_rejected_like_epic',$zeroPackageRejected);
$zeroProvider=$snapshot('0000000000000000',[],[['public_export_hash'=>$hash]]);
$zeroProviderRejected=false;
try{R::resolve($consumer,[['provider_id'=>72,'snapshot'=>$zeroProvider]],$context);}
catch(RuntimeException $e){$zeroProviderRejected=str_contains($e->getMessage(),'FPackageId')&&str_contains($e->getMessage(),'zero');}
$check('b007_provider_zero_fpackageid_rejected_like_epic',$zeroProviderRejected);
$other=$snapshot('FFEEDDCCBBAA0099',[],[
 ['public_export_hash'=>$hash,'filter_flags'=>0,'object_name'=>['text'=>'Other']]
]);
$unmatched=R::resolve($consumer,[['provider_id'=>73,'snapshot'=>$other]],$context);
$check('b007_same_hash_wrong_package_does_not_resolve',
 ($unmatched[0]['outcome']??'')==='missing'
 &&($unmatched[0]['selected_provider_object']??null)===null);
$good=$snapshot($pkg,[],[['public_export_hash'=>$hash,'filter_flags'=>0,'object_name'=>['text'=>'One']]]);
$one=R::resolve($consumer,[['provider_id'=>74,'snapshot'=>$good]],$context);
$check('b007_exact_provider_and_hash_resolves',
 ($one[0]['outcome']??'')==='resolved'
 &&($one[0]['selected_provider_file_id']??0)===74
 &&($one[0]['selected_provider_object']['export_index']??null)===0);
$repo=dirname(__DIR__);
$callers=[];$files=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
 $repo.'/src',FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
 ++$files;
 $text=(string)file_get_contents($file->getPathname());
 $count=substr_count($text,'Uedb5Ue5ZenDependencyResolver::resolve(');
 if($count)$callers[basename($file->getPathname())]=$count;
}
ksort($callers);
$expected=[
 'PdoUedb5PhysicalProviderSelector.php'=>1,
 'Uedb5DependencyRebuilder.php'=>1,
];
$check('b007_complete_production_resolver_caller_inventory',$files>=500&&$callers===$expected);
$future=$callers;$future['UnregisteredCaller.php']=1;
$check('b007_new_unregistered_callsite_fails_inventory',$future!==$expected);
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'failures'=>$fails,
 'source_derived'=>true,'epic_executable_invoked'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
