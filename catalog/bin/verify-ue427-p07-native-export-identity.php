<?php
/** Independently compare Epic compiled FLinker class identity against UE4 tables.
 * This proves export identity *inputs* to VerifyImportInner's candidate hash,
 * NOT that its private match/redirector/outer control flow was executed.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p07-native-export-identity.log';
$raw=@file_get_contents($log);
if(!is_string($raw))throw new RuntimeException('Missing compiled Epic native export log');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$files=[
 '/Engine/Animation/DefaultAnimBoneCompressionSettings'=>
 'H:/Epic Games/UE_4.27/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset',
 '/Engine/ArtTools/RenderToTexture/MaterialFunctions/CheckerPattern'=>
 'H:/Epic Games/UE_4.27/Engine/Content/ArtTools/RenderToTexture/MaterialFunctions/CheckerPattern.uasset'
];
$rows=[];$total=null;$errors=[];
foreach(explode("\n",$raw) as $line){
 $at=strpos($line,'UE427_NATIVE_P07_EXPORT|');
 if($at!==false){
  $v=explode('|',trim(substr($line,$at+strlen('UE427_NATIVE_P07_EXPORT|'))));
  if(count($v)!==8){$errors[]=['bad_native_export'=>$v];continue;}
  if(!isset($files[$v[0]])){$errors[]=['not_in_manifest'=>$v[0]];continue;}
  $idx=(int)$v[1];
  if(isset($rows[$v[0]][$idx]))$errors[]=['duplicate_export'=>$v[0].':'.$idx];
  $rows[$v[0]][$idx]=[
   'classIndex'=>(int)$v[2],
   'className'=>(string)hex2bin($v[3]),
   'classPackage'=>(string)hex2bin($v[4]),
   'objectName'=>(string)hex2bin($v[5]),
   'outerIndex'=>(int)$v[6],
   'objectFlags'=>(int)$v[7],
  ];
 }
 $at=strpos($line,'UE427_NATIVE_P07_EXPORT_COUNT|');
 if($at!==false)$total=(int)trim(substr($line,$at+strlen('UE427_NATIVE_P07_EXPORT_COUNT|')));
}
$counts=['packages'=>0,'exports'=>0,'class_identity_fields'=>0,
 'import_class_indices'=>0,'export_class_indices'=>0,'null_class_indices'=>0,
 'private_exports'=>0,'public_exports'=>0];
foreach($files as $package=>$path){
 $reader=new UnrealPackageReader4($path);
 if($reader->validatePackage()!==[]){$errors[]=['bad_reader'=>$package];continue;}
 $imps=$reader->getImports();$exports=$reader->getExports();
 $counts['packages']++;
 if(count($exports)!==count($rows[$package]??[]))$errors[]=['count_mismatch'=>$package,count($exports),count($rows[$package]??[])];
 foreach($exports as $i=>$item){
  $classIndex=(int)$item['classIndex'];
  if($classIndex<0){
   ++$counts['import_class_indices'];
   $source=$imps[-$classIndex-1]??null;
   $outer=(int)($source['outerIndex']??0);
   $className=(string)($source['objectNameText']??'');
   $classPackage=($outer<0)?(string)($imps[-$outer-1]['objectNameText']??''):
    (($outer>0)?(string)($exports[$outer-1]['objectNameText']??''):'');
  }elseif($classIndex>0){
   ++$counts['export_class_indices'];
   $className=(string)($exports[$classIndex-1]['objectNameText']??'');
   $classPackage=$package;
  }else{
   ++$counts['null_class_indices'];
   $className='Class';$classPackage='/Script/CoreUObject';
  }
  $expected=['classIndex'=>$classIndex,'className'=>$className,
   'classPackage'=>$classPackage,'objectName'=>(string)$item['objectNameText'],
   'outerIndex'=>(int)$item['outerIndex'],'objectFlags'=>(int)$item['objectFlags']];
  $actual=$rows[$package][$i]??null;
  $counts['exports']++;$counts['class_identity_fields']+=6;
  if(((int)$item['objectFlags']&1)!==0)++$counts['public_exports'];else ++$counts['private_exports'];
  if($expected!==$actual&&count($errors)<20)$errors[]=['file'=>$package,'export'=>$i,'expected'=>$expected,'compiled'=>$actual];
 }
}
$ok=$errors===[]&&$total===33&&$counts['packages']===2&&$counts['exports']===33;
echo json_encode(['ok'=>$ok,'scope'=>'Compiled Epic FLinker::GetExportClassName and FLinkerLoad::GetExportClassPackage (candidate class identities), not private VerifyImportInner',
 'counts'=>$counts,'native_export_count'=>$total,'mismatch_count'=>count($errors),'mismatches'=>$errors],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
