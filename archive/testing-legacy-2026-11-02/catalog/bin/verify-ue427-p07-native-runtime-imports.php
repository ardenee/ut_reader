<?php
/**
 * Source/native P07 bounded runtime evidence. This is NOT a certification
 * of file-backed VerifyImportInner matching: after actual compiled LoadPackage
 * it is normal for native/script imports to have XObject but no SourceIndex.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p07-native-full-load.log';
$raw=@file_get_contents($log);
if(!is_string($raw))throw new RuntimeException('Missing compiled Epic P07 runtime log');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$paths=[
 '/Engine/Animation/DefaultAnimBoneCompressionSettings'=>
   'H:/Epic Games/UE_4.27/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset',
 '/Engine/ArtTools/RenderToTexture/MaterialFunctions/CheckerPattern'=>
   'H:/Epic Games/UE_4.27/Engine/Content/ArtTools/RenderToTexture/MaterialFunctions/CheckerPattern.uasset',
];
$rows=[];$packageHeaders=[];$count=null;$errors=[];
foreach(explode("\n",$raw) as $line){
 foreach(['UE427_NATIVE_P07_PACKAGE|'=>'package','UE427_NATIVE_P07_IMPORT|'=>'import','UE427_NATIVE_P07_COUNT|'=>'count'] as $marker=>$kind){
  $at=strpos($line,$marker);if($at===false)continue;
  $parts=explode('|',trim(substr($line,$at+strlen($marker))));
  if($kind==='package'){
   if(count($parts)!==3)$errors[]=['malformed_package'=>$parts];
   else $packageHeaders[$parts[0]]=[(int)$parts[1],(int)$parts[2]];
  }elseif($kind==='import'){
   if(count($parts)!==9)$errors[]=['malformed_import'=>$parts];
   elseif(isset($rows[$parts[0]][(int)$parts[1]]))$errors[]=['duplicate_import'=>$parts[0].':'.$parts[1]];
   else $rows[$parts[0]][(int)$parts[1]]=$parts;
  }else{
   if(count($parts)!==5)$errors[]=['malformed_total'=>$parts];
   else $count=array_map('intval',$parts);
  }
  break;
 }
}
$fields=0;$imports=0;$loaded=0;$linked=0;
foreach($paths as $packageName=>$path){
 $reader=new UnrealPackageReader4($path);
 $names=$reader->getImports();
 if($reader->validatePackage()!==[])$errors[]=['php_reader_issues'=>$packageName];
 $header=$packageHeaders[$packageName]??null;
 if($header!==[1,count($names)])$errors[]=['package_count_mismatch'=>$packageName,'native'=>$header,'php'=>count($names)];
 foreach($names as $i=>$name){
  $row=$rows[$packageName][$i]??null;
  if(!$row){$errors[]=['missing_runtime_row'=>$packageName,'index'=>$i];continue;}
  $expected=[
   $packageName,(string)$i,
   (string)$name['classPackageText'],(string)$name['classNameText'],
   (string)$name['objectNameText'],(string)$name['outerIndex']
  ];
  if(array_slice($row,0,6)!==$expected)$errors[]=['identity_mismatch'=>$packageName,'index'=>$i,'expected'=>$expected,'actual'=>array_slice($row,0,6)];
  $sourceIndex=(int)$row[6];
  $xObject=(int)$row[7];
  if($xObject!==0&&$xObject!==1)$errors[]=['invalid_xobject_flag'=>$row];
  if($sourceIndex>=0)$linked++;
  if($xObject===1)$loaded++;
  $imports++;$fields+=5;
 }
}
$ok=$errors===[] && $count===[2,22,0,16,22]&&$imports===22&&$linked===0&&$loaded===16;
echo json_encode(['ok'=>$ok,'scope'=>'Real compiled UE4 LoadPackage and full linker LoadAllObjects on 2 genuine Engine packages',
 'packages_checked'=>count($packageHeaders),'import_rows_compared'=>$imports,
 'serialized_identity_fields_compared'=>$fields,'runtime_xobject_loaded'=>$loaded,
 'postload_source_linker_rows'=>$linked,
 'important_boundary'=>'Native XObject visibility after fully loaded package is NOT deterministic file-backed VerifyImportInner provider/source-index evidence',
 'native_counts'=>$count,'issues'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
