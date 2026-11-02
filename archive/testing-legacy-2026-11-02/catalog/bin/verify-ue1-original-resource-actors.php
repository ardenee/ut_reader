#!/usr/bin/env php
<?php
/** Original 1996 v9/v10 native UClass embedded actor and UActorList records. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceActorReader as Actor;
$checks=[];$bad=[];$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{
 $checks[$n]=$ok;if(!$ok)$bad[]=$n;
};
$makeActor=static function():string{
 $buf=str_repeat("\0",1148);
 $buf=substr_replace($buf,pack('V',23),0,4);
 $buf=substr_replace($buf,pack('g3V',1.0,-2.0,3.5,0x12345678),4,16);
 $buf=substr_replace($buf,pack('g3V',4.0,5.0,6.0,99),20,16);
 $buf=substr_replace($buf,pack('V3',0xffffffff,2,3),36,12);
 $buf=substr_replace($buf,pack('V3',4,5,6),48,12);
 $buf=substr_replace($buf,pack('v2',7,8),144,4);
 $buf=substr_replace($buf,pack('V',0xffffffff),152,4);
 $buf=substr_replace($buf,pack('V3',10,11,12),948,12);
 return $buf;
};
$make=static function(int $v,int $type)use($makeActor):array{
 $prefix="Unreal Resource\x1A\0";$names=pack('a32V','ACTOR',0);
 $actor=$makeActor();
 $data=$type===11?$actor:'';
 $headerSize=$type===10?1272:116;
 $extra=$type===10
   ? pack('V10',10,11,12,1,0,0xabcd,0xbeef,0,0,0).$actor
   : pack('V8',5,1,10,11,12,13,14,15);
 if(strlen($extra)!==$headerSize-84)
    throw new RuntimeException('Actor source fixture native layout mismatch');
 $nameOff=strlen($prefix);$dataOff=$nameOff+strlen($names);
 $exportOff=$dataOff+strlen($data);
 $export=pack('a32VVV','ActorOne',1,$type,$headerSize);
 $headerOff=$exportOff+strlen($export);
 $header=pack('V2a32V9',0,$type,'ActorOne',0,0,1,0xffffffff,0,$dataOff,strlen($data),0,0)
    .pack('V2',($type===10?0:1),($type===10?0:1)).$extra;
 $tail=pack('V9',$v,1,0,1,$nameOff,0,$exportOff,$headerOff,$headerSize)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$data.$export.$header.'[SUMMARY]'.$tail,
  'data'=>$dataOff,'header'=>$headerOff,'export'=>$exportOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1actors96');
if(!is_string($tmp))throw new RuntimeException('No temp path');
try{
 foreach([9,10] as $v)foreach([10,11] as $type){
  $fx=$make($v,$type);file_put_contents($tmp,$fx['bytes']);
  $r=Actor::read($tmp,true)['actor_resources'][0]??[];
  $isClass=$type===10;$key="v{$v}_type{$type}";
  $actor=$isClass?($r['default_actor']??[]):($r['actors'][0]??[]);
  $check("{$key}_actor_record_source_layout",
    ($r['status']??null)===($isClass?
      'native_UClass_default_actor_core_decoded':'native_actor_list_core_records_decoded')
    && ($r['native_header_bytes']??null)===($isClass?1272:116)
    && ($actor['class_reference_raw_word']??null)===23
    && ($actor['native_property_pad_bytes']??null)===643
    && ($actor['native_private_segment_bytes']??null)===200
    && ($actor['all_native_member_values_decoded']??true)===false);
  $check("{$key}_actor_native_field_values",
    ($actor['location']['xyz']??null)===[1.0,-2.0,3.5]
    && ($actor['location']['w_or_flags_raw_word']??null)===0x12345678
    && ($actor['draw_rotation']??null)===[-1,2,3]
    && ($actor['name_index_raw_word']??null)===7
    && ($actor['event_index_raw_word']??null)===8
    && ($actor['actor_index']??null)===-1
    && ($r['runtime_class_links_resolved']??true)===false);
  $check("{$key}_source_container_header",
    $isClass
     ?(($r['class_reference_raw_words']['parent_class']??null)===10
       && ($r['script_text_crc']??null)===0xabcd)
     :(($r['record_count']??null)===1
       && ($r['record_size_bytes']??null)===1148
       && ($r['runtime_compact_actor_lists_raw_words'][0]??null)===10));
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unreal_resource','version'=>$v]
  );
  $s=$stage['native_metadata']['resource_actor_records']['actor_resources'][0]??[];
  $check("{$key}_native_actor_staging_partial",
    ($s['native_header_bytes']??null)===($isClass?1272:116)
    && ($s['all_native_actor_members_decoded']??true)===false
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($fx['bytes'],
    pack('V',($isClass?1268:112)),$fx['export']+40,4));
  $partial=Actor::read($tmp,false)['actor_resources'][0]??[];
  try{Actor::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$key}_short_native_header_rejected",
    ($partial['status']??null)==='incomplete_original_actor_container_header'&&$reject);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',1),
    $fx['header']+64,4));
  try{Actor::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$key}_incorrect_data_size_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
