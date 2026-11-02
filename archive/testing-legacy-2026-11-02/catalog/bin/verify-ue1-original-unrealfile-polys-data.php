#!/usr/bin/env php
<?php
/** Original v0.867 UPolys::SerializeData/FPoly::operator<<. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePolysDataReader as Polys;
$checks=[];$bad=[];
$check=static function(string $id,bool $ok)use(&$checks,&$bad):void{
 $checks[$id]=$ok;if(!$ok)$bad[]=$id;
};
$make=static function(int $v):array{
 $names='';
 foreach(['Polys','OnePoly','Group','Item'] as $name)$names.=$name."\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $header=$stack.pack('V2',1,1);
 $data=pack('V',3)
  .pack('g12',0.0,0.0,0.0,0.0,0.0,1.0,1.0,0.0,0.0,0.0,1.0,0.0)
  .pack('g9',0.0,0.0,0.0,1.0,0.0,0.0,0.0,1.0,0.0)
  .pack('V3',0x55,1,1)
  .pack('v2',3,4)
  .pack('V2',0xffffffff,2)
  .pack('v2',0xfffe,5)
  .pack('C2',1,2);
 if(strlen($data)!==118)throw new RuntimeException('Wrong source FPoly variable archive fixture');
 $nameOff=56;$hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($header);$tableOff=$dataOff+strlen($data);
 $table=pack('vvVV',2,1,0,$hOff).pack('V4',0,$dataOff,strlen($header),strlen($data));
 $summary=pack('a32V6',"Unrealfile\x1A",$v,1,4,$nameOff,$tableOff,0);
 return ['bytes'=>$summary.$names.$header.$data.$table,
  'native'=>$hOff+16,'data'=>$dataOff,'table'=>$tableOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1fpoly97');
if(!is_string($tmp))throw new RuntimeException('Temp unavailable');
try {
 foreach([22,23] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $r=Polys::read($tmp,true)['polygon_records'][0]??[];
  $poly=$r['records'][0]??[];
  $check("v{$v}_source_archive_length",
    ($r['status']??null)==='native_fpoly_records_decoded'
    && ($r['record_count']??null)===1
    && ($r['serialized_data_bytes']??null)===118
    && ($r['runtime_references_resolved']??true)===false);
  $check("v{$v}_vectors_and_active_vertices",
    ($poly['num_vertices']??null)===3
    && ($poly['normal']??null)===[0.0,0.0,1.0]
    && ($poly['vertices'][1]??null)===[1.0,0.0,0.0]
    && ($poly['vertices'][2]??null)===[0.0,1.0,0.0]);
  $check("v{$v}_source_references_and_signed_fields",
    ($poly['brush_ref']??null)===1
    && ($poly['texture_ref']??null)===1
    && ($poly['group_name']??null)==='Group'
    && ($poly['item_name']??null)==='Item'
    && ($poly['link_index']??null)===-1
    && ($poly['brush_poly_index']??null)===2
    && ($poly['pan_u']??null)===-2
    && ($poly['zone_indices']??null)===[1,2]);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
   $tmp,['format'=>'early_unrealfile','version'=>$v]
  );
  $s=$stage['native_metadata']['unrealfile_polygon_records']['polygon_records'][0]??[];
  $check("v{$v}_staging_unverified_polygon_data",
    ($s['records'][0]['num_vertices']??null)===3
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',17),$f['data'],4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_vertex_count_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['data']+92,4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_brush_reference_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['native'],4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_num_exceeding_max_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',119),$f['table']+24,4));
  try{Polys::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_extra_archive_data_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
