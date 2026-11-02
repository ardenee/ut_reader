<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p04-full-native.log';
$list=$argv[2]??'C:/Temp/ue427-all-assets.txt';
$raw=@file_get_contents($log);
$paths=@file($list,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($paths))throw new RuntimeException('Missing native P04 log or corpus manifest');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
if(isset($paths[0]))$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$counts=[];$depends=[];$soft=[];$preload=[];$errors=[];
foreach(explode("\n",$raw) as $line) {
 foreach(['UE427_NATIVE_P04_COUNT|'=>'counts','UE427_NATIVE_DEPENDS|'=>'depends',
          'UE427_NATIVE_SOFT|'=>'soft','UE427_NATIVE_PRELOAD|'=>'preload',
          'UE427_NATIVE_P04_ERROR|'=>'error'] as $tag=>$type){
  $at=strpos($line,$tag);
  if($at===false)continue;
  $record=explode('|',trim(substr($line,$at+strlen($tag))));
  if($type==='error'){$errors[]=['native_error'=>$record];break;}
  $width=['counts'=>8,'depends'=>5,'soft'=>7,'preload'=>4][$type];
  if(count($record)!==$width){$errors[]=['bad_row_width'=>$type,'found'=>count($record),'record'=>$record];break;}
  $file=(int)$record[0];
  if($type==='counts') {
   if(isset($counts[$file]))$errors[]=['duplicate_count'=>$file];
   $counts[$file]=array_map('intval',array_slice($record,1));
  } elseif($type==='depends'){
   $row=(int)$record[1];if(isset($depends[$file][$row]))$errors[]=['duplicate_depends'=>[$file,$row]];
   $values=$record[4]===''?[]:array_map('intval',explode(',',$record[4]));
   $depends[$file][$row]=['offset'=>(int)$record[2],'count'=>(int)$record[3],'references'=>$values];
  } elseif($type==='soft'){
   $row=(int)$record[1];if(isset($soft[$file][$row]))$errors[]=['duplicate_soft'=>[$file,$row]];
   $text=hex2bin($record[6]);if($text===false){$errors[]=['invalid_hex'=>[$file,$row]];break;}
   $soft[$file][$row]=['offset'=>(int)$record[2],'format'=>(int)$record[3],
     'name_index'=>(int)$record[4],'number'=>(int)$record[5],'path'=>$text];
  } else {
   $row=(int)$record[1];if(isset($preload[$file][$row]))$errors[]=['duplicate_preload'=>[$file,$row]];
   $preload[$file][$row]=['offset'=>(int)$record[2],'ref'=>(int)$record[3]];
  }
  break;
 }
}

