<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
$asset='H:/UE5-583-runtime-probe/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset';
$reader=new UnrealPackageReader5($asset);
if($reader->validatePackage()!==[]||$reader->getExports()===[])throw new RuntimeException('Native UE5 fixture not available');
$raw=file_get_contents($asset);if(!is_string($raw))throw new RuntimeException('Cannot open asset');
$exports=$reader->getExports();$first=$exports[0];
$flagOffset=(int)$first['offset']+24; // modern UE4/UE5: Class/Super/Template/Outer FPackageIndex + FName (8)
if($flagOffset+4>strlen($raw))throw new RuntimeException('Export flags offset outside header');
$wire=unpack('V',substr($raw,$flagOffset,4))[1];
if($wire!==$first['serializedObjectFlags'])throw new RuntimeException('Flag fixture layout differs from UE5 source');
$nonload=0x40; // RF_Transient, explicitly absent from ObjectMacros.h RF_Load
$temp=sys_get_temp_dir().'/ue583-s001-'.getmypid().'.uasset';
try{
 $modified=substr_replace($raw,pack('V',$wire|$nonload),$flagOffset,4);
 if(file_put_contents($temp,$modified)!==strlen($modified))throw new RuntimeException('Cannot write fixture');
 $mutated=new UnrealPackageReader5($temp);
 if($mutated->validatePackage()!==[])throw new RuntimeException('Mutated fixture invalid: '.json_encode($mutated->validatePackage()));
 $row=$mutated->getExports()[0];
 $valid=($row['objectFlags']??null)===(($wire|$nonload)&0xA2D4083B)
   && ($row['serializedObjectFlags']??null)===($wire|$nonload)
   && ($row['objectFlags']??null)===($first['objectFlags']??null);
 echo json_encode(['ok'=>$valid,'epic_rf_load_mask'=>'A2D4083B','original_serialized'=>sprintf('%08X',$wire),'injected_serialized'=>sprintf('%08X',$row['serializedObjectFlags']),'effective'=>sprintf('%08X',$row['objectFlags']),'transient_mask_discarded'=>($row['objectFlags']&0x40)===0],JSON_PRETTY_PRINT),PHP_EOL;
 exit($valid?0:2);
}finally{if(is_file($temp))unlink($temp);}
