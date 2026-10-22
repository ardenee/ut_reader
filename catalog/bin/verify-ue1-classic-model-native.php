#!/usr/bin/env php
<?php
/**
 * UE1 UModel inherited UPrimitive + historical referenced BSP/tables;
 * UT99 v400 inline geometry zero/nonempty branch classification.
 * Fixtures are actual UE1 package headers/imports/exports/object payloads.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$h=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($h);$s=chr($h|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);return $s;
};
$build=static function(int $ver,string $native)use($idx):string{
 $names=['None','Model','Brush','Engine','Class','MyModel'];
 $nb='';foreach($names as $name){$nb.=($ver>=64?$idx(strlen($name)+1):'').$name."\0".pack('V',0);}
 $im=$idx(3).$idx(4).($ver<50?$idx(0):pack('V',0)).$idx(1);
 $old=$ver<40?pack('V2',0,0):'';
 if($ver<47){$old.=$idx(0).$idx(0).str_repeat("\0",8);}
 $bytes=$old."\x00".($ver<57?"\x00":"").($ver<58?"\x00":"").$native;
 $base=$ver>=68?56:44;$no=$base;$io=$no+strlen($nb);$eo=$io+strlen($im);
 $ex=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(2).pack('V',0)
     .$idx(strlen($bytes)).$idx(512);
 $summary=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io)
    .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $table=$summary.$nb.$im.$ex;
 if(strlen($table)>512)throw new RuntimeException('Fixture table > payload start');
 return $table.str_repeat("\0",512-strlen($table)).$bytes;
};
$primitive=static fn(int $ver):string=>pack('g6',0,0,0,100,100,100).chr(1)
    .($ver>40&&$ver<=61?pack('g3',1,2,3):pack('g4',1,2,3,4));
$lightmap=static function(int $ver)use($idx):string{
 $out=pack('V',123);
 if($ver<=37){
    return $out.pack('g2',2.5,3.5).$idx(3).$idx(4)
        .pack('V2',8,9).chr(6);
 }
 $out.=pack('g3',2.5,3.5,4.5).$idx(3).$idx(4);
 if($ver<53){$out.=pack('g',0.75);}
 else {$out.=pack('g2',0.75,1.25);}
 return $out.pack('V',9);
};
$model=static function(int $ver,array $options=[])use($idx,$primitive,$lightmap):string{
 $bytes=$primitive($ver);
 if($ver<=61){
   foreach($options['refs']??[0,0,0,0,0,-1] as $r){$bytes.=$idx($r);}
 }else{
   // TTransArray/FVector, Points, Nodes, Surfs, Verts.
   $vectors=$options['vectors']??1;
   $bytes.=$idx($vectors).str_repeat(pack('g3',1,2,3),max(0,$vectors));
   $points=$options['points']??0;
   $bytes.=$idx($points).str_repeat(pack('g3',4,5,6),max(0,$points));
   $nodes=$options['nodes']??0;
   $bytes.=$idx($nodes);
   if($nodes!==0){return $bytes."\xaa";}
   $bytes.=$idx($options['surfs']??0);
   if(($options['surfs']??0)!==0){return $bytes."\xbb";}
   $bytes.=$idx($options['verts']??0);
   if(($options['verts']??0)!==0){return $bytes."\xcc";}
   $zoneCount=$options['zones']??0;
   $bytes.=pack('V2',2,$zoneCount&0xffffffff);
   if($zoneCount!==0){return $bytes."\xdd";}
   $bytes.=$idx(-1);
 }
 $lightCount=$options['lightCount']??1;
 $bytes.=$idx($lightCount);
 if($lightCount>0){
   $bytes.=$lightmap($ver);
   if($lightCount>1){$bytes.=str_repeat($lightmap($ver),$lightCount-1);}
 }
 $lightData=$options['lightBits']??"\x11\x22";
 $bytes.=$idx(strlen($lightData)).$lightData;
 $bounds=$options['bounds']??1;
 $bytes.=$idx($bounds);
 if($bounds>0){$bytes.=str_repeat(pack('g6',0,1,2,3,4,5).chr(1),$bounds);}
 $hulls=$options['hulls']??[5,-9];
 $bytes.=$idx(count($hulls));foreach($hulls as $h){$bytes.=pack('V',$h&0xffffffff);}
 $leaves=$options['leaves']??1;
 $bytes.=$idx($leaves);
 if($leaves>0){$bytes.=str_repeat($idx(2).$idx(-1).$idx(4)."\x10\x20\x30\x40\x50\x60\x70\x80",$leaves);}
 $lights=$options['lights']??[-1];
 $bytes.=$idx(count($lights));foreach($lights as $r){$bytes.=$idx($r);}
 if($ver<=61){$bytes.=$idx($options['leafZone']??0).$idx($options['leafLeaf']??0);}
 if($ver<=41){$bytes.=pack('V3',1,33,2);}
 elseif($ver<=42){$bytes.=chr(1).chr(33).chr(2);}
 elseif($ver<=43){$bytes.=chr(1).chr(2);}
 else {$bytes.=pack('V2',1,2);}
 return $bytes.($options['extension']??'');
};
$paths=[];$checks=[];$failed=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failed):void{
 $checks[$name]=$pass;if(!$pass)$failed[]=$name;
};
$parse=static function(int $ver,string $raw)use($build,&$paths):array{
 $file=tempnam(sys_get_temp_dir(),'ue1model');
 if(!$file)throw new RuntimeException('Fixture file creation failed');
 $paths[]=$file;file_put_contents($file,$build($ver,$raw));
 $pkg=new Package($file);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Invalid UE1 header fixture');
 $props=Tags::inspect($file,$pkg,0);
 if(($props['status']??'')!=='tag_envelope_decoded')throw new RuntimeException('UObject tags not decoded');
 return [Native::inspect($file,$pkg,0,$props),$props];
};
$reject=static function(int $ver,string $raw)use($parse):bool{
 try{$parse($ver,$raw);return false;}catch(RuntimeException){return true;}
};
try{
 foreach([35,41,42,43,44,50,53,61,62,69] as $ver){
   [$r]=$parse($ver,$model($ver));$m=$r['model'];
   $check('complete_model_v'.$ver,($r['status']??'')==='native_model_record_complete'
       &&$r['extension_bytes']===0
       &&$m['root_outside']===1&&$m['linked']===2
       &&count($m['light_map'])===1&&$m['light_map'][0]['data_offset']===123
       &&$m['light_map'][0]['u_clamp']===3
       &&$m['light_bits']['count']===2
       &&count($m['bounds'])===1&&count($m['leaves'])===1
       &&$m['leaves'][0]['visible_zones_hex']==='1020304050607080'
       &&$m['leaf_hulls']===[5,-9]
       &&$m['lights']===[-1]);
 }
 [$r]=$parse(35,$model(35));
 $check('v35_LightMap_old_serializer',
   $r['model']['light_map'][0]['old_mesh_shift']===6
   &&$r['model']['light_map'][0]['u_scale']===8);
 [$r]=$parse(50,$model(50));
 $check('v50_single_lightmap_scale',
   $r['model']['light_map'][0]['u_scale']===0.75
   &&$r['model']['light_map'][0]['v_scale']===0.75);
 $check('lightbits_real_serial_bytes_digest',
   $r['model']['light_bits']['sha256']===hash('sha256',"\x11\x22")
   &&$r['model']['light_bits']['count']===2);
 [$r]=$parse(53,$model(53));
 $check('v53_separate_lightmap_scales',
   $r['model']['light_map'][0]['v_scale']===1.25);
 [$r]=$parse(69,$model(69));
 $check('modern_vector_geometry_and_polys',
   count($r['model']['vectors'])===1&&$r['model']['vectors'][0]['x']===1.0
   &&$r['model']['num_shared_sides']===2
   &&$r['model']['polys_reference']===-1);
 [$r]=$parse(69,$model(69,['nodes'=>1]));
 $check('modern_nonempty_nodes_explicitly_incomplete',
   $r['status']==='native_model_inline_geometry_unimplemented'
   &&$r['model']['nodes_count']===1&&$r['unparsed_bytes']===1);
 [$r]=$parse(69,$model(69,['zones'=>1]));
 $check('modern_nonempty_zones_explicitly_incomplete',
   $r['status']==='native_model_zone_properties_unimplemented'
   &&$r['model']['num_zones']===1);
 [$r]=$parse(61,$model(61,['refs'=>[-1,1,0,0,0,1],'leafZone'=>-1,'leafLeaf'=>1]));
 $check('legacy_all_references_source_order',
   $r['status']==='native_model_record_complete'
   &&$r['model']['vectors_reference']===-1
   &&$r['model']['points_reference']===1
   &&$r['model']['polys_reference']===1
   &&$r['model']['legacy_leaf_zone_reference']===-1
   &&$r['model']['legacy_leaf_leaf_reference']===1);
 [$r]=$parse(69,$model(69,['bounds'=>0,'hulls'=>[],'leaves'=>0,'lights'=>[],'lightCount'=>0,'lightBits'=>'']));
 $check('zero_arrays_complete',$r['status']==='native_model_record_complete'
   &&$r['model']['light_map']===[]&&$r['model']['leaves']===[]);
 [$r]=$parse(50,$model(50,['extension'=>"\x77"]));
 $check('extension_explicitly_incomplete',
   $r['status']==='native_model_has_extension'&&$r['extension_bytes']===1);
 $check('reject_outside_UModel_reference',$reject(50,$model(50,['refs'=>[0,2,0,0,0,0]])));
 $check('reject_negative_lightmap_count',$reject(50,$primitive(50).str_repeat("\0",6).$idx(-1)));
 $check('reject_bad_light_reference',$reject(50,$model(50,['lights'=>[-2]])));
 $check('reject_invalid_legacy_leaf_reference',$reject(50,$model(50,['leafZone'=>2])));
 $check('reject_negative_num_zones',$reject(69,$model(69,['zones'=>-1])));
 $check('reject_excess_num_zones',$reject(69,$model(69,['zones'=>65])));
 $check('reject_truncated_model',$reject(35,substr($model(35),0,-1)));
 $check('reject_truncated_light_bits',$reject(50,$primitive(50).str_repeat("\0",6).$idx(0).$idx(10)."A"));
}finally{foreach($paths as $f){@unlink($f);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failed'=>$failed,'cases'=>$checks,
 'sources'=>['Unreal v1.200 Engine/Src/UnModel.cpp:291-332, Engine/Inc/UnObj.h FLeaf/FLightMapIndex',
 'UT99 v400 Engine/Src/UnModel.cpp:182-239, Engine/Inc/UnObj.h FLeaf/FLightMapIndex']],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
