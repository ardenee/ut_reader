<?php
/** Genuine UE4.27 FObjectExport class and compiled redirector DestinationObject comparison.
 * This does NOT instrument VerifyImport/VerifyImportInner or certify second-pass redirection.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$relative=[
 'provider1'=>'Functions/Engine_MaterialFunctions02/MAXScripts/MultiNormalScript_VertexColors',
 'consumer1'=>'Functions/Engine_MaterialFunctions02/MAXScripts/SecondVertNormalSet_Script',
 'provider2'=>'Functions/Engine_MaterialFunctions02/PivotPainter2/ms_PivotPainter2_CalculateCurrentIndex',
 'consumer2'=>'Functions/Engine_MaterialFunctions02/PivotPainter2/ms_PivotPainter2_CalcuateCurrentIndex',
];
$engine='H:/Epic Games/UE_4.27/Engine/Content/';
$errors=[];$seen=[];$targets=[];$expectedCount=0;
foreach($relative as $case=>$rel){
 $package='/Engine/'.$rel;
 $source=$engine.$rel.'.uasset';
 $reader=new UnrealPackageReader4($source);
 if(($issue=$reader->validatePackage())!==[])throw new RuntimeException('Genuine redirector malformed: '.$source.' '.json_encode($issue));
 $imports=$reader->getImports();$exports=$reader->getExports();
 $redirectorExports=[];
 foreach($exports as $i=>$export){
  $classIndex=(int)$export['classIndex'];
  if($classIndex>=0||($imports[-$classIndex-1]['objectNameText']??'')!=='ObjectRedirector')continue;
  $redirectorExports[$i]=$export;
 }
 if(count($redirectorExports)!==1)$errors[]=['source_redirector_count'=>$case,'actual'=>count($redirectorExports)];
 $log='C:/Temp/ue427-p07-redirector-'.$case.'.log';
 $raw=@file_get_contents($log);
 if(!is_string($raw))throw new RuntimeException('Missing compiled UE4.27 log '.$log);
 if(str_starts_with($raw,chr(255).chr(254)))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
 $native=[];
 foreach(explode("\n",$raw) as $line){
  $pos=strpos($line,'UE427_NATIVE_P07_REDIRECTOR|');
  if($pos===false)continue;
  $pieces=explode('|',trim(substr($line,$pos+strlen('UE427_NATIVE_P07_REDIRECTOR|'))));
  if(count($pieces)!==6){$errors[]=['malformed_native'=>$case];continue;}
  [$pkg,$index,$nameHex,$destHex,$classHex,$materialized]=$pieces;
  if(!ctype_xdigit($nameHex)||!ctype_xdigit($destHex)||!ctype_xdigit($classHex))
   {$errors[]=['bad_hex'=>$case];continue;}
  $native[]=['package'=>$pkg,'index'=>(int)$index,'name'=>hex2bin($nameHex),
   'destination'=>hex2bin($destHex),'destination_class'=>hex2bin($classHex),
   'materialized'=>(int)$materialized];
 }
 if(count($native)!==1){$errors[]=['native_redirector_count'=>$case,'actual'=>count($native)];continue;}
 $row=$native[0];$export=$redirectorExports[$row['index']]??null;
 if(!$export||$row['package']!==$package||$row['name']!==($export['objectNameText']??null)
  ||$row['materialized']!==1||$row['destination_class']!=='MaterialFunction')
  $errors[]=['source_native_mismatch'=>$case,'row'=>$row];
 // Verify DestinationObject names a genuine installed material function export.
 $target=$row['destination'];$bits=explode('.',$target,2);
 if(count($bits)!==2||!str_starts_with($bits[0],'/Engine/')){$errors[]=['bad_target'=>$case,$target];continue;}
 $targetPath=$engine.substr($bits[0],strlen('/Engine/')).'.uasset';
 if(!is_file($targetPath)){$errors[]=['missing_real_target_asset'=>$case,$targetPath];continue;}
 $targetReader=new UnrealPackageReader4($targetPath);
 if($targetReader->validatePackage()!==[])throw new RuntimeException('Bad target: '.$targetPath);
 $found=false;foreach($targetReader->getExports() as $targetExport){
  if(($targetExport['objectNameText']??null)===$bits[1]){$found=true;break;}
 }
 if(!$found)$errors[]=['target_export_missing'=>$case,$target];
 if(!str_contains($raw,'UE427_NATIVE_P07_COUNT|1|5|0|2|5'))
  $errors[]=['missing_runtime_completion'=>$case];
 $targets[$case]=$target;
 $seen[$case]=['source'=>$source,'redirector_export'=>$row['index'],
  'redirector_name'=>$row['name'],'destination'=>$target,
  'destination_class'=>$row['destination_class'],
  'target_export_exists'=>$found];
 ++$expectedCount;
}
if(($targets['provider1']??null)!==($targets['consumer1']??null)
 ||($targets['provider2']??null)!==($targets['consumer2']??null)
 ||($targets['provider1']??null)===($targets['provider2']??null))
 $errors[]=['pair_targets_mismatch'=>$targets];
$ok=$errors===[]&&$expectedCount===4;
echo json_encode(['ok'=>$ok,'scope'=>'Genuine serialized ObjectRedirector exports and compiled DestinationObject payloads, NOT VerifyImportInner second-pass proof',
 'genuine_redirector_payloads_compared'=>$expectedCount,'distinct_destinations'=>count(array_unique($targets)),
 'results'=>$seen,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($ok?0:2);
