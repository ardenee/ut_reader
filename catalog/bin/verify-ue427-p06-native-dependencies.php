<?php
/** Compare Epic's compiled TBitArray serialization to UnrealDB's P06 reader. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$log=$argv[2]??'C:/Temp/ue427-p06-deps-native.log';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$raw=@file_get_contents($log);
if(!is_array($paths)||!is_string($raw))throw new RuntimeException('Missing native log or manifest');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$native=[];$endMarker=false;$errors=[];
foreach(explode("\n",$raw) as $line){
 if(str_contains($line,'UE427_NATIVE_COUNT 1749'))$endMarker=true;
 $p=strpos($line,'UE427_NATIVE_P06_DEP|');
 if($p===false)continue;
 $f=explode('|',trim(substr($line,$p+strlen('UE427_NATIVE_P06_DEP|'))));
 if(count($f)!==7){$errors[]=['malformed_epic_row'=>$f];continue;}
 $idx=(int)$f[0];
 if(isset($native[$idx])){$errors[]=['duplicate_index'=>$idx];continue;}
 $native[$idx]=[
   'offset'=>(int)$f[1],
   'imports'=>(int)$f[2],
   'importBits'=>$f[3],
   'soft'=>(int)$f[4],
   'softBits'=>$f[5],
   'end'=>(int)$f[6],
 ];
}
$count=['packages'=>0,'native_dependency_sections'=>count($native),'php_dependency_sections'=>0,
 'import_bits'=>0,'soft_bits'=>0,'import_true'=>0,'soft_true'=>0,
 'pre_dependency_sections'=>0,'bit_fields_compared'=>0];
if(count($paths)!==1749 || !$endMarker)$errors[]=['incomplete_native_corpus'=>count($paths),'endMarker'=>$endMarker];
foreach($paths as $i=>$path){
 $r=new UnrealPackageReader4($path);
 $count['packages']++;
 if($r->validatePackage()!==[]){if(count($errors)<25)$errors[]=['file'=>$i,'reader_issues'=>$r->validatePackage()];continue;}
 $dep=$r->getAssetRegistryDependencyBits();
 if($dep===[]){
  $count['pre_dependency_sections']++;
  if(isset($native[$i])&&count($errors)<25)$errors[]=['file'=>$i,'unexpected_native_bits'=>$native[$i]];
  continue;
 }
 $count['php_dependency_sections']++;
 if(!isset($native[$i])){if(count($errors)<25)$errors[]=['file'=>$i,'missing_native_bits'=>true];continue;}
 $n=$native[$i];
 $imp=$dep['importUsedInGame'];$soft=$dep['softPackageUsedInGame'];
 $count['import_bits']+=count($imp);$count['soft_bits']+=count($soft);
 $count['import_true']+=count(array_filter($imp));$count['soft_true']+=count(array_filter($soft));
 $phpImp=implode('',array_map(static fn(bool $b):string=>$b?'1':'0',$imp));
 $phpSoft=implode('',array_map(static fn(bool $b):string=>$b?'1':'0',$soft));
 $actual=[
  'offset'=>$dep['offset'],'imports'=>count($imp),'importBits'=>$phpImp,
  'soft'=>count($soft),'softBits'=>$phpSoft,'end'=>$dep['endOffset']
 ];
 if($actual!==$n&&count($errors)<25)$errors[]=['file'=>$i,'native'=>$n,'reader'=>$actual];
 $count['bit_fields_compared']+=count($imp)+count($soft)+4;
 if(count($imp)!==count($r->getImports())&&count($errors)<25)
   $errors[]=['file'=>$i,'bad_import_count'=>[count($imp),count($r->getImports())]];
 if(count($soft)!==(int)($r->getHeader()['stringAssetReferencesCount']??-1)&&count($errors)<25)
   $errors[]=['file'=>$i,'bad_soft_count'=>[count($soft),$r->getHeader()['stringAssetReferencesCount']??null]];
}
$ok=count($native)===35&&$count['php_dependency_sections']===35&&$errors===[];
echo json_encode(['ok'=>$ok,'scope'=>'Compiled Epic UE4 TBitArray dependency bit payload parity',
 'counts'=>$count,'mismatch_count'=>count($errors),'mismatches'=>array_slice($errors,0,25)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
