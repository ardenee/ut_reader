<?php
/** Source-shape/native FObjectImport::PackageName v520 replay (not inner-branch proof). */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$source='H:/Epic Games/UE_4.27/Engine/Content/EngineMaterials/RandomVectors_WithXNeighbor.uasset';
$fixture='C:/Temp/UE427Oracle/Saved/UE427P07Probe/P07';
$expected=['Original'=>['None',19],'ExplicitEngine'=>['/Script/Engine',2],
 'ExplicitCoreUObject'=>['/Script/CoreUObject',1]];
$orig=file_get_contents($source);
if(!is_string($orig))throw new RuntimeException('Missing original genuine asset');
$sourceReader=new UnrealPackageReader4($source);
if($sourceReader->validatePackage()!==[]||(int)$sourceReader->getHeader()['version']!==520
 ||hash('sha256',$orig)!=='452f11bccc43cf1f0901d93de25f25ac7ee3c69147d6dd8a5f78a32cca286970')
 throw new RuntimeException('Unexpected pinned source');
$errors=[];$results=[];$size=strlen($orig);
foreach($expected as $case=>[$name,$idx]){
 $file=$fixture.$case.'.uasset';$bytes=@file_get_contents($file);
 if(!is_string($bytes))throw new RuntimeException('Missing '.$file);
 $reader=new UnrealPackageReader4($file);$import=$reader->getImports()[1]??[];
 $off=(int)($import['offset']??-28)+28;
 $diff=[];for($i=0;$i<min($size,strlen($bytes));++$i)
  if($orig[$i]!==$bytes[$i])$diff[]=$i;
 if($reader->validatePackage()!==[]||strlen($bytes)!==$size||$off!==1307
  ||($import['packageNameText']??null)!==$name||(int)($import['packageName']['index']??-1)!==$idx
  ||unpack('V',substr($bytes,$off,4))[1]!==$idx
  ||$diff!==($case==='Original'?[]:[1307]))
  $errors[]=['fixture_mismatch'=>$case,'changed_offsets'=>$diff,'read_name'=>$import['packageNameText']??null];
 $log='C:/Temp/ue427-p07-pkgname-'.$case.'.log';
 $raw=@file_get_contents($log);
 if(!is_string($raw))throw new RuntimeException('Missing compiled native log: '.$log);
 if(str_starts_with($raw,chr(255).chr(254)))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
 $rows=[];
 foreach(explode("\n",$raw) as $line){
  $at=strpos($line,'UE427_NATIVE_P07_EXPLICIT|');
  if($at===false)continue;
  $parts=explode('|',trim(substr($line,$at+strlen('UE427_NATIVE_P07_EXPLICIT|'))));
  if(count($parts)!==3||!ctype_xdigit($parts[2])){$errors[]=['bad_native_row'=>$case];continue;}
  $rows[]=['file'=>$parts[0],'index'=>(int)$parts[1],'name'=>hex2bin($parts[2])];
 }
 if(count($rows)!==($case==='Original'?0:1)
  ||($case!=='Original'&&($rows[0]['index']!==1||$rows[0]['name']!==$name
    ||$rows[0]['file']!==str_replace('\\','/',$file)))
  ||!str_contains($raw,'UE427_NATIVE_P07_COUNT|1|5|0|3|5'))
  $errors[]=['native_record_mismatch'=>$case,'rows'=>$rows];
 $results[$case]=['package_name'=>$name,'modified_bytes'=>count($diff),
  'native_explicit_name_rows'=>count($rows),'native_loaded_object_refs'=>3];
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'scope'=>'Serialized v520 PackageName native decode and successful whole-package load; NOT VerifyImportInner source-choice proof',
 'source_version'=>520,'source_file_size'=>$size,'package_name_wire_offset'=>1307,
 'cases'=>$results,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($ok?0:2);
