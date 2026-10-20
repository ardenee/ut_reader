<?php
/** Native FPackageFileSummary and FWorldTileInfo differential across both endians
 * and UE4 pre-array single ChunkID field from an actual version-288 package.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-p06-world-manifest.txt';
$log=$argv[2]??'C:/Temp/ue427-p06-world-native-all.log';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$raw=@file_get_contents($log);
if(!is_array($paths)||!is_string($raw))throw new RuntimeException('Native manifest/log missing');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$head=[];$chunks=[];$world=[];$errors=[];$final=false;
foreach(explode("\n",$raw) as $line){
 if(str_contains($line,'UE427_NATIVE_COUNT 3'))$final=true;
 foreach(['UE427_NATIVE_P06_WORLD_HEAD|'=>'head','UE427_NATIVE_P06_CHUNK|'=>'chunk','UE427_NATIVE_P06_WORLD|'=>'world'] as $tag=>$kind){
  $at=strpos($line,$tag);if($at===false)continue;
  $f=explode('|',trim(substr($line,$at+strlen($tag))));
  if($kind==='head'&&count($f)===3)$head[(int)$f[0]]=[(int)$f[1],(int)$f[2]];
  elseif($kind==='chunk'&&count($f)===3)$chunks[(int)$f[0]][(int)$f[1]]=(int)$f[2];
  elseif($kind==='world'&&count($f)===11)$world[(int)$f[0]]=$f;
  else $errors[]=['bad_epic_native_line'=>$f,'kind'=>$kind];
  break;
 }
}
$totals=['fixtures'=>count($paths),'native_head_rows'=>count($head),'native_world_rows'=>count($world),
 'chunk_ids_compared'=>0,'world_fields_compared'=>0];
foreach($paths as $i=>$path){
 $r=new UnrealPackageReader4($path);
 if($r->validatePackage()!==[]){$errors[]=['file'=>$i,'issues'=>$r->validatePackage()];continue;}
 $h=$r->getHeader();$w=$r->getWorldTileInfo();
 if(!isset($head[$i])){$errors[]=['file'=>$i,'native_missing_head'=>true];continue;}
 [$offset,$number]=$head[$i];
 $ids=array_values((array)$h['chunkIDs']);
 $nativeIds=array_values($chunks[$i]??[]);
 if($ids!==$nativeIds||$number!==count($ids)||$offset!==(int)$h['worldTileInfoDataOffset'])
  $errors[]=['file'=>$i,'chunk_or_world_header_mismatch'=>[$ids,$nativeIds,$number,$offset]];
 $totals['chunk_ids_compared']+=count($ids);
 if($offset===0){
  if($w!==[]||isset($world[$i]))$errors[]=['file'=>$i,'unexpected_world'=>true];
  if($h['version']!==288||$ids!==[7])$errors[]=['file'=>$i,'wrong_legacy_case'=>[$h['version'],$ids]];
  continue;
 }
 if(!isset($world[$i])){$errors[]=['file'=>$i,'missing_world'=>true];continue;}
 $n=$world[$i];
 $actual=[
  (int)$n[1],(int)$n[2],(int)$n[3],$n[4],(int)$n[5],(int)$n[6],
  $n[7],(int)$n[8],(int)$n[9],(int)$n[10]
 ];
 $php=[
  $w['position'][0],$w['position'][1],$w['position'][2],$w['layerName'],
  $w['streamingDistance'],$w['distanceStreamingEnabled']?1:0,
  $w['parentTilePackageName'],$w['hideInTileView']?1:0,$w['zOrder'],$w['endOffset']
 ];
 if($php!==$actual)$errors[]=['file'=>$i,'native_world_mismatch'=>['native'=>$actual,'reader'=>$php]];
 $expected=[
  'position'=>[12,-34,56],'boundsMin'=>[-100.0,-200.0,-300.0],
  'boundsMax'=>[100.0,200.0,300.0],'boundsValid'=>true,
  'layerReserved0'=>0,'layerReservedPosition'=>[0,0],
  'customVersion3D'=>true,'lodListCount'=>1,'lodDistance'=>12345,
 ];
 foreach($expected as $field=>$value){
  $actualField=match($field){
   'lodListCount'=>count($w['lodList']),
   'lodDistance'=>$w['lodList'][0]['relativeStreamingDistance']??null,
   default=>$w[$field]??null
  };
  if($actualField!==$value)$errors[]=['file'=>$i,'fixture_field'=>$field,'expected'=>$value,'actual'=>$actualField];
 }
 if((bool)$h['byteSwapping']!==str_contains(strtolower($path),'swapped'))
  $errors[]=['file'=>$i,'wrong_endian_decision'=>true];
 $totals['world_fields_compared']+=10+count($expected);
}
$ok=$final&&$totals['fixtures']===3&&$totals['native_world_rows']===2&&$errors===[];
echo json_encode(['ok'=>$ok,'scope'=>'Compiled Epic FPackageFileSummary and FWorldTileInfo - v288 legacy and v522 twin endians',
 'counts'=>$totals,'mismatch_count'=>count($errors),'mismatches'=>array_slice($errors,0,18)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
