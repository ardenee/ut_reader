<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$path='H:/Epic Games/UE_4.27/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset';
$bytes=@file_get_contents($path);
if(!is_string($bytes)||strlen($bytes)<30)throw new RuntimeException('Real UE4.27 asset unavailable');
$legacy=-7;$offset=12;
$cases=[
 'valid_original'=>[$bytes,true],
 'future_layout_minus8'=>[substr_replace($bytes,pack('V',0xfffffff8),4,4),false],
 'too_old_version_213'=>[substr_replace($bytes,pack('V',213),$offset,4),false],
 'too_new_version_523'=>[substr_replace($bytes,pack('V',523),$offset,4),false],
 'corrupt_tag'=>[substr_replace($bytes,'FAIL',0,4),false],
 'truncated_summary'=>[substr($bytes,0,16),false],
];
$checks=[];$temporary=tempnam(sys_get_temp_dir(),'ue427_negative_');
try{
 foreach($cases as $name=>[$contents,$shouldPass]){
  file_put_contents($temporary,$contents);
  $reader=new UnrealPackageReader4($temporary);
  $passed=$reader->validatePackage()===[];
  $checks[$name]=['ok'=>$passed===$shouldPass,'observed'=>$passed?'accepted':'rejected','expected'=>$shouldPass?'accepted':'rejected'];
 }
}finally{@unlink($temporary);}
$ok=!in_array(false,array_column($checks,'ok'),true);
echo json_encode(['ok'=>$ok,'real_ue4_asset'=>basename($path),'cases'=>$checks],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
