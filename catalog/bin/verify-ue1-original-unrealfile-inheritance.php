#!/usr/bin/env php
<?php
/**
 * Original 1997 UClass::SerializeBin: child-first FPropertyIterator
 * and local ResMap parent UClass lookup. No original runtime oracle.
 *
 * Epic v0.867 UnClass.cpp:725-752, UnClass.h::FPropertyIterator;
 * UnProp.cpp::FProperty::SerializeProperty, UnLinker.h object map.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader as Values;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileClassSchemaReader as Schemas;

$checks=[];$failed=[];
$check=static function(string $id,bool $yes)use(&$checks,&$failed):void{
    $checks[$id]=$yes;
    if(!$yes)$failed[]=$id;
};
$fixture=static function(int $version):array{
    $nameList=['Class','BaseClass','DerivedClass','BaseScore','DerivedFlag','Instance'];
    $names='';
    foreach($nameList as $name) $names.=$name."\0".pack('V',0);
    $stack=pack('V2',0,0).str_repeat("\xff",8);
    $classFields=static function(int $num,int $parentRef):string{
        return pack('V2',$num,$num).pack('V5',$parentRef,0,0,0,0)
             .pack('V',0).pack('v',0).pack('V3',0,0,0)
             .pack('V8',28,12,0,0,0,0,0,0);
    };
    $baseHeader=$stack.$classFields(1,0);
    $derivedHeader=$stack.$classFields(1,1);
    $baseData=pack('vvV6',4,0,2,0,0,1,4,1);
    $derivedData=pack('vvV7',5,0,3,4,0,1,4,1,1);
    $instanceHeader=$stack.pack('V2',1,77); // Child Bool first, Base Int second.
    if(strlen($baseHeader)!==94||strlen($derivedHeader)!==94
        ||strlen($baseData)!==28||strlen($derivedData)!==32||strlen($instanceHeader)!==24) {
        throw new RuntimeException('1997 inheritance fixture differs from native sizes');
    }
    $namesOff=56;
    $baseHeaderOff=$namesOff+strlen($names);
    $baseDataOff=$baseHeaderOff+strlen($baseHeader);
    $derivedHeaderOff=$baseDataOff+strlen($baseData);
    $derivedDataOff=$derivedHeaderOff+strlen($derivedHeader);
    $instanceHeaderOff=$derivedDataOff+strlen($derivedData);
    $objectOff=$instanceHeaderOff+strlen($instanceHeader);
    $classObject=static function(int $n,int $head,int $data,int $dataSize):string{
        return pack('vvVV',$n,1,0,$head)
            .pack('V4',0,$data,94,$dataSize)
            .pack('VvVV',0,0,0,0); // 14-byte native UClass preload
    };
    $objects=$classObject(2,$baseHeaderOff,$baseDataOff,28)
        .$classObject(3,$derivedHeaderOff,$derivedDataOff,32)
        .pack('vvVV',6,3,0,$instanceHeaderOff)
        .pack('V4',0,0,24,0);
    $summary=pack('a32V6',"Unrealfile\x1A",$version,3,count($nameList),$namesOff,$objectOff,0);
    return [
        'bytes'=>$summary.$names.$baseHeader.$baseData.$derivedHeader.$derivedData.$instanceHeader.$objects,
        'derived_header_offset'=>$derivedHeaderOff,
        'derived_parent_ref_offset'=>$derivedHeaderOff+16+8,
    ];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1o06inherit');
if(!is_string($tmp))throw new RuntimeException('No temporary fixture');
try {
    foreach([22,23] as $version) {
        $fx=$fixture($version);
        file_put_contents($tmp,$fx['bytes']);
        $schemas=Schemas::read($tmp,true)['class_schemas'];
        $check("v{$version}_two_native_class_schemas",
            count($schemas)===2
            && $schemas[0]['class_name']==='BaseClass'
            && $schemas[1]['class_name']==='DerivedClass'
            && $schemas[1]['parent_class_ref']===1);
        $value=Values::read($tmp,true)['objects'][0]??[];
        $props=$value['property_values']??[];
        $check("v{$version}_child_then_parent_serialization",
            ($value['status']??null)==='native_binary_properties_decoded'
            && count($props)===2
            && ($props[0]['name']??null)==='DerivedFlag'
            && ($props[0]['values']??null)===[true]
            && ($props[1]['name']??null)==='BaseScore'
            && ($props[1]['values']??null)===[77]);
        $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unrealfile','version'=>$version]
        );
        $stageValues=$stage['native_metadata']['unrealfile_binary_properties']['objects'][0]??[];
        $check("v{$version}_stage_inheritance_without_runtime_identity",
            ($stageValues['property_values'][1]['values'][0]??null)===77
            && ($stage['parse_complete']??true)===false
            && ($stageValues['runtime_object_constructed']??true)===false);
        // Parent source class ref beyond original ResMap is invalid.
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',4),$fx['derived_parent_ref_offset'],4));
        try{Schemas::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$version}_invalid_parent_resmap_rejected",$reject);
        // Inheritance cycle must not loop forever or manufacture properties.
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['derived_parent_ref_offset'],4));
        try{Values::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$version}_self_parent_cycle_rejected",$reject);
        // Parent ref points to an ordinary instance, not a locally decoded
        // UClass. It can remain unresolved but cannot be treated as loaded.
        file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',3),$fx['derived_parent_ref_offset'],4));
        $unknown=Values::read($tmp,false)['objects'][0]??[];
        $check("v{$version}_external_or_nonclass_parent_unresolved",
            ($unknown['status']??null)==='parent_UClass_schema_unavailable'
            && ($unknown['property_values']??null)===[]);
    }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
    'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
