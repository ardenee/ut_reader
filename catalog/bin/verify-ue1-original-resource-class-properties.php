#!/usr/bin/env php
<?php
/** UE1 original 1996 UClass native FClassProperty data records. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceClassPropertyReader as ClassTable;
$checks=[];$bad=[];$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{
 $checks[$n]=$ok;if(!$ok)$bad[]=$n;
};
$make=static function(int $v):array{
 $prefix="Unreal Resource\x1A\0";
 $names=pack('a32V','CLASS',0);$nameOff=strlen($prefix);
 $records=pack('v2V7',1,2,1,12,0x100,1,1,0,3)
   .pack('v2V7',3,2,3,16,0,1,4,0,0x10)
   .pack('v2V7',4,2,5,20,0,1,2,1,7);
 if(strlen($records)!==96)throw new RuntimeException('Source FClassProperty record fixture mismatch');
 $dataOff=$nameOff+strlen($names);
 $exOff=$dataOff+strlen($records);
 $export=pack('a32VVV','ClassOne',1,10,84);
 $headerOff=$exOff+strlen($export);
 $header=pack('V2a32V9',0,10,'ClassOne',0,0,1,0xffffffff,0,$dataOff,96,0,0)
   .pack('V2',3,4);
 $tail=pack('V9',$v,1,0,1,$nameOff,0,$exOff,$headerOff,84)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$records.$export.$header.'[SUMMARY]'.$tail,
     'data'=>$dataOff,'header'=>$headerOff,'export'=>$exOff];
};
$temp=tempnam(sys_get_temp_dir(),'ue1class96');
if(!is_string($temp))throw new RuntimeException('No class property fixture');
try{
 foreach([9,10] as $v){
   $f=$make($v);file_put_contents($temp,$f['bytes']);
   $tab=ClassTable::read($temp,true)['class_property_tables'][0]??[];
   $props=$tab['properties']??[];
   $check("v{$v}_native_fclassproperty_records",
     ($tab['status']??null)==='native_UClass_property_records_decoded'
     && ($tab['property_count']??null)===3
     && ($tab['property_capacity']??null)===4
     && ($tab['property_record_bytes']??null)===32
     && ($tab['stored_mode']??null)==='QueryMinSize'
     && count($props)===3);
   $check("v{$v}_typed_source_union_values",
     ($props[0]['type_name']??null)==='Byte'
     && ($props[0]['enum_reference_raw_word']??null)===3
     && ($props[0]['name_index_raw_word']??null)===1
     && ($props[1]['type_name']??null)==='Boolean'
     && ($props[1]['boolean_bitmask']??null)===0x10
     && ($props[2]['type_name']??null)==='Actor'
     && ($props[2]['actor_class_reference_raw_word']??null)===7
     && ($tab['default_actor_header_decoded']??true)===false);
   $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $temp,['format'=>'early_unreal_resource','version'=>$v]
   );
   $record=$stage['native_metadata']['resource_class_property_tables']['class_property_tables'][0]??[];
   $check("v{$v}_staging_does_not_claim_default_actor",
     ($record['property_count']??null)===3
     && ($record['class_member_header_decoded']??true)===false
     && ($stage['parse_complete']??true)===false);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',11),$f['data']+4,4));
   try{ClassTable::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$v}_unknown_property_type_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',385),$f['header']+80,4));
   try{ClassTable::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$v}_too_many_class_properties_rejected",$reject);
   file_put_contents($temp,substr_replace($f['bytes'],pack('V',95),$f['header']+64,4));
   try{ClassTable::read($temp,true);$reject=false;}catch(RuntimeException){$reject=true;}
   $check("v{$v}_wrong_data_byte_count_rejected",$reject);
 }
}finally{@unlink($temp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
