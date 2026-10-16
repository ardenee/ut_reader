<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenMountedStoreContextBuilder as Mounted;
$root=(string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'');
$file=rtrim($root,'/\\').'/Engine/Source/Runtime/PakFile/Private/FilePackageStore.cpp';
$source=is_file($file)?(string)file_get_contents($file):'';
$checks=[];$fails=[];
$check=static function(string $k,bool $ok)use(&$checks,&$fails):void{
 $checks[$k]=$ok;if(!$ok)$fails[]=$k;
};
$check('b006_file_store_epic_sha',hash('sha256',$source)==='dfdd4e226b3a41f36476ddd93225aa34284ea63943f1752216e0f2a25c2a2418');
foreach([
 'b006_mount_stable_sort'=>'Algo::StableSort(MountedContainers',
 'b006_mount_order_before_sequence'=>'return A.Order > B.Order;',
 'b006_mount_sequence_descending'=>'return A.Sequence > B.Sequence;',
 'b006_update_pairs_from_container_ids'=>'Pairs.Add({PackageId, Handle});',
 'b006_redirect_find_or_add'=>'RedirectsPackageMap.FindOrAdd(Redirect.SourcePackageId)',
 'b006_redirect_none_allows_refill'=>'if (SourcePackageName.IsNone())',
 'b006_localized_find_or_add'=>'LocalizedPackages.FindOrAdd(LocalizedPackage.SourcePackageId)',
 'b006_localized_none_allows_refill'=>'if (LocalizedPackageSourceName.IsNone())',
 'b006_runtime_localization_skips_editor'=>'if (!GIsEditor)',
 'b006_direct_redirect_first'=>'RedirectsPackageMap.Find(PackageId)',
 'b006_mounted_lookup_status_missing'=>'return EPackageStoreEntryStatus::Missing;',
] as $k=>$needle){$check($k,str_contains($source,$needle));}
$id='AABBCCDDEEFF0011';
$mount=static function(string $label,int $order,int $sequence,array $r,array $l,array $entries=[]):array{
 return ['container_id'=>$label,'order'=>$order,'sequence'=>$sequence,
  'package_redirects'=>$r,'localized_packages'=>$l,'package_entries'=>$entries];
};
$redirect=static fn(string $name,string $target):array=>[
 'source_package_id'=>$id,'target_package_id'=>$target,
 'source_package_name'=>['text'=>$name,'number'=>0]];
$local=static fn(string $name):array=>[
 'source_package_id'=>$id,'source_package_name'=>['text'=>$name,'number'=>0]];
$higher=$mount('high',12,3,
 [$redirect('None','AAAAAAAAAAAAAAAA')],[$local('None')],
 [['package_id'=>$id,'file_id'=>70]]);
$lower=$mount('low',11,2,
 [$redirect('/Game/Valid','BBBBBBBBBBBBBBBB')],[$local('/Game/Valid')],
 [['package_id'=>$id,'file_id'=>71]]);
$actual=Mounted::build([$lower,$higher]);
$check('b006_source_none_redirect_refilled_by_lower_mount',
 ($actual['package_redirects'][0]['target_package_id']??null)==='BBBBBBBBBBBBBBBB');
$check('b006_source_none_localized_refilled_by_lower_mount',
 ($actual['localized_packages'][0]['source_package_name']['text']??null)==='/Game/Valid');
$check('b006_top_mount_package_id_priority_still_first',
 ($actual['file_backend_entries'][$id]['file_id']??null)===70);
$check('b006_mounted_order_higher_first',
 ($actual['mounted_container_order'][0]['container_id']??null)==='high');
$highValid=$higher;
$highValid['package_redirects']=[ $redirect('/Game/Higher','AAAAAAAAAAAAAAAA') ];
$highValid['localized_packages']=[ $local('/Game/Higher') ];
$b=Mounted::build([$lower,$highValid]);
$check('b006_valid_high_redirect_wins',
 ($b['package_redirects'][0]['target_package_id']??null)==='AAAAAAAAAAAAAAAA');
$check('b006_valid_high_localization_wins',
 ($b['localized_packages'][0]['source_package_name']['text']??null)==='/Game/Higher');
$unknownName=$higher;
unset($unknownName['package_redirects'][0]['source_package_name'],
      $unknownName['localized_packages'][0]['source_package_name']);
$opaque=Mounted::build([$lower,$unknownName]);
$check('b006_missing_name_is_not_assumed_none',
 ($opaque['package_redirects'][0]['target_package_id']??null)==='AAAAAAAAAAAAAAAA'
 && !array_key_exists('source_package_name',$opaque['localized_packages'][0]??[]));
$repo=dirname(__DIR__);
$counts=['Uedb5ZenMountedStoreContextBuilder'=>0,
 'Uedb5ZenEffectivePackageStoreContextBuilder'=>0];
$files=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
 $repo.'/src',FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||$file->getExtension()!=='php')continue;
 ++$files;
 $body=(string)file_get_contents($file->getPathname());
 foreach($counts as $name=>&$count){
  $count+=substr_count($body,$name.'::build(');
 }
 unset($count);
}
$check('b006_all_production_builder_callers_counted',
 $files>500
 &&$counts['Uedb5ZenMountedStoreContextBuilder']===2
 &&$counts['Uedb5ZenEffectivePackageStoreContextBuilder']===1);
$check('b006_no_unauthenticated_runtime_authority',
 ($b['package_store_context_authoritative']??null)===false
 &&($b['package_identity_context_authoritative']??null)===false);
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'failures'=>$fails,
 'source_oracle'=>true,'real_editor_binary_executed'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
