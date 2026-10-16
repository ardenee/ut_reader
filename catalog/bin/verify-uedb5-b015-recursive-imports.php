<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder as Projection;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as Sql;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter as Writer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
$checks=[];$failed=[];
$check=static function(string $id,bool $ok)use(&$checks,&$failed):void{$checks[$id]=$ok;if(!$ok)$failed[]=$id;};
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$srcFile=$sourceRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$epic=is_file($srcFile)?(string)file_get_contents($srcFile):'';
$check('b0152_pinned_epic_source',hash('sha256',$epic)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$between=static function(string $a,string $b)use($epic):string{
 $start=strpos($epic,$a);if($start===false)return '';
 $end=strpos($epic,$b,$start+strlen($a));return $end===false?'':substr($epic,$start,$end-$start);
};
$inner=$between('void FAsyncPackage2::ImportPackagesRecursiveInner(','void FAsyncPackage2::ImportPackagesRecursive(');
$outer=$between('void FAsyncPackage2::ImportPackagesRecursive(','void FAsyncPackage2::InitializeLinkerLoadState(');
$check('b0152_every_package_id_enumerated',str_contains($inner,'Header.ImportedPackageIds')
 &&str_contains($inner,'LocalImportedPackageIndex < ImportedPackageCount')
 &&str_contains($inner,'if (!ImportedPackageCount)'));
$check('b0152_names_either_absent_or_equal_count',str_contains($inner,'ImportedPackageNames.Num() == 0 || ImportedPackageNames.Num() == ImportedPackageCount'));
$check('b0152_epic_redirect_instancing_localization_order',
 strpos($inner,'ApplyPackageNameRedirections(')!==false
 &&strpos($inner,'ApplyPackageNameRedirections(')<strpos($inner,'InstancingContext.RemapPackage(')
 &&strpos($inner,'InstancingContext.RemapPackage(')<strpos($inner,'ApplyLooseFileLocalizationPackageNameRedirects(')
 &&strpos($inner,'ApplyLooseFileLocalizationPackageNameRedirects(')<strpos($inner,'GetPackageRedirectInfo('));
$check('b0152_existing_package_lookup_precedes_store',str_contains($inner,'TryGetExistingLoadedPackagePath(')
 &&strpos($inner,'TryGetExistingLoadedPackagePath(')<strpos($inner,'TryGetPackageInfoFromPackageStore('));
$check('b0152_fully_loaded_precedes_missing_and_not_installed',
 strpos($inner,'if (bIsFullyLoaded)')<strpos($inner,'else if (bIsNotInstalled)')
 &&strpos($inner,'else if (bIsNotInstalled)')<strpos($inner,'else if (bIsMissing)'));
$check('b0152_pending_enqueue_and_cross_loader_barrier',
 str_contains($inner,'PendingPackages.Add(ImportedPackage)')
 &&str_contains($inner,'Package_DependenciesReady')
 &&str_contains($inner,'Package_ExportsSerialized'));
$check('b0152_once_and_optional_editor_guard',
 str_contains($outer,'if (bHasStartedImportingPackages)')
 &&str_contains($outer,'#if WITH_EDITOR')
 &&str_contains($outer,'OptionalSegmentHeaderData.IsSet()')
 &&substr_count($outer,'ImportPackagesRecursiveInner(')===2);
$check('b0152_existing_load_context_runtime_not_fabricated',
 str_contains($outer,'IncludePackageInSyncLoadContextRecursive')
 &&str_contains($inner,'AreAllPublicExportsLoaded()'));
$mk=static function(string $id):array{return [
 'package_family'=>Zen::PACKAGE_FAMILY,'source_policy'=>Zen::SOURCE_POLICY,
 'sections'=>['package_summary'=>[['package_id'=>$id]],'imports'=>[],'exports'=>[],
 'cell_imports'=>[],'cell_exports'=>[],'soft_package_references'=>[],
 'dependency_bundle_entries'=>[],'imported_package_ids'=>[],
 'optional_segment'=>[]],
 ];};
$ids=['1111111111111111','2222222222222222','3333333333333333','4444444444444444'];
$consumer=$mk('ABABABABABABABAB');
$consumer['sections']['imported_package_ids']=[
 ['index'=>0,'package_id'=>$ids[0],'serialized_name'=>['text'=>'/Game/Main']],
 ['index'=>1,'package_id'=>$ids[1],'serialized_name'=>null]];
$consumer['sections']['optional_segment']=[[
 'imported_package_ids'=>[['index'=>0,'package_id'=>$ids[2],'serialized_name'=>['text'=>'/Game/EditorOnly']]],
 'imports'=>[],'exports'=>[],'cell_imports'=>[],'cell_exports'=>[],
 'soft_package_references'=>[],'dependency_bundle_entries'=>[]]];
$providerA=$mk($ids[0]);$providerC=$mk($ids[2]);
$providers=[['provider_id'=>91,'snapshot'=>$providerA],['provider_id'=>93,'snapshot'=>$providerC]];
$find=static function(array $rows,string $section,int $index):array{
 foreach($rows as $row)if(($row['source_section']??null)===$section &&($row['source_index']??null)===$index)return $row;
 return [];
};
$before=Resolver::resolve($consumer,[]);
$check('b0152_no_context_still_emits_main_package_only_imports',
 count($before)===3
 &&($find($before,'imported_package_ids',0)['reason_code']??null)==='package_identity_runtime_context_required'
 &&($find($before,'imported_package_ids',1)['required_package_id']??null)===$ids[1]);
$check('b0152_optional_unknown_context_unresolved',
 ($find($before,'optional_segment_imported_package_ids',0)['reason_code']??null)==='optional_segment_editor_build_context_required');
$runtime=['with_editor'=>false,'package_identity_context_authoritative'=>true,
 'package_identity_rewrites'=>[],'package_store_context_authoritative'=>true,
 'package_redirects'=>[],'localized_packages'=>[],
 'package_store_backend_results'=>[[
 'backend_id'=>'runtime','priority'=>10,
 'entries'=>[
 $ids[0]=>['status'=>'Ok','file_id'=>91],
 $ids[1]=>['status'=>'Pending','file_id'=>92],
 $ids[2]=>['status'=>'Ok','file_id'=>93]],'redirects'=>[]]]];
$rows=Resolver::resolve($consumer,$providers,$runtime);
$a=$find($rows,'imported_package_ids',0);
$b=$find($rows,'imported_package_ids',1);
$c=$find($rows,'optional_segment_imported_package_ids',0);
$check('b0152_exact_evidenced_provider_is_package_only_not_runtime_loaded',
 ($a['outcome']??null)==='package_only'&&($a['selected_provider_file_id']??null)===91
 &&($a['runtime_schedule_authoritative']??null)===false);
$check('b0152_pending_kept_unresolved',($b['outcome']??null)==='unresolved'
 &&($b['reason_code']??null)==='package_store_entry_pending');
$check('b0152_noneditor_optional_not_loaded',
 ($c['outcome']??null)==='unresolved'
 &&($c['reason_code']??null)==='optional_segment_not_loaded_non_editor');
$editor=$runtime;$editor['with_editor']=true;
$rowsEditor=Resolver::resolve($consumer,$providers,$editor);
$check('b0152_editor_optional_separate_source_row',
 ($find($rowsEditor,'optional_segment_imported_package_ids',0)['selected_provider_file_id']??null)===93
 &&($find($rowsEditor,'optional_segment_imported_package_ids',0)['outcome']??null)==='package_only');
$missing=$runtime;$missing['package_store_backend_results'][0]['entries'][$ids[0]]=['status'=>'NotInstalled'];
$notInstalled=Resolver::resolve($consumer,$providers,$missing);
$check('b0152_not_installed_does_not_invent_unloaded_state',
 ($find($notInstalled,'imported_package_ids',0)['outcome']??null)==='unresolved'
 &&($find($notInstalled,'imported_package_ids',0)['reason_code']??null)==='recursive_import_existing_loaded_package_state_required');
$redirect=$runtime;
$redirect['package_redirects']=[['source_package_id'=>$ids[0],'target_package_id'=>$ids[2],'source_package_name'=>['text'=>'/Game/Main']]];
$redirected=Resolver::resolve($consumer,$providers,$redirect);
$check('b0152_redirect_precedes_evidenced_provider',
 ($find($redirected,'imported_package_ids',0)['provider_lookup_package_id']??null)===$ids[2]
 &&($find($redirected,'imported_package_ids',0)['selected_provider_file_id']??null)===93);
$rewritten=$runtime;$rewritten['package_identity_rewrites']=[[
 'source_package_id'=>$ids[0],'imported_package_id'=>$ids[2],'package_id_to_load'=>$ids[2],
 'reason'=>'instancing']];
$re=Resolver::resolve($consumer,$providers,$rewritten);
$check('b0152_identity_remap_before_backend_lookup',
 ($find($re,'imported_package_ids',0)['effective_import_package_id']??null)===$ids[2]
 &&($find($re,'imported_package_ids',0)['selected_provider_file_id']??null)===93);
$lookup=$runtime;unset($lookup['package_store_backend_results']);
$unknown=Resolver::resolve($consumer,$providers,$lookup);
$check('b0152_no_backend_status_never_claims_loaded_or_missing',
 ($find($unknown,'imported_package_ids',0)['outcome']??null)==='unresolved'
 &&($find($unknown,'imported_package_ids',0)['reason_code']??null)==='recursive_import_package_store_status_required');
$all=array_values($rowsEditor);
$consumer['file']=['id'=>150152,'game_id'=>7,'package_name'=>'/Game/B015'];
$consumer['sections']['dependency_results']=$all;
$proj=Projection::build($consumer);
$byKind=[];
foreach($proj['dependency_edges'] as $edge)$byKind[$edge['source_kind']][]=$edge;
$check('b0152_sql_main_source_kind_9_preserves_indices',
 count($byKind[Sql::DEP_SOURCE_IMPORTED_PACKAGE]??[])===2
 &&($byKind[Sql::DEP_SOURCE_IMPORTED_PACKAGE][0]['source_index']??null)===0
 &&($byKind[Sql::DEP_SOURCE_IMPORTED_PACKAGE][1]['source_index']??null)===1);
$check('b0152_sql_optional_source_kind_10_and_no_fake_object',
 count($byKind[Sql::DEP_SOURCE_OPTIONAL_IMPORTED_PACKAGE]??[])===1
 &&array_key_exists('required_object_key',$byKind[Sql::DEP_SOURCE_OPTIONAL_IMPORTED_PACKAGE][0])
 &&$byKind[Sql::DEP_SOURCE_OPTIONAL_IMPORTED_PACKAGE][0]['required_object_key']===null);
$check('b0152_sql_retains_package_count_and_outcomes',
 count($proj['dependency_packages'])===3&&count($proj['dependency_edges'])===3);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_uedb5_dependency_edges('
 .'file_id INTEGER,source_kind INTEGER,source_index INTEGER,classification INTEGER,outcome INTEGER,'
 .'required_package_key_kind INTEGER,required_package_key BLOB,required_object_key_kind INTEGER,'
 .'required_object_key BLOB,resolved_file_id INTEGER,resolved_object_kind INTEGER,resolved_object_index INTEGER,'
 .'PRIMARY KEY(file_id,source_kind,source_index))');
$db->exec('CREATE TABLE ue_uedb5_dependency_packages('
 .'game_id INTEGER,file_id INTEGER,package_key_kind INTEGER,package_key BLOB,required_package_name TEXT,'
 .'dependency_count INTEGER,resolved_count INTEGER,missing_count INTEGER,package_only_count INTEGER,'
 .'common_count INTEGER,unresolved_count INTEGER,hard_missing_count INTEGER,nonhard_missing_count INTEGER,'
 .'summary_outcome INTEGER,provider_file_id INTEGER,updated_at TEXT,'
 .'PRIMARY KEY(file_id,package_key_kind,package_key))');
$publisher=new UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5DependencyProjectionPublisher($db);
$sqlReport=$publisher->publish($consumer);
$published=$db->query('SELECT source_kind,source_index,outcome,resolved_file_id FROM ue_uedb5_dependency_edges ORDER BY source_kind,source_index')->fetchAll(PDO::FETCH_ASSOC);
$check('b0152_real_isolated_sql_persists_package_source_kinds',
 ($sqlReport['dependency_edges']??null)===3
 &&array_column($published,'source_kind')===[9,9,10]
 &&array_column($published,'source_index')===[0,1,0]);
$check('b0152_real_isolated_sql_separates_optional_and_main',
 (int)($published[0]['resolved_file_id']??0)===91
 &&(int)($published[2]['resolved_file_id']??0)===93
 &&(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages')->fetchColumn()===3);
$dir=sys_get_temp_dir().'/uedb5-b0152-'.bin2hex(random_bytes(5));
try{
 $w=(new Writer($dir))->write($consumer);
 $r=(new Reader($dir))->snapshot(7,150152);
 $recovered=(array)($r['sections']['dependency_results']??[]);
 $check('b0152_real_compressed_v5_roundtrip',
 isset($w['payload_sha256'])&&count($recovered)===3
 &&($find($recovered,'optional_segment_imported_package_ids',0)['required_package_id']??null)===$ids[2]);
}finally{
 if(is_dir($dir)){
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $f)if($f->isDir())@rmdir($f->getPathname());else @unlink($f->getPathname());
  @rmdir($dir);
 }
}
echo json_encode(['ok'=>$failed===[],'operation'=>'UE5-583-B015-2',
 'checks'=>$checks,'failures'=>$failed,'real_epic_async_scheduler_executed'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
