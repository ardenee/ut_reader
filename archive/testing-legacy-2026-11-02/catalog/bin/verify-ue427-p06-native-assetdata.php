<?php
/** Independent compiled UE4.27 FAssetData constructor/tag parity on engine corpus.
 * The native harness mirrors non-exported PackageReader.cpp source branch
 * decisions and invokes compiled FAssetData constructors; it does not invoke
 * the private ReadPackageDataMain itself.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$log=$argv[2]??'C:/Temp/ue427-p06-assetdata-native.log';
$files=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$raw=@file_get_contents($log);
if(!is_array($files)||!is_string($raw))throw new RuntimeException('Missing genuine package manifest or native log');
$files[0]=preg_replace('/^\xEF\xBB\xBF/','',$files[0]);
$isFixture=count($files)===3 && str_contains(str_replace('\\','/',$files[0]),'/Saved/UE427AssetFixtures/');
$expectedFiles=$isFixture?3:1749;
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$meta=[];$natives=[];$issues=[];$seenEnd=false;
foreach(explode("\n",$raw) as $line){
 if(str_contains($line,'UE427_NATIVE_COUNT '.$expectedFiles))$seenEnd=true;
 foreach(['UE427_NATIVE_P06_ASSET_COUNT|'=>'count','UE427_NATIVE_P06_ASSET_TAG|'=>'tag',
  'UE427_NATIVE_P06_ASSET|'=>'asset'] as $needle=>$kind){
  $start=strpos($line,$needle);if($start===false)continue;
  $v=explode('|',trim(substr($line,$start+strlen($needle))));
  $i=(int)$v[0];$source=(int)($v[1]??0);
  if($kind==='count'){
   if(count($v)!==5||isset($meta[$i]))$issues[]=['invalid_native_count'=>$v];
   else $meta[$i]=array_map('intval',array_slice($v,1));
  }elseif($kind==='asset'){
   if(count($v)!==11||isset($natives[$i][$source]['asset']))$issues[]=['invalid_native_asset'=>$v];
   else $natives[$i][$source]['asset']=[
     'packageName'=>hex2bin($v[2]),'packagePath'=>hex2bin($v[3]),
     'objectPath'=>hex2bin($v[4]),'assetName'=>hex2bin($v[5]),
     'assetClass'=>hex2bin($v[6]),'chunkCSV'=>$v[7],
     'packageFlags'=>(int)$v[8],'tagCount'=>(int)$v[9],
     'syntheticWorld'=>$v[10]==='1',
   ];
  }elseif($kind==='tag'){
   if(count($v)!==4)$issues[]=['invalid_native_tag'=>$v];
   else $natives[$i][$source]['tags'][strtolower((string)hex2bin($v[2]))]=(string)hex2bin($v[3]);
  }
  break;
 }
}
$totals=['packages'=>0,'native_record_sections'=>count($meta),'asset_rows'=>0,
 'native_asset_rows'=>0,'raw_object_rows'=>0,'tags_compared'=>0,
 'semantic_fields_compared'=>0,'filtered_empty_tags'=>0,'synthetic_world_rows'=>0,
 'native_tag_mismatch_rows'=>0];
$tagMismatchKeys=[];
foreach($natives as $list)$totals['native_asset_rows']+=count($list);
if(count($files)!==$expectedFiles||count($meta)!==$expectedFiles||!$seenEnd)$issues[]=['incomplete_corpus'=>count($files),'sections'=>count($meta),'end'=>$seenEnd];
foreach($files as $i=>$path){
 $r=new UnrealPackageReader4($path);
 $totals['packages']++;
 if($r->validatePackage()!==[]){if(count($issues)<20)$issues[]=['file'=>$i,'reader_issues'=>$r->validatePackage()];continue;}
 $root=str_replace('\\','/',$path);
 if($isFixture){
  $p=strpos(strtolower($root),'/saved/ue427assetfixtures/');
  if($p===false){$issues[]=['file'=>$i,'not_disposable_fixture'=>$path];continue;}
  $suffix=substr($root,$p+strlen('/saved/ue427assetfixtures/'));
  $packageName='/Game/UE427AssetFixtures/'.preg_replace('/\.(uasset|umap)$/i','',$suffix);
 }else{
  $p=strpos(strtolower($root),'/engine/content/');
  if($p===false){if(count($issues)<20)$issues[]=['file'=>$i,'unsupported_engine_mount'=>$path];continue;}
  $suffix=substr($root,$p+strlen('/engine/content/'));
  $packageName='/Engine/'.preg_replace('/\.(uasset|umap)$/i','',$suffix);
 }
 $assets=$r->getAssetRegistryAssetData($packageName);
 $rawRows=$r->getAssetRegistryObjects();
 $totals['raw_object_rows']+=count($rawRows);
 if(!isset($meta[$i])){if(count($issues)<20)$issues[]=['file'=>$i,'missing_native_count'=>true];continue;}
 [$count,$original,$publicGate,$externalGate]=$meta[$i];
 if($count!==count($assets)||$original!==count($rawRows)||$publicGate!==337||$externalGate!==520){
  if(count($issues)<20)$issues[]=['file'=>$i,'wrong_counts_and_gates'=>[$count,count($assets),$original,count($rawRows),$publicGate,$externalGate]];
 }
 foreach($rawRows as $row)foreach($row['tags'] as $t)
  if($t['key']===''||$t['value']==='')$totals['filtered_empty_tags']++;
 foreach($assets as $asset){
  $source=$asset['sourceIndex'];$totals['asset_rows']++;
  if($asset['syntheticWorld'])$totals['synthetic_world_rows']++;
  $n=$natives[$i][$source]['asset']??null;
  if($n===null){if(count($issues)<20)$issues[]=['file'=>$i,'source'=>$source,'missing_native_asset'=>true];continue;}
  $actual=[
   'packageName'=>$asset['packageName'],'packagePath'=>$asset['packagePath'],
   'objectPath'=>$asset['objectPath'],'assetName'=>$asset['assetName'],
   'assetClass'=>$asset['assetClass'],'chunkCSV'=>implode(',',$asset['chunkIDs']),
   'packageFlags'=>$asset['packageFlags'],'tagCount'=>count($asset['tags']),
   'syntheticWorld'=>$asset['syntheticWorld'],
  ];
  // FName is case-insensitive, but its display spelling may vary according
  // to the first FName registration in an Editor process.
  foreach(['packageName','packagePath','objectPath','assetName','assetClass'] as $k){
   if(is_string($n[$k]))$n[$k]=strtolower($n[$k]);
   $actual[$k]=strtolower((string)$actual[$k]);
  }
  $totals['semantic_fields_compared']+=count($actual);
  if($actual!==$n&&count($issues)<20)$issues[]=['file'=>$i,'source'=>$source,'native'=>$n,'php'=>$actual];
  $ntags=$natives[$i][$source]['tags']??[];
  $ptags=[];
  foreach($asset['tags'] as $t)$ptags[strtolower($t['key'])]=$t['value'];
  $totals['tags_compared']+=count($ptags);
  ksort($ntags);ksort($ptags);
  if($ptags!==$ntags){
   $totals['native_tag_mismatch_rows']++;
   $keys=array_unique(array_merge(array_keys($ptags),array_keys($ntags)));
   foreach($keys as $key)if(($ptags[$key]??null)!==($ntags[$key]??null))$tagMismatchKeys[$key]=($tagMismatchKeys[$key]??0)+1;
   if(count($issues)>=20)continue;
   $diff=[];
   foreach($keys as $key){if(($ptags[$key]??null)!==($ntags[$key]??null))$diff[]=['key'=>$key,'phpLen'=>strlen((string)($ptags[$key]??'')),'nativeLen'=>strlen((string)($ntags[$key]??'')),'phpHash'=>sha1((string)($ptags[$key]??'')),'nativeHash'=>sha1((string)($ntags[$key]??''))];}
   $issues[]=['file'=>$i,'source'=>$source,'phpCount'=>count($ptags),'nativeCount'=>count($ntags),'differences'=>$diff];
  }
 }
}
$ok=$issues===[] && $totals['packages']===$expectedFiles &&
 $totals['asset_rows']===$totals['native_asset_rows'] &&
 ($isFixture
  ? ($totals['asset_rows']===3 && $totals['raw_object_rows']===3
   && $totals['tags_compared']===1 && $totals['filtered_empty_tags']===2
   && $totals['synthetic_world_rows']===1)
  : ($totals['asset_rows']===1730 && $totals['tags_compared']===11442
   && $totals['filtered_empty_tags']===616));
echo json_encode(['ok'=>$ok,'source'=>'Pinned Epic PackageReader.cpp semantics mirrored for private function; compiled FAssetData ctor/tag-map result independently compared',
 'counts'=>$totals,'tag_mismatch_keys'=>$tagMismatchKeys,'mismatch_count'=>count($issues),'mismatches'=>array_slice($issues,0,20)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
