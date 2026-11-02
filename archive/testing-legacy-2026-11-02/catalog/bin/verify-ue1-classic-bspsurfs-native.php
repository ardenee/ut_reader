#!/usr/bin/env php
<?php
/** Native Epic v1.200 UBspSurfs / FBspSurf complete record fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $v):string{
 $neg=$v<0;$v=abs($v);$a=($v&63)|($neg?128:0);$v>>=6;
 if(!$v)return chr($a);$s=chr($a|64);
 do{$b=$v&127;$v>>=7;$s.=chr($b|($v?128:0));}while($v);
 return $s;
};
$paths=[];$checks=[];$fails=[];
$check=static function(string $key,bool $ok)use(&$checks,&$fails):void{
 $checks[$key]=$ok;if(!$ok)$fails[]=$key;
};
$surf=static function(int $tex=0,int $actor=-1,int $flags=0x80000103,
    int $panU=-320,int $panV=255)use($idx):string{
 return $idx($tex).pack('V',$flags)
   .$idx(0).$idx(100).$idx(-1).$idx(200).$idx(300).$idx(400)
   .pack('v2',$panU&0xffff,$panV&0xffff).$idx($actor);
};
$disk=static function(int $ver,int $num,int $max,string $records)
 :array{
 return $ver<40
  ?[$records,['legacyNum'=>$num,'legacyMax'=>$max]]
  :[pack('V2',$num&0xffffffff,$max&0xffffffff).$records,[]];
};
$build=static function(int $ver,string $payload,array $opt)use($idx):string{
 $names=['None','BspSurfs','Example','Core','Class'];$namesRaw='';
 foreach($names as $n){
  $namesRaw.=($ver>=64?$idx(strlen($n)+1):'').$n."\0".pack('V',0);
 }
 $imp=$idx(3).$idx(4).($ver<50?$idx(0):pack('V',0)).$idx(1);
 $prefix=$ver<40?pack('V2',($opt['legacyNum']??0)&0xffffffff,
   ($opt['legacyMax']??0)&0xffffffff):'';
 if($ver<47){$prefix.=$idx(0).$idx(0).str_repeat("\0",8);}
 $bytes=$prefix."\0".($ver<57?"\0":"").($ver<58?"\0":"").$payload;
 $namesOff=$ver>=68?56:44;
 $importsOff=$namesOff+strlen($namesRaw);$exportsOff=$importsOff+strlen($imp);
 $exp=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(2)
   .pack('V',0).$idx(strlen($bytes)).$idx(512);
 $header=pack('V9',0x9e2a83c1,$ver,0,count($names),$namesOff,1,$exportsOff,1,$importsOff)
   .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $metadata=$header.$namesRaw.$imp.$exp;
 if(strlen($metadata)>512)throw new RuntimeException('BSP fixture metadata too large');
 return $metadata.str_repeat("\0",512-strlen($metadata)).$bytes;
};
$parse=static function(int $ver,string $wire,array $opt=[])use($build,&$paths):array{
 $file=tempnam(sys_get_temp_dir(),'o06bs');
 if(!$file)throw new RuntimeException('Cannot allocate fixture');
 $paths[]=$file;file_put_contents($file,$build($ver,$wire,$opt));
 $pkg=new Package($file);
 if($pkg->validatePackage()!==[])throw new RuntimeException('BSP fixture invalid package');
 $tags=Tags::inspect($file,$pkg,0);
 if(($tags['status']??'')!=='tag_envelope_decoded'){
  throw new RuntimeException('BSP fixture has bad UObject tags');
 }
 return Native::inspect($file,$pkg,0,$tags);
};
$reject=static function(int $ver,string $wire,array $opt=[])use($parse):bool{
 try{$parse($ver,$wire,$opt);return false;}catch(RuntimeException){return true;}
};
try{
 $c98='L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]';
 $hdr=@file_get_contents($c98.'\Engine\Inc\UnObj.h');
 $impl=@file_get_contents($c98.'\Engine\Src\UnModel.cpp');
 $check('epic_fbsp_surf_operator',is_string($hdr)
   &&str_contains($hdr,'FArchive& operator<< (FArchive &Ar, FBspSurf &Surf)')
   &&str_contains($hdr,'Ar << Surf.PolyFlags << AR_INDEX(Surf.pBase)')
   &&str_contains($hdr,'Ar << Surf.PanU << Surf.PanV;'));
 $check('epic_six_compact_indices',is_string($hdr)
   &&str_contains($hdr,'AR_INDEX(Surf.vTextureU)')
   &&str_contains($hdr,'AR_INDEX(Surf.iBrushPoly)'));
 $check('epic_bsp_surf_database_macro',is_string($hdr)
   &&str_contains($hdr,'DECLARE_DB_CLASS(UBspSurfs,UDatabase,FBspSurf)')
   &&is_string($impl)
   &&str_contains($impl,'IMPLEMENT_DB_CLASS(UBspSurfs);'));
 foreach([35,39,40,50,61] as $ver){
  [$wire,$pre]=$disk($ver,2,5,$surf().$surf(1,1,0x00000002,32767,-32768));
  $rec=$parse($ver,$wire,$pre);
  $check('complete_two_surfaces_v'.$ver,
    $rec['status']==='native_bspsurfs_database_complete'
    &&$rec['count']===2&&$rec['capacity']===5
    &&count($rec['surfaces_preview'])===2);
  $check('flags_and_compact_indices_v'.$ver,
    $rec['surfaces_preview'][0]['poly_flags']===0x80000103
    &&$rec['surfaces_preview'][0]['indices']['v_normal']===100
    &&$rec['surfaces_preview'][0]['indices']['v_texture_u']===-1
    &&$rec['surfaces_preview'][0]['indices']['brush_poly']===400);
  $check('signed_pan_and_actor_v'.$ver,
    $rec['surfaces_preview'][0]['pan_u']===-320
    &&$rec['surfaces_preview'][0]['actor_reference']===-1
    &&$rec['surfaces_preview'][1]['pan_u']===32767
    &&$rec['surfaces_preview'][1]['pan_v']===-32768);
  $check('counts_in_prefix_as_epic_v'.$ver,
    $rec['counts_in_uobject_prefix']===($ver<40));
  [$wire,$pre]=$disk($ver,0,0,'');
  $empty=$parse($ver,$wire,$pre);
  $check('empty_surfs_v'.$ver,$empty['count']===0
    &&$empty['status']==='native_bspsurfs_database_complete');
  $tail=$parse($ver,$wire."\xdd",$pre);
  $check('tail_not_falsely_complete_v'.$ver,
    $tail['status']==='native_bspsurfs_database_has_extension'
    &&$tail['extension_bytes']===1);
  [$wire,$pre]=$disk($ver,1,1,$surf(-2,0));
  $check('bad_texture_ref_v'.$ver,$reject($ver,$wire,$pre));
  [$wire,$pre]=$disk($ver,1,1,$surf(0,-2));
  $check('bad_actor_ref_v'.$ver,$reject($ver,$wire,$pre));
  [$wire,$pre]=$disk($ver,1,0,$surf());
  $check('capacity_below_count_v'.$ver,$reject($ver,$wire,$pre));
  [$wire,$pre]=$disk($ver,1000,1000,$surf());
  $check('huge_count_with_short_payload_v'.$ver,$reject($ver,$wire,$pre));
  [$wire,$pre]=$disk($ver,1,1,substr($surf(),0,-1));
  $check('truncated_actor_v'.$ver,$reject($ver,$wire,$pre));
 }
 [$wire,$pre]=$disk(61,18,18,str_repeat($surf(),18));
 $large=$parse(61,$wire,$pre);
 $check('bounded_preview_18_surfaces',
   $large['count']===18&&count($large['surfaces_preview'])===16
   &&$large['status']==='native_bspsurfs_database_complete');
 $t99Model=@file_get_contents('L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Engine\Src\UnModel.cpp');
 $check('ut99_old_bsp_surfs_loader_source',
   is_string($t99Model)
   &&str_contains($t99Model,'class ENGINE_API UBspSurfs : public UObject')
   &&str_contains($t99Model,'Ar << DbNum << DbMax;')
   &&str_contains($t99Model,'Ar << Element(i);'));
 $check('ut99_surf_load_clears_transient_not_file_decals',
   is_string($t99Model)
   &&str_contains($t99Model,'Surf.PolyFlags &= ~PF_Transient;')
   &&str_contains($t99Model,'if( !Ar.IsLoading() && !Ar.IsSaving() )')
   &&str_contains($t99Model,'Ar << Surf.Decals;'));
 foreach([62,68,69] as $ver){
  [$wire,$pre]=$disk($ver,2,5,
    $surf(0,-1,0x10000006).$surf(1,1,0x10000001));
  $rec=$parse($ver,$wire,$pre);
  $check('ut99_surfs_loader_v'.$ver,
    $rec['status']==='native_bspsurfs_database_complete'
    &&$rec['archive_family']==='UT99-v400-legacy-database'
    &&$rec['count']===2&&$rec['capacity']===5);
  $check('ut99_surfs_transient_clear_v'.$ver,
    $rec['surfaces_preview'][0]['poly_flags']===0x10000006
    &&$rec['surfaces_preview'][0]['poly_flags_after_epic_load']===6
    &&$rec['surfaces_preview'][1]['poly_flags_after_epic_load']===1);
  $check('ut99_surfs_references_and_pan_v'.$ver,
    $rec['surfaces_preview'][0]['actor_reference']===-1
    &&$rec['surfaces_preview'][1]['texture_reference']===1
    &&$rec['surfaces_preview'][0]['pan_u']===-320);
  [$wire,$pre]=$disk($ver,1,1,substr($surf(),0,-1));
  $check('ut99_surfs_truncated_actor_v'.$ver,$reject($ver,$wire,$pre));
  [$wire,$pre]=$disk($ver,1,0,$surf());
  $check('ut99_surfs_invalid_capacity_v'.$ver,$reject($ver,$wire,$pre));
  [$wire,$pre]=$disk($ver,1,1,$surf(-2,0));
  $check('ut99_surfs_invalid_texture_v'.$ver,$reject($ver,$wire,$pre));
 }

}finally{foreach($paths as $p){@unlink($p);}}
echo json_encode(['ok'=>!$fails,'total'=>count($checks),
 'passed'=>count($checks)-count($fails),'failed'=>$fails,
 'scope'=>'Epic v1.200 FBspSurf disk operator and UDatabase inherited serialization'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($fails?1:0);
