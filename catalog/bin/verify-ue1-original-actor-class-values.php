#!/usr/bin/env php
<?php
/** Native 1996 FClassProperty values in AActor PROPBIN_Global/Static. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalActorClassPropertyValueReader as Values;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceActorReader as Actor;

$checks=[];$bad=[];
$check=static function(string $k,bool $yes)use(&$checks,&$bad):void{
    $checks[$k]=$yes;if(!$yes)$bad[]=$k;
};
$descriptor=static function(int $idx,int $type,int $off,int $dim,int $size,int $bin,int $extra=0):array{
    return ['index'=>$idx,'name_index_raw_word'=>$idx+1,
      'type'=>$type,'type_name'=>['None','Byte','Integer','Boolean','Real','Actor','Resource','Name','String','Vector','Rotation'][$type],
      'property_memory_offset'=>$off,'array_dim'=>$dim,'element_size'=>$size,
      'bin'=>$bin,'boolean_bitmask'=>$type===3?$extra:null];
};
$actor=str_repeat("\0",1148);
$put=static function(int $offset,string $bytes)use(&$actor):void{
    $actor=substr_replace($actor,$bytes,$offset,strlen($bytes));
};
$put(400,pack('V',0xfffffffb));
$put(404,pack('V',0xfffffffd));
$put(408,pack('V',1|8));
$put(420,pack('V',31337));
$put(428,pack('v',44));
$put(432,"Epic\0".str_repeat("\0",7));
$put(448,pack('g3V',1.5,-2.5,3.0,0xfeedbeef));
$put(472,pack('V3',0xffffffff,1,0x80000000));
$put(488,pack('g',2.25));
$put(500,pack('V',12));
$put(510,pack('C',201));
$properties=[
    $descriptor(0,2,400,2,4,0),
    $descriptor(1,3,408,1,4,0,1),
    $descriptor(2,3,408,1,0,0,8),
    $descriptor(3,6,420,1,4,0),
    $descriptor(4,7,428,1,2,0),
    $descriptor(5,8,432,1,12,0),
    $descriptor(6,9,448,1,16,0),
    $descriptor(7,10,472,1,12,0),
    $descriptor(8,4,488,1,4,1),
    $descriptor(9,5,500,1,4,0),
    $descriptor(10,1,510,1,1,0),
    $descriptor(11,4,400,1,4,2)
];
$frame=Values::read($actor,$properties,$actor);
$p=$frame['property_values'];
$check('source_bins_and_status',
    $frame['status']==='class_frame_values_partially_unresolved'
    && $frame['decoded_property_count']===11
    && $frame['unresolved_property_count']===1
    && $frame['runtime_local_script_bin_loaded']===false);
$check('global_signed_integer_array',
    $p[0]['values']===[-5,-3] && $p[0]['status']==='source_native_class_property_decoded');
$check('packed_bool_native_bitmask',
    $p[1]['values']===[true] && $p[2]['values']===[true]
    && $p[2]['element_size']===0);
$check('raw_resource_and_name_identity',
    $p[3]['values']===[['resource_pointer_raw_word'=>31337]]
    && $p[4]['values']===[['fname_index_raw_word'=>44]]
    && $frame['runtime_resource_refs_resolved']===false);
$check('ansi_fixed_string_bytes',
    ($p[5]['values'][0]['ansi_bytes_hex']??null)==='45706963'
    && ($p[5]['values'][0]['nul_terminated']??false)===true);
$check('x86_vector_and_signed_rotation',
    ($p[6]['values'][0]['xyz']??null)===[1.5,-2.5,3.0]
    && ($p[6]['values'][0]['w_or_flags_raw_word']??null)===0xfeedbeef
    && $p[7]['values'][0]===[-1,1,-2147483648]);
$check('static_bin_and_native_actor_reference',
    $p[8]['values']===[2.25]
    && $p[9]['values']===[['actor_index_raw'=>12]]
    && $p[10]['values']===[201]);
$check('local_bin_is_unresolved',
    $p[11]['status']==='runtime_local_execution_bin_unavailable'
    && $p[11]['values']===[]);
$unprovided=Values::read($actor,[$descriptor(0,4,488,1,4,1)])['property_values'][0];
$check('unprovided_class_default_not_fabricated',
    $unprovided['status']==='static_default_actor_provider_unavailable');
try{Values::read($actor,[$descriptor(0,2,943,2,4,0)],$actor);$reject=false;}
catch(RuntimeException){$reject=true;}
$check('native_propertypad_overrun_rejected',$reject);
try{Values::read($actor,[$descriptor(0,3,400,2,4,0,1)],$actor);$reject=false;}
catch(RuntimeException){$reject=true;}
$check('native_boolean_arrays_rejected',$reject);
// Native integrated UClass resource with two property records.
$native=pack('v2V7',1,0,2,400,0,2,4,0,0)
    .pack('v2V7',2,0,4,488,0,1,4,1,0);
$prefix="Unreal Resource\x1A\0";$names=pack('a32V','ClassOne',0);
$dataOff=strlen($prefix)+strlen($names);
$exportOff=$dataOff+strlen($native);
$export=pack('a32VVV','ClassOne',1,10,1272);
$headerOff=$exportOff+strlen($export);
$header=pack('V2a32V9',0,10,'ClassOne',0,0,1,0xffffffff,0,$dataOff,strlen($native),0,0)
    .pack('V2',2,2).str_repeat("\0",40).$actor;
if(strlen($header)!==1272)throw new RuntimeException('Native UClass test header mismatch');
$temp=tempnam(sys_get_temp_dir(),'ue1nativeprop');
if(!is_string($temp))throw new RuntimeException('No temp file');
try{
  foreach([9,10] as $v){
    $tail=pack('V9',$v,1,0,1,strlen($prefix),0,$exportOff,$headerOff,1272)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    file_put_contents($temp,$prefix.$names.$native.$export.$header.'[SUMMARY]'.$tail);
    $result=Actor::read($temp,true)['actor_resources'][0]??[];
    $values=$result['default_actor_class_properties']??[];
    $check("v{$v}_integrated_UClass_property_frame",
        ($result['actor_UClass_properties_decoded']??false)===true
        && ($values['property_values'][0]['values']??null)===[-5,-3]
        && ($values['property_values'][1]['values']??null)===[2.25]
        && ($values['unresolved_property_count']??null)===0
        && ($result['all_native_actor_members_decoded']??true)===false);
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $temp,['format'=>'early_unreal_resource','version'=>$v]);
    $stored=$stage['native_metadata']['resource_actor_records']['actor_resources'][0]??[];
    $check("v{$v}_production_native_staging_frame",
        ($stored['default_actor_class_properties']['property_values'][0]['values'][0]??null)===-5
        && ($stage['parse_complete']??true)===false);
  }
}finally{@unlink($temp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
