#!/usr/bin/env php
<?php
/** Epic v1.200 UPolys::SerializeData FPoly archive (v<45 extra BYTEs). */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$b=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($b);
 $s=chr($b|64);do{$next=$n&127;$n>>=7;$s.=chr($next|($n?128:0));}while($n);
 return $s;
};
$vec=static fn(float $x,float $y,float $z):string=>pack('g3',$x,$y,$z);
$poly=static function(int $ver,int $numVertices=2,int $flags=6,
 int $actor=-1,int $texture=1,int $name=3)use($idx,$vec):string{
 $raw=$idx($numVertices)
   .$vec(1,2,3).$vec(0,0,1).$vec(2,0,0).$vec(0,3,0);
 for($j=0;$j<max(0,min($numVertices,16));$j++){
  $raw.=$vec($j,-$j,5.0);
 }
 $raw.=pack('V',$flags).$idx($actor).$idx($texture).$idx($name)
   .$idx(300).$idx(-1).pack('v2',-123&0xffff,456);
 if($ver<45){$raw.="\xab\xcd";}
 return $raw;
};
$disk=static function(int $ver,int $count,int $max,string $raw):array{
 return $ver<40?[$raw,['legacyNum'=>$count,'legacyMax'=>$max]]:
   [pack('V2',$count&0xffffffff,$max&0xffffffff).$raw,[]];
};
$paths=[];$tests=[];$fail=[];
$check=static function(string $n,bool $ok)use(&$tests,&$fail):void{
 $tests[$n]=$ok;if(!$ok)$fail[]=$n;
};
$build=static function(int $ver,string $raw,array $opts)use($idx):string{
 $names=['None','Polys','Example','PolyName','Core','Class'];$nmap='';
 foreach($names as $n){$nmap.=($ver>=64?$idx(strlen($n)+1):'').$n."\0".pack('V',0);}
 $imp=$idx(4).$idx(5).($ver<50?$idx(0):pack('V',0)).$idx(1);
 $prefix=$ver<40?pack('V2',($opts['legacyNum']??0)&0xffffffff,
   ($opts['legacyMax']??0)&0xffffffff):'';
 if($ver<47){$prefix.=$idx(0).$idx(0).str_repeat("\0",8);}
 $body=$prefix."\0".($ver<57?"\0":"").($ver<58?"\0":"").$raw;
 $no=$ver>=68?56:44;$io=$no+strlen($nmap);$eo=$io+strlen($imp);
 $ex=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(2)
   .pack('V',0).$idx(strlen($body)).$idx(512);
 $header=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io)
   .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $meta=$header.$nmap.$imp.$ex;
 if(strlen($meta)>512)throw new RuntimeException('FPoly package metadata overflow');
 return $meta.str_repeat("\0",512-strlen($meta)).$body;
};
$parse=static function(int $ver,string $raw,array $opts=[])use($build,&$paths):array{
 $file=tempnam(sys_get_temp_dir(),'o06po');
 if(!$file)throw new RuntimeException('Cannot allocate FPoly fixture');
 $paths[]=$file;file_put_contents($file,$build($ver,$raw,$opts));
 $pkg=new Package($file);
 if($pkg->validatePackage()!==[])throw new RuntimeException('FPoly invalid package');
 $tags=Tags::inspect($file,$pkg,0);
 if(($tags['status']??'')!=='tag_envelope_decoded'){
  throw new RuntimeException('FPoly bad property stream');
 }
 return Native::inspect($file,$pkg,0,$tags);
};
$reject=static function(int $ver,string $raw,array $opts=[])use($parse):bool{
 try{$parse($ver,$raw,$opts);return false;}catch(RuntimeException){return true;}
};
try{
 $source='L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]';
 $hdr=@file_get_contents($source.'\Engine\Inc\UnObj.h');
 $impl=@file_get_contents($source.'\Engine\Src\UnFPoly.cpp');
 $check('source_fpoly_count_and_four_vectors',is_string($hdr)
   &&str_contains($hdr,'Ar << AR_INDEX(Poly.NumVertices);')
   &&str_contains($hdr,'Ar << Poly.Base << Poly.Normal << Poly.TextureU << Poly.TextureV;')
   &&str_contains($hdr,'for( int i=0; i<Poly.NumVertices; i++ )'));
 $check('source_masked_translucent_load_correction',is_string($hdr)
   &&str_contains($hdr,'(Poly.PolyFlags & PF_Masked)')
   &&str_contains($hdr,'(Poly.PolyFlags & PF_Translucent)')
   &&str_contains($hdr,'Poly.PolyFlags &= ~PF_Masked;'));
 $check('source_old_version_extra_two_bytes',is_string($hdr)
   &&str_contains($hdr,'if( Ar.Ver()<45 )')
   &&str_contains($hdr,'Ar << A << A;'));
 $check('source_database_registration',is_string($hdr)
   &&str_contains($hdr,'DECLARE_DB_CLASS(UPolys,UDatabase,FPoly)')
   &&is_string($impl)&&str_contains($impl,'IMPLEMENT_DB_CLASS(UPolys);'));
 foreach([35,39,40,44,45,50,61] as $ver){
  [$wire,$prefix]=$disk($ver,2,7,$poly($ver).$poly($ver,1,0x1));
  $parsed=$parse($ver,$wire,$prefix);
  $check('complete_polys_v'.$ver,
    $parsed['status']==='native_polys_database_complete'
    &&$parsed['count']===2&&$parsed['capacity']===7
    &&count($parsed['polys_preview'])===2);
  $check('source_four_vectors_and_vertex_array_v'.$ver,
    $parsed['polys_preview'][0]['base']===[1.0,2.0,3.0]
    &&$parsed['polys_preview'][0]['normal']===[0.0,0.0,1.0]
    &&$parsed['polys_preview'][0]['num_vertices']===2
    &&count($parsed['polys_preview'][0]['vertices'])===2
    &&$parsed['polys_preview'][0]['vertices'][1]===[1.0,-1.0,5.0]);
  $check('source_flags_and_epic_mask_fix_v'.$ver,
    $parsed['polys_preview'][0]['poly_flags_serialized']===6
    &&$parsed['polys_preview'][0]['poly_flags_after_epic_load']===4
    &&$parsed['polys_preview'][1]['poly_flags_after_epic_load']===1);
  $check('references_and_signed_pan_v'.$ver,
    $parsed['polys_preview'][0]['actor_reference']===-1
    &&$parsed['polys_preview'][0]['texture_reference']===1
    &&$parsed['polys_preview'][0]['item_name_index']===3
    &&$parsed['polys_preview'][0]['link_index']===300
    &&$parsed['polys_preview'][0]['brush_poly_index']===-1
    &&$parsed['polys_preview'][0]['pan_u']===-123
    &&$parsed['polys_preview'][0]['pan_v']===456);
  $check('source_old_bytes_version_v'.$ver,
    $parsed['polys_preview'][0]['legacy_two_bytes']===
      ($ver<45?[171,205]:null)
    &&$parsed['counts_in_uobject_prefix']===($ver<40));
  [$wire,$prefix]=$disk($ver,0,0,'');
  $empty=$parse($ver,$wire,$prefix);
  $check('empty_polys_v'.$ver,$empty['status']==='native_polys_database_complete'
    &&$empty['count']===0);
  $tail=$parse($ver,$wire."\xef",$prefix);
  $check('unknown_tail_incomplete_v'.$ver,
    $tail['status']==='native_polys_database_has_extension'
    &&$tail['extension_bytes']===1);
  [$wire,$prefix]=$disk($ver,1,1,$poly($ver,17));
  $check('source_max_sixteen_vertices_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$poly($ver,1,0,0,-2));
  $check('invalid_texture_ref_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$poly($ver,1,0,-2));
  $check('invalid_actor_ref_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,$poly($ver,1,0,0,0,99));
  $check('invalid_item_name_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,0,$poly($ver));
  $check('capacity_too_small_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1000,1000,$poly($ver));
  $check('count_exceeds_native_file_v'.$ver,$reject($ver,$wire,$prefix));
  [$wire,$prefix]=$disk($ver,1,1,substr($poly($ver),0,-1));
  $check('truncated_source_tail_v'.$ver,$reject($ver,$wire,$prefix));
 }
 [$wire,$prefix]=$disk(61,18,18,str_repeat($poly(61,0),18));
 $large=$parse(61,$wire,$prefix);
 $check('bounded_preview_18_polys',
   $large['status']==='native_polys_database_complete'
   &&$large['count']===18&&count($large['polys_preview'])===16);
 $unverified=$parse(62,'');
 $check('ut99_unproven_class_not_guessed',
   $unverified['status']==='source_c98_polys_version_unverified');
}finally{foreach($paths as $p){@unlink($p);}}
echo json_encode(['ok'=>count($fail)===0,'total'=>count($tests),
 'passed'=>count($tests)-count($fail),'failed'=>$fail,
 'scope'=>'Epic v1.200 FPoly native disk reader, pre45 extra bytes and engine masked flag fix'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail?1:0);
