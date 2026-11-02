<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p03-full-native.log';
$manifest=$argv[2]??'C:/Temp/ue427-all-assets.txt';
$raw=@file_get_contents($log);
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($paths))throw new RuntimeException('Missing native record log or manifest.');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
if(isset($paths[0]))$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$counts=[];$imports=[];$exports=[];$nativeErrors=[];$parseErrors=[];
foreach(explode("\n",$raw) as $line){
 foreach(['UE427_NATIVE_RECORD_COUNT|'=>'counts','UE427_NATIVE_IMPORT|'=>'imports','UE427_NATIVE_EXPORT|'=>'exports','UE427_NATIVE_RECORD_ERROR|'=>'errors'] as $tag=>$target){
  $pos=strpos($line,$tag);
  if($pos===false)continue;
  $body=trim(substr($line,$pos+strlen($tag)));
  $fields=explode('|',$body);
  if($target==='errors'){$nativeErrors[]=$body;break;}
  $expected=['counts'=>5,'imports'=>14,'exports'=>28][$target];
  if(count($fields)!==$expected){$parseErrors[]=['target'=>$target,'fields'=>count($fields),'expected'=>$expected,'row'=>$body];break;}
  $fields=array_map('intval',$fields);
  $file=$fields[0];$index=$fields[1]??null;
  if($target==='counts') {
   if(isset($counts[$file]))$parseErrors[]=['duplicate_count'=>$file];
   $counts[$file]=array_slice($fields,1);
  }elseif($target==='imports') {
   if(isset($imports[$file][$index]))$parseErrors[]=['duplicate_import'=>[$file,$index]];
   $imports[$file][$index]=array_slice($fields,2);
  }else{
   if(isset($exports[$file][$index]))$parseErrors[]=['duplicate_export'=>[$file,$index]];
   $exports[$file][$index]=array_slice($fields,2);
  }
  break;
 }
}
$fail=[];$fieldsChecked=0;$importRows=0;$exportRows=0;$versionCounts=[];$recordSizes=[];
$add=function(int $f,string $type,int $idx,string $field,mixed $expected,mixed $actual)use(&$fail,&$fieldsChecked):void{
 $fieldsChecked++;
 if($expected!==$actual && count($fail)<40)$fail[]=['file'=>$f,'table'=>$type,'record'=>$idx,'field'=>$field,'epic'=>$expected,'unrealdb'=>$actual];
};
foreach($paths as $f=>$path){
 if(!isset($counts[$f])){$fail[]=['file'=>$f,'error'=>'missing native record counts'];continue;}
 [$impCount,$expCount,$hasPackageName,$version]=$counts[$f];
 $r=new UnrealPackageReader4($path);
 if($r->validatePackage()!==[]){$fail[]=['file'=>$f,'error'=>'reader issues','issues'=>array_slice($r->validatePackage(),0,2)];continue;}
 $h=$r->getHeader();$pIm=$r->getImports();$pEx=$r->getExports();
 $versionCounts[$version]=($versionCounts[$version]??0)+1;
 $add($f,'summary',0,'importCount',$impCount,count($pIm));
 $add($f,'summary',0,'exportCount',$expCount,count($pEx));
 $add($f,'summary',0,'version',$version,(int)$h['version']);
 $add($f,'summary',0,'packageNamePolicy',$hasPackageName,(int)($version>=520&&(((int)$h['packageFlags']&0x80000000)===0)));
 if(count($imports[$f]??[])!==$impCount||count($exports[$f]??[])!==$expCount){
  $fail[]=['file'=>$f,'error'=>'native table entries missing','importNative'=>count($imports[$f]??[]),'exportNative'=>count($exports[$f]??[])];continue;
 }
 $importSize=$hasPackageName?36:28;
 $exportSize=64+($version>=508?4:0)+($version>=511?8:0)+($version>=365?4:0)+($version>=485?4:0)+($version>=507?20:0);
 foreach($pIm as $i=>$row){
  $v=$imports[$f][$i]??null;
  if(!$v){$fail[]=['file'=>$f,'missingImport'=>$i];continue;}
  [$start,$end,$cp,$cpN,$cn,$cnN,$outer,$on,$onN,$pkgPresent,$pkg,$pkgN]=$v;
  $add($f,'import',$i,'start',$start,(int)$row['offset']);
  $add($f,'import',$i,'end',$end,$start+$importSize);
  $add($f,'import',$i,'classPackage.index',$cp,(int)$row['classPackage']['index']);
  $add($f,'import',$i,'classPackage.number',$cpN,(int)$row['classPackage']['number']);
  $add($f,'import',$i,'className.index',$cn,(int)$row['className']['index']);
  $add($f,'import',$i,'className.number',$cnN,(int)$row['className']['number']);
  $add($f,'import',$i,'outerIndex',$outer,(int)$row['outerIndex']);
  $add($f,'import',$i,'objectName.index',$on,(int)$row['objectName']['index']);
  $add($f,'import',$i,'objectName.number',$onN,(int)$row['objectName']['number']);
  $add($f,'import',$i,'pkgPresent',$pkgPresent,(int)$row['packageNamePresent']);
  if($pkgPresent){
   $add($f,'import',$i,'packageName.index',$pkg,(int)$row['packageName']['index']);
   $add($f,'import',$i,'packageName.number',$pkgN,(int)$row['packageName']['number']);
  }
  $importRows++;
 }
 foreach($pEx as $i=>$row){
  $v=$exports[$f][$i]??null;
  if(!$v){$fail[]=['file'=>$f,'missingExport'=>$i];continue;}
  [$start,$end,$cls,$super,$template,$outer,$obj,$number,$flags,$size,$offset,
   $forced,$client,$server,$gA,$gB,$gC,$gD,$pkgFlags,$notAlways,$isAsset,
   $first,$sbs,$cbs,$sbc,$cbc]=$v;
  $guid=strtoupper(sprintf('%08X-%08X-%08X-%08X',$gA,$gB,$gC,$gD));
  foreach([
   'start'=>[$start,(int)$row['offset']],
   'end'=>[$end,$start+$exportSize],
   'classIndex'=>[$cls,(int)$row['classIndex']],
   'superIndex'=>[$super,(int)$row['superIndex']],
   'templateIndex'=>[$template,(int)$row['templateIndex']],
   'outerIndex'=>[$outer,(int)$row['outerIndex']],
   'name.index'=>[$obj,(int)$row['objectName']['index']],
   'name.number'=>[$number,(int)$row['objectName']['number']],
   'objectFlags'=>[$flags,(int)$row['objectFlags']],
   'serialSize'=>[$size,(int)$row['serialSize']],
   'serialOffset'=>[$offset,(int)$row['serialOffset']],
   'forcedExport'=>[$forced,(int)$row['forcedExport']],
   'notForClient'=>[$client,(int)$row['notForClient']],
   'notForServer'=>[$server,(int)$row['notForServer']],
   'packageGuid'=>[$guid,strtoupper((string)$row['packageGuid'])],
   'packageFlags'=>[$pkgFlags,(int)$row['packageFlags']],
   'notForEditorGame'=>[$notAlways,$version>=365?(int)$row['notForEditorGame']:1],
   'isAsset'=>[$isAsset,$version>=485?(int)$row['isAsset']:0],
   'firstExportDependency'=>[$first,(int)($row['preload']['firstExportDependency']??-1)],
   'serializationBeforeSerializationDependencies'=>[$sbs,(int)($row['preload']['serializationBeforeSerializationDependencies']??0)],
   'createBeforeSerializationDependencies'=>[$cbs,(int)($row['preload']['createBeforeSerializationDependencies']??0)],
   'serializationBeforeCreateDependencies'=>[$sbc,(int)($row['preload']['serializationBeforeCreateDependencies']??0)],
   'createBeforeCreateDependencies'=>[$cbc,(int)($row['preload']['createBeforeCreateDependencies']??0)],
  ] as $field=>[$epic,$php])$add($f,'export',$i,$field,$epic,$php);
  $recordSizes[$exportSize]=($recordSizes[$exportSize]??0)+1;
  $exportRows++;
 }
}
ksort($versionCounts);ksort($recordSizes);
$ok=count($counts)===count($paths)&&$nativeErrors===[]&&$parseErrors===[]&&$fail===[];
echo json_encode(['ok'=>$ok,'files'=>count($paths),'native_count_records'=>count($counts),
 'imports_compared'=>$importRows,'exports_compared'=>$exportRows,'fields_compared'=>$fieldsChecked,
 'version_counts'=>$versionCounts,'export_record_sizes'=>$recordSizes,
 'native_errors'=>array_slice($nativeErrors,0,6),'parse_errors'=>array_slice($parseErrors,0,6),
 'mismatch_count'=>count($fail),'mismatches'=>array_slice($fail,0,25)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
