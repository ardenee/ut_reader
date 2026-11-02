#!/usr/bin/env php
<?php
/** UE1 1996 UBspNodes native 64-zone header and 64-byte FBspNode. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspNodesReader as Nodes;
$checks=[];$failed=[];
$check=static function(string $id,bool $ok)use(&$checks,&$failed):void{
 $checks[$id]=$ok;if(!$ok)$failed[]=$id;
};
$make=static function(int $v):array{
 $prefix="Unreal Resource\x1A\0";
 $name=pack('a32V','NODES',0);$nameOff=strlen($prefix);
 $node=pack('g3',1.0,2.0,3.0).pack('V',0)
   .pack('V2',1,0)
   .pack('V7',0x12,0xffffffff,2,0xffffffff,0xffffffff,0xffffffff,0)
   .pack('C4',1,2,3,0).pack('V2',0,0);
 if(strlen($node)!==64)throw new RuntimeException('Bad FBspNode size');
 $dataOff=$nameOff+strlen($name);$expOff=$dataOff+strlen($node);
 $export=pack('a32VVV','NodeOne',1,17,1640);
 $headOff=$expOff+strlen($export);
 $zones=pack('V6',0xffffffff,0,1,0,3,0).str_repeat("\0",63*24);
 $header=pack('V2a32V9',0,17,'NodeOne',0,0,1,0xffffffff,0,$dataOff,64,0,0)
   .pack('V2',1,1).pack('V4',1,3,0,0).pack('V',0).$zones;
 if(strlen($header)!==1640)throw new RuntimeException('Bad native BspNodes header');
 $tail=pack('V9',$v,1,0,1,$nameOff,0,$expOff,$headOff,1640)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$name.$node.$export.$header.'[SUMMARY]'.$tail,
   'header'=>$headOff,'export'=>$expOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1bspnodes');
if(!is_string($tmp))throw new RuntimeException('Missing temporary test path');
try {
 foreach([9,10] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $parsed=Nodes::read($tmp,true)['bsp_node_records'][0]??[];
  $record=$parsed['nodes'][0]??[];
  $check("v{$v}_record_count_and_zone_header",
   ($parsed['status']??null)==='native_bsp_nodes_decoded'
   && ($parsed['record_count']??null)===1
   && ($parsed['record_size_bytes']??null)===64
   && ($parsed['header_source_bytes']??null)===1640
   && ($parsed['num_zones']??null)===1
   && count($parsed['zones']??[])===64);
  $check("v{$v}_zone_connectivity_and_raw_actor",
   ($parsed['zones'][0]['zone_actor_raw_index']??null)===-1
   && ($parsed['zones'][0]['connectivity_unsigned_words']??null)===[1,0]
   && ($parsed['zones'][0]['visibility_unsigned_words']??null)===[3,0]
   && ($parsed['runtime_zone_actors_resolved']??true)===false);
  $check("v{$v}_node_native_layout",
   ($record['plane_vector']??null)===[1.0,2.0,3.0]
   && ($record['node_flags']??null)===0x12
   && ($record['vertex_pool_index']??null)===-1
   && ($record['surface_index']??null)===2
   && ($record['zone_indices']??null)===[1,2]
   && ($record['vertex_count']??null)===3);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unreal_resource','version'=>$v]);
  $stored=$stage['native_metadata']['resource_bsp_node_records']['bsp_node_records'][0]??[];
  $check("v{$v}_native_staging_unverified",
    ($stored['record_count']??null)===1
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',65),$f['header']+84,4));
  try{Nodes::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_too_many_zones_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header']+76,4));
  try{Nodes::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_node_count_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',1639),$f['export']+40,4));
  $partial=Nodes::read($tmp,false)['bsp_node_records'][0]??[];
  try{Nodes::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_short_header_never_promoted",
   ($partial['status']??null)==='incomplete_native_bsp_nodes_header'&&$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
