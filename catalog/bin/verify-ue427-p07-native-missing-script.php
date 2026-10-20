<?php
/** Actual compiled UE4.27 LoadPackage missing native-script provider negative.
 * Both source and mutant are genuine-package byte identities except the
 * disposable fixed-width FName payload substitution /Script/Engine=>EnginZ.
 * No claim of directly invoking private VerifyImportInner.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$base='C:/Temp/UE427Oracle/Saved/UE427P07Probe/DefaultAnimBoneCompressionSettings.uasset';
$mutant='C:/Temp/UE427Oracle/Saved/UE427P07Probe/MissingNativeScript.uasset';
$logs=[
 'control'=>'C:/Temp/ue427-p07-path-probe.log',
 'missing'=>'C:/Temp/ue427-p07-missing-native-runtime.log',
];
$errors=[];$observed=[];
foreach($logs as $key=>$file){
 $log=@file_get_contents($file);
 if(!is_string($log))throw new RuntimeException('Missing actual native execution log: '.$file);
 if(str_starts_with($log,"\xFF\xFE"))$log=mb_convert_encoding(substr($log,2),'UTF-8','UTF-16LE');
 $countRows=[];
 if(preg_match_all('~UE427_NATIVE_P07_COUNT\|(\d+)\|(\d+)\|(\d+)\|(\d+)\|(\d+)~',$log,$match,PREG_SET_ORDER)){
  foreach($match as $m)$countRows[]=array_map('intval',array_slice($m,1,5));
 }
 if(count($countRows)!==1)$errors[]=['file'=>$key,'count_rows'=>$countRows];
 $loaded=0;$imports=[];
 foreach(explode("\n",$log) as $line){
  $at=strpos($line,'UE427_NATIVE_P07_IMPORT|');if($at===false)continue;
  $r=explode('|',trim(substr($line,$at+strlen('UE427_NATIVE_P07_IMPORT|'))));
  if(count($r)!==9){$errors[]=['file'=>$key,'bad_native_import'=>$r];continue;}
  $index=(int)$r[1];$imports[$index]=$r;
  if((int)$r[7]===1)++$loaded;
 }
 $reader=new UnrealPackageReader4($key==='control'?$base:$mutant);
 if($reader->validatePackage()!==[])$errors[]=['file'=>$key,'php_error'=>$reader->validatePackage()];
 $wire=$reader->getImports();
 if(count($wire)!==7||count($imports)!==7)$errors[]=['file'=>$key,'wrong_import_count'=>[count($wire),count($imports)]];
 foreach($wire as $idx=>$row){
  $r=$imports[$idx]??null;
  if($r===null)continue;
  if([(string)$idx,$row['classPackageText'],$row['classNameText'],$row['objectNameText'],(string)$row['outerIndex']]
    !==array_slice($r,1,5))
   $errors[]=['file'=>$key,'import_index'=>$idx,'wire_runtime_identity_mismatch'=>true];
 }
 $observed[$key]=[
  'native_counts'=>$countRows[0]??null,'matched_serialized_imports'=>count($imports),
  'loaded_object_refs'=>$loaded,
  'missing_script_warnings'=>preg_match_all('~Failed to load .?/Script/EnginZ.?~',$log),
  'missing_class_warnings'=>preg_match_all('~because its class does not exist~i',$log),
  'log'=>$file,
 ];
}
$source='H:/Epic Games/UE_4.27/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset';
$orig=@file_get_contents($source);$new=@file_get_contents($mutant);
$mutations=0;
if(is_string($orig)&&is_string($new)&&strlen($orig)===strlen($new)){
 for($i=0,$n=strlen($orig);$i<$n;$i++)if($orig[$i]!==$new[$i])$mutations++;
}
$baseline=$observed['control']['native_counts']??null;
$missing=$observed['missing']['native_counts']??null;
$ok=$errors==[]&&$baseline===[1,7,0,5,7]&&$missing===[1,7,0,1,7]
 &&$observed['control']['loaded_object_refs']===5
 &&$observed['missing']['loaded_object_refs']===1
 &&$observed['control']['missing_script_warnings']===0
 &&$observed['missing']['missing_script_warnings']>0
 &&$observed['missing']['missing_class_warnings']>0
 &&$mutations===1 &&strlen((string)$orig)===4861;
echo json_encode(['ok'=>$ok,'scope'=>'Compiled Epic UE4 LoadPackage failure to find mutated native script package, controlled original/mutant real asset',
 'file_size'=>strlen((string)$orig),'changed_single_byte_count'=>$mutations,
 'baseline'=>$observed['control'],'negative'=>$observed['missing'],
 'errors'=>$errors,'caveat'=>'Observes original engine missing-provider/loaded-object consequence; not direct call or branch trace of private VerifyImportInner'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
