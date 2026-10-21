#!/usr/bin/env php
<?php
/** Native v0.867 UClass::SerializeHeader + FProperty data records. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileClassSchemaReader as Schema;

$checks=[];$bad=[];
$check=static function(string $name,bool $ok)use(&$checks,&$bad):void{
 $checks[$name]=$ok;if(!$ok){$bad[]=$name;}
};
$fixture=static function(int $version):array{
    $names='';
    foreach(['Class','MyClass','Score','Enabled','Target','Instance'] as $s){
        $names.=$s."\0".pack('V',0);
    }
    $stack=pack('V2',0,0).str_repeat("\xff",8);
    $classFields=pack('V2',3,3).pack('V5',0,0,0,0,0).pack('V',0)
        .pack('v',0).pack('V3',0,0,0).pack('V8',28,12,0,0,0,0,0,0);
    $classHeader=$stack.$classFields;
    $properties=pack('vvV6',3,0,2,0,0,1,4,1)
        .pack('vvV7',4,0,3,4,0,1,4,1,1)
        .pack('vvV7',5,0,5,8,0,1,4,1,0);
    $instanceHeader=$stack.pack('V3',123456,1,1);
    if(strlen($classFields)!==78||strlen($classHeader)!==94
      ||strlen($properties)!==92||strlen($instanceHeader)!==28){
        throw new RuntimeException('Native source fixture layout mismatch');
    }
    $nameOff=56;
    $classHeaderOff=$nameOff+strlen($names);
    $dataOff=$classHeaderOff+strlen($classHeader);
    $instanceHeaderOff=$dataOff+strlen($properties);
    $objectsOff=$instanceHeaderOff+strlen($instanceHeader);
    $objects=pack('vvVV',2,1,0,$classHeaderOff)
        .pack('V4',0,$dataOff,strlen($classHeader),strlen($properties))
        .pack('VvVV',0,0,0,0) // native UClass preload record
        .pack('vvVV',6,2,0,$instanceHeaderOff)
        .pack('V4',0,0,strlen($instanceHeader),0);
    $summary=pack('a32V6',"Unrealfile\x1A",$version,2,6,$nameOff,$objectsOff,0);
    return ['bytes'=>$summary.$names.$classHeader.$properties.$instanceHeader.$objects,
        'class_header'=>$classHeaderOff,'property_data'=>$dataOff,'objects'=>$objectsOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1o06schema');
if(!is_string($tmp)){throw new RuntimeException('Temporary fixture unavailable');}
try{
 foreach([22,23] as $version){
    $f=$fixture($version);file_put_contents($tmp,$f['bytes']);
    $schema=Schema::read($tmp,true);
    $c=$schema['class_schemas'][0]??[];
    $props=$c['properties']??[];
    $check("v{$version}_native_UClass_header",
        ($c['status']??null)==='native_UClass_schema_decoded'
        && ($c['class_name']??null)==='MyClass'
        && ($c['property_count']??null)===3
        && ($c['property_capacity']??null)===3
        && ($c['full_header_size']??null)===28
        && ($c['this_header_size']??null)===12
        && ($schema['runtime_class_linked']??true)===false);
    $values=\UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader::read($tmp,true);
    $instance=$values['objects'][0]??[];
    $valueRows=$instance['property_values']??[];
    $check("v{$version}_instance_serialized_bin_values",
        ($instance['status']??null)==='native_binary_properties_decoded'
        && count($valueRows)===3
        && $valueRows[0]['name']==='Score'
        && $valueRows[0]['values']===[123456]
        && $valueRows[1]['name']==='Enabled'
        && $valueRows[1]['values']===[true]
        && $valueRows[2]['name']==='Target'
        && $valueRows[2]['values']===[['object_ref'=>1,'object_index'=>0]]
        && ($values['runtime_objects_created']??true)===false);
    $check("v{$version}_FProperty_descriptor_fields",
        count($props)===3
        && $props[0]['name']==='Score'
        && $props[0]['property_type_name']==='Int'
        && $props[0]['bin']===1
        && $props[0]['serialized_bytes']===28
        && $props[1]['name']==='Enabled'
        && $props[1]['property_type_name']==='Bool'
        && $props[1]['bool_bitmask']===1
        && $props[1]['serialized_bytes']===32
        && $props[2]['name']==='Target'
        && $props[2]['property_type_name']==='Object'
        && $props[2]['serialized_bytes']===32);
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $tmp,['format'=>'early_unrealfile','version'=>$version]
    );
    $stored=$stage['native_metadata']['unrealfile_class_schemas']['class_schemas'][0]??[];
    $check("v{$version}_staging_preserves_UClass_descriptors",
        ($stored['property_count']??null)===3
        && ($stored['properties'][1]['name']??null)==='Enabled'
        && ($stage['parse_complete']??true)===false);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('v',7),$f['property_data'],2));
    try{Schema::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$version}_invalid_descriptor_name_rejected",$reject);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',4),$f['class_header']+16,4));
    try{Schema::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$version}_impossible_property_count_rejected",$reject);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',3),$f['property_data']+24,4));
    try{Schema::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$version}_invalid_property_bin_rejected",$reject);
    $instanceOff=$f['property_data']+92;
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',3),$instanceOff+24,4));
    try{
        \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader::read($tmp,true);
        $reject=false;
    }catch(RuntimeException){$reject=true;}
    $check("v{$version}_invalid_binary_object_reference_rejected",$reject);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',3),$instanceOff+16,4));
    $different=\UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader::read($tmp,true);
    $check("v{$version}_changing_serialized_int_changes_only_value",
        ($different['objects'][0]['property_values'][0]['values'][0]??null)===3
        && ($different['objects'][0]['property_values'][1]['values'][0]??null)===true);

 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
