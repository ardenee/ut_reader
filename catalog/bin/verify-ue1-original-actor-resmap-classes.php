#!/usr/bin/env php
<?php
/** Original 1996 ResMap: imports first, local exports second, one-based. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceActorReader as Actor;
$checks=[];$bad=[];
$check=static function(string $id,bool $ok)use(&$checks,&$bad):void{
    $checks[$id]=$ok;if(!$ok)$bad[]=$id;
};
$fixture=static function(int $v,bool $hasImport):array{
    $prefix="Unreal Resource\x1A\0";$names=pack('a32V','NameOne',0);
    $props=pack('v2V7',1,0,2,400,0,1,4,0,0)
        .pack('v2V7',2,0,4,488,0,1,4,1,0);
    $default=str_repeat("\0",1148);
    $default=substr_replace($default,pack('V',44),400,4);
    $default=substr_replace($default,pack('g',9.5),488,4);
    $actor=str_repeat("\0",1148);
    $actor=substr_replace($actor,pack('V',$hasImport?2:1),0,4);
    $actor=substr_replace($actor,pack('V',55),400,4);
    $actor=substr_replace($actor,pack('g',3.0),488,4);
    $import=$hasImport?pack('a32VVV','OtherClass',1,10,1272):'';
    $nameOff=strlen($prefix);
    $importOff=$nameOff+strlen($names);
    $dataClassOff=$importOff+strlen($import);
    $dataActorOff=$dataClassOff+strlen($props);
    $expOff=$dataActorOff+strlen($actor);
    $exports=pack('a32VVV','MyClass',1,10,1272)
        .pack('a32VVV','ActorGroup',1,11,116);
    $headerOff=$expOff+strlen($exports);
    $classHeader=pack('V2a32V9',0,10,'MyClass',0,0,1,0xffffffff,0,
        $dataClassOff,strlen($props),0,0)
        .pack('V2',2,2).str_repeat("\0",40).$default;
    $listHeader=pack('V2a32V9',0,11,'ActorGroup',0,0,1,0xffffffff,0,
        $dataActorOff,strlen($actor),0,0)
        .pack('V2',1,1).pack('V8',0,1,0,0,0,0,0,0);
    if(strlen($classHeader)!==1272||strlen($listHeader)!==116)
        throw new RuntimeException('Native source header size mismatch');
    $trail=pack('V9',$v,2,$hasImport?1:0,1,$nameOff,
        $importOff,$expOff,$headerOff,1388)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return [
        'bytes'=>$prefix.$names.$import.$props.$actor.$exports
            .$classHeader.$listHeader.'[SUMMARY]'.$trail,
        'actor_class_word_offset'=>$dataActorOff,
        'expected_ref'=>$hasImport?2:1,
        'not_class_ref'=>$hasImport?3:2,
    ];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1actorresmap');
if(!is_string($tmp))throw new RuntimeException('No actor fixture temporary path');
try{
  foreach([9,10] as $v)foreach([false,true] as $hasImport){
    $fx=$fixture($v,$hasImport);file_put_contents($tmp,$fx['bytes']);
    $id="v{$v}_".($hasImport?'with_import':'without_import');
    $result=Actor::read($tmp,true)['actor_resources'];
    $list=$result[1]??[];$actor=$list['actors'][0]??[];
    $properties=$actor['class_property_values']??[];
    $check("{$id}_source_one_based_resmap",
        ($actor['class_reference_raw_word']??null)===$fx['expected_ref']
        && ($actor['class_reference_status']??null)==='source_local_export_UClass'
        && ($actor['class_export_index']??null)===0
        && ($actor['class_resource_name']??null)==='MyClass');
    $check("{$id}_per_instance_global_value",
        ($properties['property_values'][0]['values']??null)===[55]
        && ($properties['decoded_property_count']??null)===2);
    $check("{$id}_static_from_UClass_default_not_instance",
        ($properties['property_values'][1]['values']??null)===[9.5]
        && ($result[0]['default_actor_class_properties']['property_values'][0]['values']??null)===[44]
        && ($properties['runtime_resource_refs_resolved']??true)===false);
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $tmp,['format'=>'early_unreal_resource','version'=>$v]);
    $record=$stage['native_metadata']['resource_actor_records']['actor_resources'][1]['actors'][0]??[];
    $check("{$id}_native_production_staging_values",
        ($record['class_property_values']['property_values'][0]['values'][0]??null)===55
        && ($stage['parse_complete']??true)===false);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',1),
        $fx['actor_class_word_offset'],4));
    if($hasImport){
        $other=Actor::read($tmp,true)['actor_resources'][1]['actors'][0];
        $check("{$id}_imported_class_stays_unresolved",
            ($other['class_reference_status']??null)==='imported_UClass_provider_unavailable'
            && array_key_exists('class_property_values',$other)
            && $other['class_property_values']===null);
    }
    file_put_contents($tmp,substr_replace($fx['bytes'],
        pack('V',$fx['not_class_ref']),$fx['actor_class_word_offset'],4));
    $wrong=Actor::read($tmp,true)['actor_resources'][1]['actors'][0];
    $check("{$id}_other_export_not_misbound_as_UClass",
        $wrong['class_reference_status']==='resmap_export_not_decoded_UClass'
        && $wrong['class_property_values']===null);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',1000),
        $fx['actor_class_word_offset'],4));
    $badRef=Actor::read($tmp,true)['actor_resources'][1]['actors'][0];
    $check("{$id}_invalid_ref_never_promoted",
        $badRef['class_reference_status']==='one_based_resmap_ref_out_of_range'
        && $badRef['class_property_values']===null);
  }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