$fail=[];$fieldCount=0;$totalSoft=0;$totalPreload=0;$totalDependsRows=0;$totalDependsRefs=0;
$versions=[];$legacySoft=0;$modernSoft=0;
$cmp=static function(int $f,string $table,int $row,string $field,mixed $native,mixed $php) use (&$fail,&$fieldCount):void {
 $fieldCount++;
 if($native!==$php && count($fail)<50)$fail[]=['file'=>$f,'table'=>$table,'row'=>$row,'field'=>$field,'epic'=>$native,'unrealdb'=>$php];
};
foreach($paths as $f=>$path){
 if(!isset($counts[$f])){$fail[]=['file'=>$f,'issue'=>'missing_native_count'];continue;}
 [$exports,$dependsOffset,$softCount,$softOffset,$preloadCount,$preloadOffset,$version]=$counts[$f];
 $reader=new UnrealPackageReader4($path);
 $header=$reader->getHeader();
 if($reader->validatePackage()!==[]){$fail[]=['file'=>$f,'issues'=>array_slice($reader->validatePackage(),0,3)];continue;}
 foreach(['exportCount'=>$exports,'dependsOffset'=>$dependsOffset,
          'stringAssetReferencesCount'=>$softCount,'stringAssetReferencesOffset'=>$softOffset,
          'preloadDependencyCount'=>$preloadCount,'preloadDependencyOffset'=>$preloadOffset,
          'version'=>$version] as $field=>$native) {
  $cmp($f,'summary',0,$field,$native,(int)($header[$field]??0));
 }
 $versions[(string)$version]=($versions[(string)$version]??0)+1;
 $nativeDepends=$depends[$f]??[];$phpDepends=$reader->getDependsMap();
 $expectedDependsRows=$dependsOffset>0?$exports:0;
 $cmp($f,'depends_count',0,'rows',$expectedDependsRows,count($nativeDepends));
 $cmp($f,'depends_count',0,'php_rows',$expectedDependsRows,count($phpDepends));
 foreach($phpDepends as $i=>$row){
  $n=$nativeDepends[$i]??null;
  if($n===null){$fail[]=['file'=>$f,'missing_depends_row'=>$i];continue;}
  $cmp($f,'depends',$i,'offset',$n['offset'],(int)($row['offset']??-1));
  $cmp($f,'depends',$i,'refs_count',$n['count'],count($row['references']??[]));
  $cmp($f,'depends',$i,'refs',$n['references'],$row['references']??[]);
  $totalDependsRows++;$totalDependsRefs+=count($n['references']);
 }
 $nativePreload=$preload[$f]??[];$phpPreload=$reader->getPreloadDependencies();
 $expectedPreload=$preloadCount>0&&$preloadOffset>0?$preloadCount:0;
 $cmp($f,'preload_count',0,'native',$expectedPreload,count($nativePreload));
 $cmp($f,'preload_count',0,'php',$expectedPreload,count($phpPreload));
 foreach($phpPreload as $i=>$row){
  $n=$nativePreload[$i]??null;if($n===null){$fail[]=['file'=>$f,'missing_preload'=>$i];continue;}
  $cmp($f,'preload',$i,'offset',$n['offset'],(int)($row['offset']??-1));
  $cmp($f,'preload',$i,'ref',$n['ref'],(int)($row['ref']??-10000000));
  $totalPreload++;
 }

 $nativeSoft=$soft[$f]??[];$phpSoft=$reader->getStringAssetReferences();
 $expectedSoft=$softCount>0&&$softOffset>0?$softCount:0;
 $cmp($f,'soft_count',0,'native',$expectedSoft,count($nativeSoft));
 $cmp($f,'soft_count',0,'php_nonempty',$expectedSoft,count($phpSoft));
 foreach($nativeSoft as $i=>$n){
  $row=$phpSoft[$i]??null;
  if($row===null){$fail[]=['file'=>$f,'missing_soft'=>$i];continue;}
  $cmp($f,'soft',$i,'offset',$n['offset'],(int)($row['offset']??-1));
  $cmp($f,'soft',$i,'text',$n['path'],(string)($row['path']??''));
  $cmp($f,'soft',$i,'format',$n['format']?'fstring':'fname',(string)($row['wireFormat']??''));
  if($n['format'])$legacySoft++;else $modernSoft++;
  $totalSoft++;
 }
}
ksort($versions,SORT_NUMERIC);
$ok=count($counts)===count($paths)&&$errors===[]&&$fail===[];
echo json_encode([
 'ok'=>$ok,'packages'=>count($paths),'native_count_records'=>count($counts),
 'depends_rows_compared'=>$totalDependsRows,'depends_refs_compared'=>$totalDependsRefs,
 'preload_entries_compared'=>$totalPreload,'soft_entries_compared'=>$totalSoft,
 'legacy_fstring_soft_entries'=>$legacySoft,'modern_fname_soft_entries'=>$modernSoft,
 'compared_fields'=>$fieldCount,'versions'=>$versions,'native_errors'=>array_slice($errors,0,10),
 'mismatch_count'=>count($fail),'mismatches'=>array_slice($fail,0,25)
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
