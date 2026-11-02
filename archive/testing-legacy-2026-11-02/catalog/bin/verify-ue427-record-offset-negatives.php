<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$path=$argv[1]??'H:/Epic Games/UE_4.27/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset';
$original=@file_get_contents($path);
if(!is_string($original))throw new RuntimeException('Real asset fixture source absent');
$baseline=new UnrealPackageReader4($path);$h=$baseline->getHeader();
if($baseline->validatePackage()!==[]||($h['importCount']??0)<1||($h['exportCount']??0)<1) {
 throw new RuntimeException('Expected valid real asset with both record maps');
}
$pack=static fn(int $value):string=>pack('V',$value & 0xFFFFFFFF);
$pattern=$pack($h['exportCount']).$pack($h['exportOffset']).$pack($h['importCount']).$pack($h['importOffset']).$pack($h['dependsOffset']);
$pos=strpos($original,$pattern);
if($pos===false||$pos>512||strpos($original,$pattern,$pos+1)!==false){
 throw new RuntimeException('Serialized summary offset signature is not unique');
}
$values=[
 'importOffset_zero'=>[$pos+12,0,'Bad import offset'],
 'importOffset_beyond'=>[$pos+12,strlen($original),'Bad import offset'],
 'exportOffset_zero'=>[$pos+4,0,'Bad export offset'],
 'exportOffset_beyond'=>[$pos+4,strlen($original),'Bad export offset'],
 'negative_import_count'=>[$pos+8,-1,'Bad import count'],
 'negative_export_count'=>[$pos,-1,'Bad export count'],
];
$temp=tempnam(sys_get_temp_dir(),'ue427rec-');$cases=[];
try{
 foreach($values as $name=>[$offset,$value,$message]){
  $fixture=substr_replace($original,$pack($value),$offset,4);
  file_put_contents($temp,$fixture);
  $reader=new UnrealPackageReader4($temp);
  $issues=$reader->validatePackage();
  $found=false;
  foreach($issues as $issue)if(str_contains($issue,$message)){$found=true;break;}
  $cases[$name]=['ok'=>$found,'issues'=>array_slice($issues,0,2)];
 }
}finally{@unlink($temp);}
$ok=count($cases)===6&&!in_array(false,array_column($cases,'ok'),true);
echo json_encode(['ok'=>$ok,'source_asset'=>$path,'record_cases'=>count($cases),
 'original_import_count'=>$h['importCount'],'original_export_count'=>$h['exportCount'],
 'cases'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
