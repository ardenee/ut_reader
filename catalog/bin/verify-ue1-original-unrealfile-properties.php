#!/usr/bin/env php
<?php
/** Source-defined native Unrealfile v22/v23 UProperties::SerializeBin. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertiesReader as Props;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate as Gate;
$checks=[];$bad=[];$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{
 $checks[$n]=$ok;if(!$ok)$bad[]=$n;
};
$make=static function(int $ver):array{
 $labels=['Class','TestClass','Properties','PropertyBin',
   'Count','Enabled','Label','Rots','Vec'];
 $names='';foreach($labels as $name)$names.=$name."\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $classFields=pack('V2',5,5).pack('V5',0,0,0,0,0).pack('V',0)
   .pack('v',0).pack('V3',0,0,0).pack('V8',28,12,0,0,0,0,0,0);
 $classHeader=$stack.$classFields;
 $defs=pack('vvV6',5,0,2,0,0,1,4,1)
   .pack('vvV7',6,0,3,4,0,1,4,1,1)
   .pack('vvV6',7,0,7,8,0,1,16,1)
   .pack('vvV6',8,0,9,24,0,1,12,1)
   .pack('vvV6',9,0,8,36,0,1,12,1);
 $data=pack('V2',0xffffffff,1)."Hi\0"
   .pack('V3',0xffffffff,2,3)
   .pack('g3',1.25,-2.0,3.5);
 $propertyHeader=$stack.pack('V4',48,64,1,1);
 if(strlen($classHeader)!==94||strlen($defs)!==144||
     strlen($propertyHeader)!==32||strlen($data)!==35)
     throw new RuntimeException('Bad v0.867 UProperties test layout');
 $nameOff=56;$classHeadOff=$nameOff+strlen($names);
 $classDataOff=$classHeadOff+strlen($classHeader);
 $propHeadOff=$classDataOff+strlen($defs);
 $propDataOff=$propHeadOff+strlen($propertyHeader);
 $objOff=$propDataOff+strlen($data);
 $table=pack('vvVV',2,1,0,$classHeadOff)
      .pack('V4',0,$classDataOff,94,144)
      .pack('VvVV',0,0,0,0) // native UClass preload
      .pack('vvVV',4,3,0,$propHeadOff)
      .pack('V4',0,$propDataOff,32,strlen($data));
 $summary=pack('a32V6',"Unrealfile\x1A",$ver,2,count($labels),
   $nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$classHeader.$defs.$propertyHeader.$data.$table,
   'head'=>$propHeadOff,'data'=>$propDataOff,'table'=>$objOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1props97');
if(!is_string($tmp))throw new RuntimeException('No temp data for native properties');
try{
 foreach([22,23] as $ver){
  $fx=$make($ver);file_put_contents($tmp,$fx['bytes']);
  $out=Props::read($tmp,true,[],
     \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY)['properties_objects'][0]??[];
  $values=$out['property_values']??[];
  $check("v{$ver}_native_UClass_bin_stream",
    ($out['status']??null)==='native_UClass_bin_properties_decoded'
    && ($out['class_reference_raw']??null)===1
    && ($out['property_bin']??null)===1
    && ($out['property_memory_bytes']??null)===48
    && ($out['property_capacity_bytes']??null)===64
    && ($out['serialized_bytes_remaining']??null)===0
    && ($out['runtime_class_preload_evaluated']??true)===false
    && count($values)===5);
  $check("v{$ver}_typed_class_property_data",
    ($values[0]['values']??null)===[-1]
    && ($values[1]['values']??null)===[true]
    && ($values[2]['values']??null)===[['ansi_bytes_hex'=>'4869','source_max_length'=>16]]
    && ($values[3]['values']??null)===[[-1,2,3]]
    && ($values[4]['values']??null)===[[1.25,-2.0,3.5]]);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
     $tmp,['format'=>'early_unrealfile','version'=>$ver,
       'source_lineage'=>Gate::JULY]
  );
  $s=$stage['native_metadata']['unrealfile_properties_bin']['properties_objects'][0]??[];
  $check("v{$ver}_original_staging_not_runtime",
    ($s['class_schema_object_index']??null)===0
    && ($s['serialized_bytes_consumed']??null)===35
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',3),$fx['head']+24,4));
  try{Props::read($tmp,true,[],
     \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_invalid_class_resmap_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',3),$fx['head']+28,4));
  try{Props::read($tmp,true,[],
     \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_invalid_property_bin_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',34),$fx['table']+66,4));
  try{Props::read($tmp,true,[],
     \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_truncated_serialized_bin_rejected",$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['head']+24,4));
  $unresolved=Props::read($tmp,false,[],
     \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY)['properties_objects'][0]??[];
  $check("v{$ver}_unavailable_external_class_not_fabricated",
    ($unresolved['status']??null)==='class_schema_unavailable_or_external'
    && ($unresolved['property_values']??null)===[]);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
