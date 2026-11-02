<?php
declare(strict_types=1);
/**
 * B005: source-derived hybrid cooked-editor branch fixture.
 * Proves the pinned source decision table and guards against promoting
 * hypothetical editor state to an authoritative UnrealDB mount.
 * No UnrealEditor binary, external file writes, or catalog DB access.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageStoreBackendArbitrator as Store;
$root=(string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'');
$file=rtrim(str_replace('\\','/',$root),'/')
 .'/Engine/Source/Runtime/CoreUObject/Private/Serialization/PackageStore.cpp';
$source=is_file($file)?file_get_contents($file):false;
$checks=[];$fail=[];
$check=static function(string $key,bool $ok)use(&$checks,&$fail):void{
 $checks[$key]=$ok;if(!$ok)$fail[]=$key;
};
$check('b005_hybrid_pinned_source',is_string($source)
 &&hash('sha256',$source)==='fb5c1930f5b8e6611392c3009dde16922661c7942f2eaa8d875492c8981f67b9');
$phrases=[
 'editor_build_guard'=>'#if WITH_EDITOR',
 'hybrid_activation'=>'if (IsRunningHybridCookedEditor())',
 'hybrid_loose_zen_backend'=>'MakeShared<FHybridPackageStoreBackend>(LinkerLoad, Zen)',
 'hybrid_forced_loose'=>'ForcedLoosePackages.Contains(PackageId)',
 'hybrid_asset_registry'=>'AssetRegistry->TryGetAssetPackageData(PackageName, AssetPackageData)',
 'hybrid_writable_asset'=>'Result == UE::AssetRegistry::EExists::Exists && !AssetPackageData.IsReadOnly()',
 'hybrid_unknown_asset'=>'Result == UE::AssetRegistry::EExists::Unknown',
 'hybrid_disk_readonly'=>'FPlatformFileManager::Get().GetPlatformFile().IsReadOnly',
 'hybrid_cooked_lookup'=>'InnerStatus = CookedPackageStore->GetPackageStoreEntry',
 'hybrid_missing_only_falls_back'=>'if (InnerStatus == EPackageStoreEntryStatus::Missing)',
 'hybrid_loose_lookup'=>'InnerStatus = LoosePackageStore->GetPackageStoreEntry',
 'hybrid_redirects_from_cooked'=>'return CookedPackageStore->GetPackageRedirectInfo',
 'hybrid_reads_both'=>'CookedPackageStore->BeginRead();',
 'hybrid_respects_package_loader'=>'EPackageLoader::LinkerLoad | EPackageLoader::Zen',
];
foreach($phrases as $key=>$needle){
 $check('b005_'.$key,is_string($source)&&str_contains($source,$needle));
}
// Independent literal decision-table oracle for Epic's hybrid inner backend.
// UNKNOWN and non-authoritative runtime inputs must not be inferred by a
// static metadata reader; rows below are wholly synthetic source fixtures.
$hybrid=static function(
 bool $forced,string $asset,bool $looseWritable,
 string $cooked,string $loose
):array{
 $allowCooked=!$forced;
 $earlyLoose=false;
 if(!$forced){
  if($asset==='exists_writable')$allowCooked=false;
  elseif($asset==='unknown'&&$looseWritable){
   $earlyLoose=true;$allowCooked=false;
  }
 }
 $out='Missing';$usedCooked=false;$usedLoose=false;
 if($earlyLoose){$out='Ok';$usedLoose=true;}
 if($allowCooked){$out=$cooked;$usedCooked=true;}
 if($out==='Missing'){$out=$loose;$usedLoose=true;}
 return ['status'=>$out,'cooked'=>$usedCooked,'loose'=>$usedLoose];
};
$cases=[
 ['forced_loose',true,'exists_readonly',false,'Ok','Ok','Ok',false,true],
 ['writable_asset',false,'exists_writable',false,'Ok','Ok','Ok',false,true],
 ['asset_registry_unknown_writable_loose',false,'unknown',true,'Pending','Ok','Ok',false,true],
 ['asset_registry_unknown_readonly_loose',false,'unknown',false,'Ok','Missing','Ok',true,false],
 ['read_only_cooked_pending_terminal',false,'exists_readonly',false,'Pending','Ok','Pending',true,false],
 ['read_only_cooked_not_installed_terminal',false,'exists_readonly',false,'NotInstalled','Ok','NotInstalled',true,false],
 ['read_only_cooked_none_not_missing',false,'exists_readonly',false,'None','Ok','None',true,false],
 ['read_only_cooked_missing_falls_to_loose',false,'exists_readonly',false,'Missing','Ok','Ok',true,true],
 ['read_only_cooked_missing_and_loose_missing',false,'exists_readonly',false,'Missing','Missing','Missing',true,true],
 ['unknown_no_loose_cooked_missing_fallback',false,'unknown',false,'Missing','Ok','Ok',true,true],
];
$id='0123456789ABCDEF';
foreach($cases as [$name,$forced,$asset,$writable,$cooked,$loose,$status,$cookedUsed,$looseUsed]){
 $actual=$hybrid($forced,$asset,$writable,$cooked,$loose);
 $check('b005_hybrid_oracle_'.$name,
  $actual===['status'=>$status,'cooked'=>$cookedUsed,'loose'=>$looseUsed]);
 // A mounted hybrid *backend* must still pass through Epic's outer
 // priority/status rules. Never insert its loose/cooked inner stores as
 // independently selectable top-level backends.
 if(in_array($status,['Ok','Pending','NotInstalled','Missing'],true)){
  $rows=[
   ['backend_id'=>'lower','priority'=>1,'entries'=>[$id=>['status'=>'Ok']],'redirects'=>[]],
   ['backend_id'=>'hybrid','priority'=>10,'entries'=>[$id=>['status'=>$status]],'redirects'=>[]],
  ];
  $chosen=Store::entry($rows,$id);
  $expectedBackend=$status==='Missing'?'lower':'hybrid';
  $check('b005_outer_priority_'.$name,($chosen['backend_id']??null)===$expectedBackend);
 }
}
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,
 'evidence'=>'independent_hybrid_source_branch_table',
 'source_execution'=>'source_derivation_no_editor_binary',
 'runtime_live_mount_captured'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
