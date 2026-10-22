#!/usr/bin/env php
<?php
/** Epic v1.200 FBspNode and FZoneProperties nontransactional package records. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$h=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($h);
 $s=chr($h|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$paths=[];$tests=[];$fail=[];
$check=static function(string $key,bool $ok)use(&$tests,&$fail):void{
 $tests[$key]=$ok;if(!$ok)$fail[]=$key;
};
$node=static function(int $flags=0x83)use($idx):string{
 return pack('g4',1.25,-2.5,3.5,4.5).hex2bin('0001020304050607')
   .chr($flags).$idx(8).$idx(9).$idx(-1).$idx(2)
   .$idx(3).$idx(4).$idx(5).chr(11).chr(12).chr(3)
   .pack('V2',-1&0xffffffff,0x12345678);
};
$zone=static function(int $actor=-1)use($idx):string{
 return $idx($actor).hex2bin('0807060504030201')
   .hex2bin('0011223344556677');
};
$disk=static function(int $ver,int $n,int $max,string $record,
    int $numZones=0,string $zones='')use($idx):array{
 $raw=$record.$idx($numZones).$zones;
 return $ver<40?[$raw,['legacyNum'=>$n,'legacyMax'=>$max]]
   :[pack('V2',$n&0xffffffff,$max&0xffffffff).$raw,[]];
};
$build=static function(int $ver,string $raw,array $opt)use($idx):string{
 $names=['None','BspNodes','Example','Core','Class'];$nmap='';
 foreach($names as $n){$nmap.=($ver>=64?$idx(strlen($n)+1):'').$n."\0".pack('V',0);}
 $imp=$idx(3).$idx(4).($ver<50?$idx(0):pack('V',0)).$idx(1);
 $prefix=$ver<40?pack('V2',($opt['legacyNum']??0)&0xffffffff,
   ($opt['legacyMax']??0)&0xffffffff):'';
 if($ver<47){$prefix.=$idx(0).$idx(0).str_repeat("\0",8);}
 $body=$prefix."\0".($ver<57?"\0":"").($ver<58?"\0":"").$raw;
 $namesAt=$ver>=68?56:44;$importsAt=$namesAt+strlen($nmap);
 $exportsAt=$importsAt+strlen($imp);
 $exp=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(2)
   .pack('V',0).$idx(strlen($body)).$idx(512);
 $head=pack('V9',0x9e2a83c1,$ver,0,count($names),$namesAt,1,$exportsAt,1,$importsAt)
   .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $metadata=$head.$nmap.$imp.$exp;
 if(strlen($metadata)>512)throw new RuntimeException('BSP fixture metadata too long');
 return $metadata.str_repeat("\0",512-strlen($metadata)).$body;
};
$parse=static function(int $ver,string $wire,array $opt=[])use(&$paths,$build):array{
 $p=tempnam(sys_get_temp_dir(),'o06bn');
 if(!$p)throw new RuntimeException('Temporary BSP node fixture unavailable');
 $paths[]=$p;file_put_contents($p,$build($ver,$wire,$opt));
 $pkg=new Package($p);
 if($pkg->validatePackage()!==[])throw new RuntimeException('BSP node fixture invalid');
 $tags=Tags::inspect($p,$pkg,0);
 if(($tags['status']??'')!=='tag_envelope_decoded'){
  throw new RuntimeException('BSP node fixture properties invalid');
 }
 return Native::inspect($p,$pkg,0,$tags);
};
$reject=static function(int $ver,string $wire,array $opt=[])use($parse):bool{
 try{$parse($ver,$wire,$opt);return false;}catch(RuntimeException){return true;}
};
try{
 $source='L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]';
 $hdr=@file_get_contents($source.'\Engine\Inc\UnObj.h');
 $impl=@file_get_contents($source.'\Engine\Src\UnModel.cpp');
 $check('source_fbspnode_archive_order',is_string($hdr)
    &&str_contains($hdr,'Ar << N.Plane << N.ZoneMask << N.NodeFlags')
    &&str_contains($hdr,'AR_INDEX(N.iCollisionBound)')
    &&str_contains($hdr,'Ar << N.iLeaf[0] << N.iLeaf[1];'));
 $check('source_zone_props_early_return',is_string($hdr)
    &&str_contains($hdr,'return Ar << *(UObject**)&P.ZoneActor << P.Connectivity << P.Visibility;')
    &&str_contains($hdr,'Ar << P.LastRenderTime;'));
 $check('source_node_database_macro_and_suffix',is_string($hdr)
    &&str_contains($hdr,'DECLARE_DB_CLASS(UBspNodes,UDatabase,FBspNode)')
    &&str_contains($hdr,'Ar << AR_INDEX(NumZones);')
    &&str_contains($hdr,'for( INT i=0; i<NumZones; i++ )')
    &&is_string($impl)&&str_contains($impl,'IMPLEMENT_DB_CLASS(UBspNodes);'));
 foreach([35,39,40,50,61] as $ver){
  [$wire,$prefix]=$disk($ver,2,7,$node().$node(1),2,$zone(-1).$zone(1));
  $parsed=$parse($ver,$wire,$prefix);
  $check('complete_two_nodes_two_zones_v'.$ver,
    $parsed['status']==='native_bspnodes_database_complete'
    &&$parsed['count']===2&&$parsed['capacity']===7
    &&$parsed['num_zones']===2
    &&count($parsed['nodes_preview'])===2
    &&count($parsed['zones_preview'])===2);
  $check('plane_and_zone_mask_v'.$ver,
    $parsed['nodes_preview'][0]['plane']===[1.25,-2.5,3.5,4.5]
    &&$parsed['nodes_preview'][0]['zone_mask_le_hex']==='0001020304050607'
    &&$parsed['nodes_preview'][0]['node_flags']===0x83);
  $check('compact_indices_leaves_and_zone_ids_v'.$ver,
    $parsed['nodes_preview'][0]['indices']['child_back']===-1
    &&$parsed['nodes_preview'][0]['indices']['render_bound']===5
    &&$parsed['nodes_preview'][0]['zones']===[11,12]
    &&$parsed['nodes_preview'][0]['leaves']===[-1,0x12345678]);
  $check('zone_props_source_no_last_render_time_v'.$ver,
    $parsed['zones_preview'][0]['actor_reference']===-1
    &&$parsed['zones_preview'][1]['actor_reference']===1
    &&$parsed['zones_preview'][0]['connectivity_le_hex']==='0807060504030201'
    &&$parsed['last_render_time_serialized']===false);
  $check('inheritance_count_prefix_location_v'.$ver,
    $parsed['counts_in_uobject_prefix']===($ver<40));
  [$wire,$prefix]=$disk($ver,0,0,'');
  $empty=$parse($ver,$wire,$prefix);
  $check('empty_bsp_database_v'.$ver,
    $empty['status']==='native_bspnodes_database_complete'
    &&$empty['num_zones']===0&&$empty['count']===0);
  $extension=$parse($ver,$wire."\xca",$prefix);
  $check('no_silent_tail_acceptance_v'.$ver,
    $extension['status']==='native_bspnodes_database_has_extension'
    &&$extension['extension_bytes']===1);
  [$wire,$prefix]=$disk($ver,1,0,$node());
  $check('count_capacity_invalid_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,5,5,$node());
  $check('node_count_truncated_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$node(),65);
  $check('zone_count_exceeds_source_64_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$node(),1,$zone(-2));
  $check('zone_actor_ref_invalid_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$node(),1,substr($zone(),0,-1));
  $check('zone_masks_truncated_v'.$ver,$reject($ver,$wire,$prefix));
 }
 [$wire,$prefix]=$disk(61,18,18,str_repeat($node(),18),18,str_repeat($zone(),18));
 $large=$parse(61,$wire,$prefix);
 $check('bounded_18_node_and_zone_previews',
    $large['status']==='native_bspnodes_database_complete'
    &&$large['count']===18&&count($large['nodes_preview'])===16
    &&$large['num_zones']===18&&count($large['zones_preview'])===16);
 $utModel=@file_get_contents('L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Engine\Src\UnModel.cpp');
 $utHeader=@file_get_contents('L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Engine\Inc\UnObj.h');
 $check('ut99_old_bsp_nodes_loading_only_archive_source',
   is_string($utModel)
   &&str_contains($utModel,'class ENGINE_API UBspNodes : public UObject')
   &&str_contains($utModel,'Ar << DbNum << DbMax;')
   &&str_contains($utModel,'Ar << AR_INDEX(_NumZones);')
   &&str_contains($utModel,'Ar << _Zones[i];'));
 $check('ut99_node_transient_load_clear_source',
   is_string($utModel)
   &&str_contains($utModel,'N.NodeFlags &= ~(NF_IsNew|NF_IsFront|NF_IsBack);')
   &&is_string($utHeader)
   &&str_contains($utHeader,'return Ar << *(UObject**)&P.ZoneActor << P.Connectivity << P.Visibility;'));
 foreach([62,68,69] as $ver){
  [$wire,$prefix]=$disk($ver,2,7,$node(0xe3).$node(0xa2),2,$zone(-1).$zone(1));
  $rec=$parse($ver,$wire,$prefix);
  $check('ut99_old_bsp_node_loader_v'.$ver,
    $rec['status']==='native_bspnodes_database_complete'
    &&$rec['archive_family']==='UT99-v400-legacy-database'
    &&$rec['count']===2&&$rec['capacity']===7
    &&$rec['num_zones']===2);
  $check('ut99_node_flags_clear_e0_on_load_v'.$ver,
    $rec['nodes_preview'][0]['node_flags']===0xe3
    &&$rec['nodes_preview'][0]['node_flags_after_epic_load']===3
    &&$rec['nodes_preview'][1]['node_flags_after_epic_load']===2);
  $check('ut99_zone_fields_not_last_render_time_v'.$ver,
    $rec['zones_preview'][0]['actor_reference']===-1
    &&$rec['zones_preview'][0]['visibility_le_hex']==='0011223344556677'
    &&$rec['last_render_time_serialized']===false);
  [$wire,$prefix]=$disk($ver,2,1,$node());
  $check('ut99_invalid_node_db_capacity_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$node(),1,substr($zone(),0,-1));
  $check('ut99_truncated_zone_mask_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$node(),65);
  $check('ut99_invalid_zone_count_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$node(),1,$zone(-2));
  $check('ut99_invalid_zone_actor_v'.$ver,$reject($ver,$wire,$prefix));
 }

}finally{foreach($paths as $p){@unlink($p);}}
echo json_encode(['ok'=>count($fail)===0,'total'=>count($tests),
 'passed'=>count($tests)-count($fail),'failed'=>$fail,
 'scope'=>'Epic v1.200 FBspNode FZoneProperties UDatabase native disk archive'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail?1:0);
