#!/usr/bin/env php
<?php
/** Epic v0.867 six source-specific UE1 original SerializeHeader contracts. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileSpecialHeadersReader as Special;
$checks=[];$failed=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failed):void{
 $checks[$name]=$pass;if(!$pass)$failed[]=$name;
};
$make=static function(int $ver,string $class):array{
 $names=$class."\0".pack('V',0)."Item\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $payload='';
 $native=match($class){
   'Camera'=>pack('V',1),
   'BspNodes'=>pack('V3',0,0,1).pack('V5',1,0x42,0,0x99,0),
   'LightMesh'=>pack('V3',0,0,1),
   'Verts'=>pack('V3',1,1,3),
   'Font'=>pack('V3',1,1,1),
   'StackTree'=>pack('V3',0,0,1)
 };
 if($class==='Verts')$payload=pack('V2',11,12);
 if($class==='Font')$payload=pack('V4',1,2,3,4);
 $header=$stack.$native;$nameOff=56;
 $hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($header);
 $tableOff=$dataOff+strlen($payload);
 $entry=pack('vvVV',2,1,0,$hOff).pack('V4',0,$dataOff,strlen($header),strlen($payload));
 $summary=pack('a32V6',"Unrealfile\x1A",$ver,1,2,$nameOff,$tableOff,0);
 return ['bytes'=>$summary.$names.$header.$payload.$entry,
  'native_offset'=>$hOff+16,'table_offset'=>$tableOff,'header_bytes'=>strlen($header)];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1special97');
if(!is_string($tmp))throw new RuntimeException('Temporary fixture unavailable');
try {
 foreach([22,23] as $version)foreach(['Camera','BspNodes','LightMesh','Verts','Font','StackTree'] as $class){
  $fx=$make($version,$class);
  file_put_contents($tmp,$fx['bytes']);
  $rows=Special::read($tmp,true)['special_headers'];
  $r=$rows[0]??[];
  $id="v{$version}_{$class}";
  $check("{$id}_native_archive_members",
    count($rows)===1
    && ($r['status']??null)==='native_special_header_decoded'
    && ($r['native_class']??null)==='U'.$class
    && ($r['unconsumed_member_bytes']??null)===0
    && ($r['runtime_references_resolved']??true)===false
    && ($r['class_specific_data_decoded']??null)===in_array($class,['Verts','Font'],true));
  $expected=match($class){
   'Camera'=>($r['texture_raw_one_based_ref']??null)===1,
   'BspNodes'=>($r['num_zones']??null)===1
    && ($r['zones'][0]['zone_actor_ref']??null)===1
    && ($r['zones'][0]['connectivity_words_le']??null)===[0x42,0]
    && ($r['zones'][0]['visibility_words_le']??null)===[0x99,0],
   'LightMesh'=>($r['bits_raw_one_based_ref']??null)===1,
   'Verts'=>($r['num_shared_sides']??null)===3
    && ($r['source_record_bytes']??null)===8
    && ($r['record_values'][0]['point_vertex']??null)===11
    && ($r['record_values'][0]['side']??null)===12,
   'Font'=>($r['texture_raw_one_based_ref']??null)===1
    && ($r['source_record_bytes']??null)===16
    && ($r['record_values'][0]['start_u']??null)===1
    && ($r['record_values'][0]['v_size']??null)===4,
   'StackTree'=>($r['class_raw_one_based_ref']??null)===1,
  };
  $check("{$id}_source_fields",$expected);
  $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
     $tmp,['format'=>'early_unrealfile','version'=>$version]
  );
  $stored=$staged['native_metadata']['unrealfile_special_headers']['special_headers'][0]??[];
  $check("{$id}_unverified_native_staging",
     ($stored['status']??null)==='native_special_header_decoded'
     && ($staged['parse_complete']??true)===false
     && ($stored['runtime_references_resolved']??true)===false);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',2),$fx['native_offset']+
      ($class==='Camera'?0:($class==='BspNodes'?12:($class==='Verts'?0:8))),4));
  try{Special::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("{$id}_bad_reference_rejected",$bad);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',16+($class==='Camera'?3:11)),$fx['table_offset']+20,4));
  $soft=Special::read($tmp,false)['special_headers'][0]??[];
  try{Special::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("{$id}_short_header_not_promoted",
    ($soft['status']??null)==='incomplete_original_special_header' && $bad);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
