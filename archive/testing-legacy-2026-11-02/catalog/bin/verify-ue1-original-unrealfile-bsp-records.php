#!/usr/bin/env php
<?php
/** Original May v0.86x / July v0.867 BSP node/surface archive order. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBspRecordsReader as Bsp;
$checks=[];$bad=[];
$check=static function(string $name,bool $yes)use(&$checks,&$bad):void{
 $checks[$name]=$yes;if(!$yes)$bad[]=$name;
};
$make=static function(int $v,string $kind,bool $may=false):array{
 $prefix="Unrealfile\x1A";$names=$kind."\0".pack('V',0)."Item\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 if($kind==='BspNodes'){
  $native=pack('V3',1,1,1).pack('V5',1,0x42,0,0x99,0);
  $data=pack('g4',1.0,0.0,0.0,-2.0)
       .pack('V2',0x10,0)
       .($may?pack('V',0x103):pack('C',3))
       .($may?pack('V6',4,5,6,7,8,9):pack('V7',4,5,6,7,8,9,10))
       .pack('C3',1,2,3);
  $stride=$may?55:56;
 }else{
  $native=pack('V2',1,1);
  $data=pack('V9',1,1,0xA5A5,7,8,9,10,11,12)
        .pack('v2',0xffff,20).pack('V',1);
  $stride=44;
 }
 if(strlen($data)!==$stride)throw new RuntimeException('Wrong source BSP fixture record size');
 $header=$stack.$native;$nameOff=56;$headerOff=$nameOff+strlen($names);
 $dataOff=$headerOff+strlen($header);$tableOff=$dataOff+strlen($data);
 $table=pack('vvVV',2,1,0,$headerOff)
   .pack('V4',0,$dataOff,strlen($header),strlen($data));
 $summary=pack('a32V6',$prefix,$v,1,2,$nameOff,$tableOff,0);
 return ['bytes'=>$summary.$names.$header.$data.$table,
  'header'=>$headerOff+16,'data'=>$dataOff,'table'=>$tableOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1bsp97');
if(!is_string($tmp))throw new RuntimeException('No temporary file');
try{
 foreach([22,23] as $v)foreach(['BspNodes','BspSurfs'] as $class)
 foreach(($class==='BspNodes'?[false,true]:[false]) as $may){
  $f=$make($v,$class,$may);file_put_contents($tmp,$f['bytes']);
  $rows=Bsp::read($tmp,true)['bsp_records'];$r=$rows[0]??[];
  $key="v{$v}_{$class}_".($may?'May':'July');
  $check("{$key}_source_record_extent",
    count($rows)===1
    && ($r['status']??null)==='native_bsp_records_decoded'
    && ($r['record_count']??null)===1
    && ($r['record_capacity']??null)===1
    && ($r['source_record_stride']??null)===($class==='BspNodes'?($may?55:56):44)
    && ($r['runtime_references_resolved']??true)===false);
  $record=$r['records'][0]??[];
  $check("{$key}_typed_field_order",
    $class==='BspNodes'?
      (($record['plane']??null)===[1.0,0.0,0.0,-2.0]
       && ($record['zone_mask_words_le']??null)===[0x10,0]
       && ($record['node_flags']??null)===($may?0x103:3)
       && ($record['children']??null)===[6,7,8]
       && ($record['original_bound_index']??null)===($may?9:null)
       && ($record['collision_bound']??null)===($may?null:9)
       && ($record['render_bound']??null)===($may?null:10)
       && ($r['source_lineage']??null)===($may?'unreal_1997_may':'unreal_1997_july')
       && ($r['source_lineage_inferred_from_record_extent']??false)===true
       && ($record['zone_indices']??null)===[1,2]
       && ($record['num_vertices']??null)===3)
      :
      (($record['texture_ref']??null)===1
       && ($record['brush_ref']??null)===1
       && ($record['polygon_flags']??null)===0xA5A5
       && ($record['pan_u']??null)===-1
       && ($record['pan_v']??null)===20
       && ($record['actor_ref']??null)===1));
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
    $tmp,['format'=>'early_unrealfile','version'=>$v]
  );
  $stored=$stage['native_metadata']['unrealfile_bsp_records']['bsp_records'][0]??[];
  $check("{$key}_staging_preserves_unverified_typed_data",
    ($stored['record_count']??null)===1
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header'],4));
  try{Bsp::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$key}_invalid_NumMax_rejected",$reject);
  $badOffset=$f['data']+($class==='BspNodes'?($may?54:55):0);
  file_put_contents($tmp,substr_replace($f['bytes'],
    $class==='BspNodes'?pack('C',17):pack('V',2),
    $badOffset,$class==='BspNodes'?1:4));
  try{Bsp::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$key}_malformed_record_or_reference_rejected",$reject);
  if($class==='BspNodes'){
    file_put_contents($tmp,$f['bytes']);
    $wrong=$may?'unreal_1997_july':'unreal_1997_may';
    try{Bsp::read($tmp,true,$wrong);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("{$key}_wrong_explicit_record_stride_rejected",$reject);
    // A data length matching neither the source-defined 55 or 56-byte
    // record cannot be used to invent a May or July source profile.
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',57),$f['table']+24,4));
    try{$r=Bsp::read($tmp,false)['bsp_records'][0]??[];$ok=
        ($r['status']??null)==='source_1997_bsp_node_lineage_required';}
    catch(RuntimeException){$ok=true;} // Invalid span is also never decoded
    $check("{$key}_unknown_stride_never_decoded",$ok);
  }
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
