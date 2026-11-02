#!/usr/bin/env php
<?php
/** UE1 UPrimitive source-valid archive FBox/FSphere with old FPlane gates. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $v):string {
 $negative=$v<0;$v=abs($v);$h=($v&63)|($negative?128:0);$v>>=6;
 if(!$v)return chr($h);$s=chr($h|64);
 do{$b=$v&127;$v>>=7;$s.=chr($b|($v?128:0));}while($v);
 return $s;
};
$build=static function(int $version,string $native)use($compact):string {
 $names=['None','Primitive','TestPrimitive','Engine','Class'];
 $summarySize=$version>=68?56:44;$nb='';
 foreach($names as $name){$nb.=($version>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);}
 $im=$compact(3).$compact(4).($version<50?$compact(0):pack('V',0)).$compact(1);
 $prefix=$version<40?pack('V2',0,0):'';
 if($version<47){$prefix.=$compact(0).$compact(0).str_repeat("\0",8);}
 $body=$prefix."\x00".($version<57?"\x00":"").($version<58?"\x00":"").$native;
 $no=$summarySize;$io=$no+strlen($nb);$eo=$io+strlen($im);
 $ex=$compact(-1).$compact(0).($version<50?'':pack('V',0)).$compact(2).pack('V',0)
   .$compact(strlen($body)).$compact(512);
 $sum=pack('V9',0x9e2a83c1,$version,0,count($names),$no,1,$eo,1,$io);
 $sum.=$version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
 $tables=$sum.$nb.$im.$ex;
 if(strlen($tables)>512)throw new RuntimeException('UE1 fixture overlap');
 return $tables.str_repeat("\0",512-strlen($tables)).$body;
};
$native=static function(int $ver,int $valid=1):string {
 $box=pack('g6',1.5,-2.25,3.0,11.0,22.5,-30.0).chr($valid);
 if($ver<=40){return $box.pack('g4',7,8,9,10);}
 if($ver<=61){return $box.pack('g3',7,8,9);}
 return $box.pack('g4',7,8,9,10);
};
$paths=[];$tests=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$tests,&$failed):void {
 $tests[$name]=$ok;if(!$ok)$failed[]=$name;
};
$parse=static function(int $ver,string $payload)use($build,&$paths):array{
 $p=tempnam(sys_get_temp_dir(),'ue1prim');
 if(!$p)throw new RuntimeException('Fixture unavailable');
 $paths[]=$p;file_put_contents($p,$build($ver,$payload));
 $pkg=new Package($p);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Package layout invalid');
 $tag=Tags::inspect($p,$pkg,0);
 if(($tag['status']??null)!=='tag_envelope_decoded')throw new RuntimeException('No UObject tagged envelope');
 return [Native::inspect($p,$pkg,0,$tag),$tag];
};
$bad=static function(int $ver,string $payload)use($parse):bool {
 try{$parse($ver,$payload);return false;}catch(RuntimeException){return true;}
};
try{
 [$a,$tag]=$parse(35,$native(35));
 $check('v35_FBox_legacy_plane',$a['status']==='native_primitive_record_complete'
   &&$a['source_serialized_base_bytes']===41
   &&$a['bounding_box']['min']['x']===1.5
   &&$a['bounding_box']['max']['z']===-30.0
   &&$a['old_legacy_plane']['w']===10.0
   &&$a['bounding_sphere']['w']===0.0);
 $check('v35_UObject_old_framing',$tag['legacy_database']['count']===0
   &&$tag['legacy_state_name']['text']==='None'
   &&$tag['serialized_state_frame']!==null);
 [$a]=$parse(40,$native(40));
 $check('v40_old_plane_branch',$a['status']==='native_primitive_record_complete'
   &&$a['old_legacy_plane']['y']===8.0);
 [$b]=$parse(41,$native(41));
 $check('v41_FSphere_FVector_only',$b['status']==='native_primitive_record_complete'
   &&$b['source_serialized_base_bytes']===37
   &&$b['old_legacy_plane']===null
   &&$b['bounding_sphere']['x']===7.0
   &&$b['bounding_sphere']['w']===null);
 [$c]=$parse(61,$native(61));
 $check('v61_FSphere_vector_only',$c['status']==='native_primitive_record_complete'
    &&$c['source_serialized_base_bytes']===37);
 [$e]=$parse(62,$native(62));
 $check('v62_full_sphere_FPlane',$e['status']==='native_primitive_record_complete'
    &&$e['source_serialized_base_bytes']===41&&$e['bounding_sphere']['w']===10.0);
 [$f]=$parse(69,$native(69));
 $check('v69_FBox_IsValid_byte',$f['status']==='native_primitive_record_complete'
   &&$f['bounding_box']['is_valid']===1&&$f['bounding_sphere']['y']===8.0);
 [$f]=$parse(69,$native(69,0));
 $check('v69_invalid_box_flag_retained_not_invented',$f['status']==='native_primitive_record_complete'
   &&$f['bounding_box']['is_valid']===0);
 [$e]=$parse(69,$native(69,5));
 $check('FBox_raw_flag_preserved',$e['bounding_box']['is_valid']===5);
 [$ext]=$parse(69,$native(69)."\xff");
 $check('extension_never_claimed_complete',$ext['status']==='native_primitive_has_extension'
    &&$ext['extension_bytes']===1);
 $check('reject_short_v35_plane',$bad(35,substr($native(35),0,-1)));
 $check('reject_short_v41_sphere',$bad(41,substr($native(41),0,-1)));
 $check('reject_short_v69_sphere',$bad(69,substr($native(69),0,-1)));
 $check('reject_short_FBox',$bad(69,substr($native(69),0,24)));
 $check('reject_empty_native',$bad(50,''));
}finally{foreach($paths as $p){@unlink($p);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($tests)-count($failed),'total'=>count($tests),
 'failed'=>$failed,'cases'=>$tests,
 'epic_source'=>['v1.200 Engine/Src/UnPrim.cpp:22-35, Engine/Inc/UnMath.h FVector/FBox/FPlane',
 'UT99 v400 Engine/Src/UnPrim.cpp:22-29, Core/Inc/UnMath.h FBox/FSphere (Ver<=61)']],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
