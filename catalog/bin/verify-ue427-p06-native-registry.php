<?php
/**
 * Compare raw AssetRegistry main-section wire data with Epic FArchive output.
 * Does not claim synthesized FAssetData equivalence or dependency bit decoding.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$log=$argv[2]??'C:/Temp/ue427-p06-registry-installed-native.log';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$raw=@file_get_contents($log);
if(!is_array($paths)||!is_string($raw))throw new RuntimeException('Missing native registry corpus');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$headers=[];$objects=[];$tags=[];$nativeVersion=null;$errors=[];
foreach(explode("\n",$raw) as $line) {
 foreach(['UE427_NATIVE_P06_REG_VERSION|'=>'version',
          'UE427_NATIVE_P06_REG_HEADER|'=>'header',
          'UE427_NATIVE_P06_REG_OBJECT|'=>'object',
          'UE427_NATIVE_P06_REG_TAG|'=>'tag'] as $marker=>$kind){
  $at=strpos($line,$marker);if($at===false)continue;
  $items=explode('|',trim(substr($line,$at+strlen($marker))));
  $width=['version'=>1,'header'=>5,'object'=>6,'tag'=>6][$kind];
  if(count($items)!==$width){if(count($errors)<20)$errors[]=['malformed'=>$kind,'row'=>$items];break;}
  if($kind==='version'){$nativeVersion=(int)$items[0];break;}
  $i=(int)$items[0];
  if($kind==='header')$headers[$i]=array_map('intval',array_slice($items,1));
  if($kind==='object')$objects[$i][(int)$items[1]]=[
   'offset'=>(int)$items[2],'objectPath'=>strtoupper($items[3]),
   'className'=>strtoupper($items[4]),'tagCount'=>(int)$items[5],
  ];
  if($kind==='tag')$tags[$i][(int)$items[1]][(int)$items[2]]=[
   'offset'=>(int)$items[3],'key'=>strtoupper($items[4]),'value'=>strtoupper($items[5]),
  ];
  break;
 }
}
$count=['packages'=>count($paths),'with_registry'=>0,'pre_dependency_format'=>0,
 'dependency_format'=>0,'registry_objects'=>0,'registry_tags'=>0,
 'object_fields'=>0,'tag_fields'=>0,'header_fields'=>0];
if($nativeVersion!==521)$errors[]=['bad_epic_version_gate'=>$nativeVersion];
foreach($paths as $i=>$path){
 $reader=new UnrealPackageReader4($path);$h=$reader->getHeader();
 if($reader->validatePackage()!==[]){
  if(count($errors)<20)$errors[]=['file'=>$i,'readerIssues'=>array_slice($reader->validatePackage(),0,4)];
  continue;
 }
 $offset=(int)($h['assetRegistryDataOffset']??0);
 $pre=(int)($h['version']??0)<$nativeVersion ||
      (((int)($h['packageFlags']??0)&0x80000000)!==0);
 $rows=$reader->getAssetRegistryObjects();
 $dependency=$reader->getAssetRegistryDependencyDataOffset();
 if($offset>0){
  $count['with_registry']++;
  $count[$pre?'pre_dependency_format':'dependency_format']++;
 }
 $wantHeader=[$offset,$pre?1:0,$dependency,count($rows)];
 $count['header_fields']+=4;
 if(($headers[$i]??null)!==$wantHeader){
  if(count($errors)<20)$errors[]=['file'=>$i,'headerMismatch'=>[$headers[$i]??null,$wantHeader]];
  continue;
 }
 if(count($objects[$i]??[])!==count($rows)){
  if(count($errors)<20)$errors[]=['file'=>$i,'objectCountMismatch'=>[count($objects[$i]??[]),count($rows)]];
  continue;
 }
 foreach($rows as $j=>$row){
  $entry=['offset'=>$row['offset'],'objectPath'=>strtoupper(bin2hex($row['objectPath'])),
   'className'=>strtoupper(bin2hex($row['className'])),'tagCount'=>$row['tagCount']];
  $count['registry_objects']++;$count['object_fields']+=4;
  if(($objects[$i][$j]??null)!==$entry && count($errors)<20){
   $errors[]=['file'=>$i,'object'=>$j,'epic'=>$objects[$i][$j]??null,'unrealdb'=>$entry];
  }
  if(count($tags[$i][$j]??[])!==count($row['tags'])){
   if(count($errors)<20)$errors[]=['file'=>$i,'object'=>$j,'tagsMismatch'=>true];
   continue;
  }
  foreach($row['tags'] as $k=>$tag){
   $val=['offset'=>$tag['offset'],'key'=>strtoupper(bin2hex($tag['key'])),
    'value'=>strtoupper(bin2hex($tag['value']))];
   $count['registry_tags']++;$count['tag_fields']+=3;
   if(($tags[$i][$j][$k]??null)!==$val && count($errors)<20)
    $errors[]=['file'=>$i,'object'=>$j,'tag'=>$k,'epic'=>$tags[$i][$j][$k]??null,'unrealdb'=>$val];
  }
 }
}
$ok=$errors===[]&&count($headers)===count($paths)&&
 $count['registry_objects']===array_sum(array_map('count',$objects)) &&
 $count['registry_tags']===array_sum(array_map(static fn(array $o):int=>array_sum(array_map('count',$o)),$tags));
echo json_encode(['ok'=>$ok,'compiled_epic_version_gate'=>$nativeVersion,'counts'=>$count,
 'mismatch_count'=>count($errors),'mismatches'=>array_slice($errors,0,20)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
