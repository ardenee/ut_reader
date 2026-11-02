#!/usr/bin/env php
<?php
/** Authentic original Unrealfile v22/23 full UClass/instance/UProperties fixture.
 * Source-of-truth May v0.86x FRotation::operator<< WORD[3] vs July v0.867 INT[3].
 * No independent compiled early-1997 oracle; source-authored regression only.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader as Instance;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertiesReader as Props;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate as Gate;
$root='L:/Source/Games/Unreal/';
$source=[
 Gate::MAY => $root.'Unreal [v0.86x] [1997-05-25]/Src/Inc/UnMath.h',
 Gate::JULY => $root.'Unreal [v0.867] [1997-07-25]/Src/Inc/UnMath.h',
];
$checks=[];$errors=[];$examples=[];
$check=static function(string $id,bool $ok)use(&$checks,&$errors):void{
 $checks[$id]=$ok;if(!$ok)$errors[]=$id;
};
$may=(string)@file_get_contents($source[Gate::MAY]);
$july=(string)@file_get_contents($source[Gate::JULY]);
$check('May_Epic_source_writes_three_WORDs',str_contains($may,'WORD wPitch = R.Pitch;')
    &&str_contains($may,'Ar << wPitch << wYaw << wRoll;')
    &&str_contains($may,'R.Pitch     = wPitch;'));
$check('July_Epic_source_writes_three_INTs',str_contains($july,'return Ar << R.Pitch << R.Yaw << R.Roll;'));
$fixture=static function(int $version,string $lineage):array{
 $names='';foreach(['Class','MyClass','Facing','AfterRotation','Instance','Properties','PropertyBin'] as $n){
     $names.=$n."\0".pack('V',0);
 }
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $classFields=pack('V2',2,2).pack('V5',0,0,0,0,0).pack('V',0)
     .pack('v',0).pack('V3',0,0,0).pack('V8',28,12,0,0,0,0,0,0);
 $classHeader=$stack.$classFields;
 $descriptors=pack('vvV6',3,0,9,0,0,1,12,1)
     .pack('vvV6',4,0,2,12,0,1,4,1);
 $rotation=$lineage===Gate::MAY
     ?pack('v3',65535,32768,42)
     :pack('V3',0xffffffff,0x80000000,42);
 $payload=$rotation.pack('V',0x12345678);
 $instanceHeader=$stack.$payload;
 $propertiesHeader=$stack.pack('V4',16,16,1,1);
 $nameOff=56;$classHeadOff=$nameOff+strlen($names);
 $classDataOff=$classHeadOff+strlen($classHeader);
 $instanceOff=$classDataOff+strlen($descriptors);
 $propertyHeadOff=$instanceOff+strlen($instanceHeader);
 $propertyDataOff=$propertyHeadOff+strlen($propertiesHeader);
 $objOff=$propertyDataOff+strlen($payload);
 $obj=pack('vvVV',2,1,0,$classHeadOff)
    .pack('V4',0,$classDataOff,strlen($classHeader),strlen($descriptors))
    .pack('VvVV',0,0,0,0) // UClass preload record
    .pack('vvVV',5,2,0,$instanceOff)
    .pack('V4',0,0,strlen($instanceHeader),0)
    .pack('vvVV',7,6,0,$propertyHeadOff)
    .pack('V4',0,$propertyDataOff,strlen($propertiesHeader),strlen($payload));
 $summary=pack('a32V6',"Unrealfile\x1A",$version,3,7,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$classHeader.$descriptors
   .$instanceHeader.$propertiesHeader.$payload.$obj,
  'payload'=>$payload,'instance_offset'=>$instanceOff,
  'properties_data_offset'=>$propertyDataOff,
  'expected'=>$lineage===Gate::MAY?[65535,32768,42]:[-1,-2147483648,42]];
};
$temp='C:/Temp/UnrealDB/O06/original-1997-rotation-'.bin2hex(random_bytes(5)).'.original';
try{
 foreach([22,23] as $v)foreach([Gate::MAY,Gate::JULY] as $branch){
   $label="v{$v}_".($branch===Gate::MAY?'may':'july');
   $fx=$fixture($v,$branch);
   file_put_contents($temp,$fx['bytes']);
   $normal=Instance::read($temp,true,[],$branch)['objects'][0]??[];
   $values=$normal['property_values']??[];
   $check($label.'_binary_instance_correct_six_vs_twelve',
     ($normal['status']??'')==='native_binary_properties_decoded'
     &&($normal['source_lineage']??null)===$branch
     &&count($values)===2
     &&($values[0]['name']??'')==='Facing'
     &&($values[0]['values'][0]??null)===$fx['expected']
     &&($values[1]['values']??null)===[0x12345678]);
   $unknown=Instance::read($temp,false)['objects'][0]??[];
   $check($label.'_unidentified_instance_lineage_explicitly_unverified',
     ($unknown['status']??'')==='source_1997_property_lineage_required'
     &&($unknown['property_values']??null)===[]);
   try{Instance::read($temp,true);$reject=false;}
   catch(RuntimeException){$reject=true;}
   $check($label.'_strict_unidentified_instance_fails_closed',$reject);
   $other=$branch===Gate::MAY?Gate::JULY:Gate::MAY;
   try{Instance::read($temp,true,[],$other);$reject=false;}
   catch(RuntimeException){$reject=true;}
   $check($label.'_wrong_explicit_instance_family_rejected',$reject);
   $props=Props::read($temp,true)['properties_objects'][0]??[];
   $fields=$props['property_values']??[];
   $check($label.'_UProperties_exact_bytes_infer_correct_source',
     ($props['status']??'')==='native_UClass_bin_properties_decoded'
     &&($props['source_lineage']??null)===$branch
     &&($props['source_lineage_inferred_from_exact_data_bytes']??false)===true
     &&($props['serialized_bytes_remaining']??-1)===0
     &&($fields[0]['values'][0]??null)===$fx['expected']
     &&($fields[0]['serialized_byte_count']??null)===($branch===Gate::MAY?6:12)
     &&($fields[1]['values']??null)===[0x12345678]);
   $explicit=Props::read($temp,true,[],$branch)['properties_objects'][0]??[];
   $check($label.'_UProperties_explicit_source_matches_exact',
     ($explicit['status']??'')==='native_UClass_bin_properties_decoded'
     &&($explicit['source_lineage_inferred_from_exact_data_bytes']??true)===false);
   try{Props::read($temp,true,[],$other);$reject=false;}
   catch(RuntimeException){$reject=true;}
   $check($label.'_UProperties_wrong_explicit_source_rejected',$reject);
   $examples[]=['case'=>$label,'rotation_bytes'=>$branch===Gate::MAY?6:12,
      'auto_inferred_source'=>$props['source_lineage']??null];
 }
}finally{if(is_file($temp))unlink($temp);}
echo json_encode(['ok'=>$errors===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($errors),'failed'=>$errors,
 'cases'=>$examples,'source_sha256'=>[
    Gate::MAY=>hash_file('sha256',$source[Gate::MAY]),
    Gate::JULY=>hash_file('sha256',$source[Gate::JULY])],
 'limitations'=>'G2 original source-authored v22/23 archive fixture, not a real 1997 compiled oracle; native runtime object fields not materialized.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
