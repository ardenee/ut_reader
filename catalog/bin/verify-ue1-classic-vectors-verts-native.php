#!/usr/bin/env php
<?php
/**
 * C98 Unreal v1.200 UVectors/UVerts full-package wire-layout acceptance.
 * T99 later restructured model arrays; do not infer native class inheritance.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Pkg;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $v):string{
 $neg=$v<0;$v=abs($v);$b=($v&63)|($neg?128:0);$v>>=6;
 if(!$v)return chr($b);
 $s=chr($b|64);
 do{$next=$v&127;$v>>=7;$s.=chr($next|($v?128:0));}while($v);
 return $s;
};
$paths=[];$checks=[];$failed=[];
$check=static function(string $key,bool $pass)use(&$checks,&$failed):void{
 $checks[$key]=$pass;if(!$pass)$failed[]=$key;
};
$build=static function(string $kind,int $version,string $native,
    array $opt=[])use($compact):string{
 $names=['None','Vectors','Verts','Sample','Engine','Class'];$nameData='';
 foreach($names as $name){
  $nameData.=($version>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);
 }
 $import=$compact(4).$compact(5).($version<50?$compact(0):pack('V',0))
         .$compact($kind==='Vectors'?1:2);
 $pre=$version<40?pack('V2',($opt['legacyNum']??0)&0xffffffff,
   ($opt['legacyMax']??0)&0xffffffff):'';
 if($version<47){$pre.=$compact(0).$compact(0).str_repeat("\0",8);}
 $body=$pre."\0".($version<57?"\0":"").($version<58?"\0":"").$native;
 $headerBytes=$version>=68?56:44;$namesAt=$headerBytes;
 $importsAt=$namesAt+strlen($nameData);$exportsAt=$importsAt+strlen($import);
 $export=$compact(-1).$compact(0).($version<50?'':pack('V',0))
   .$compact(3).pack('V',0).$compact(strlen($body)).$compact(512);
 $header=pack('V9',0x9e2a83c1,$version,0,count($names),$namesAt,1,
   $exportsAt,1,$importsAt)
   .($version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $meta=$header.$nameData.$import.$export;
 if(strlen($meta)>512)throw new RuntimeException('Synthetic package metadata overflow');
 return $meta.str_repeat("\0",512-strlen($meta)).$body;
};
$parse=static function(string $kind,int $ver,string $native,
    array $opt=[])use($build,&$paths):array{
 $file=tempnam(sys_get_temp_dir(),'o06db');
 if($file===false)throw new RuntimeException('Temporary fixture unavailable');
 $paths[]=$file;file_put_contents($file,$build($kind,$ver,$native,$opt));
 $pkg=new Pkg($file);
 if($pkg->validatePackage()!==[])throw new RuntimeException('UE1 fixture package invalid');
 $tags=Tags::inspect($file,$pkg,0);
 if(($tags['status']??'')!=='tag_envelope_decoded'){
  throw new RuntimeException('UE1 native fixture property envelope invalid');
 }
 return [Native::inspect($file,$pkg,0,$tags),$tags];
};
$reject=static function(string $kind,int $ver,string $native,
    array $opt=[])use($parse):bool{
 try{$parse($kind,$ver,$native,$opt);return false;}
 catch(RuntimeException){return true;}
};
$vector=static fn(float $x,float $y,float $z):string=>pack('g3',$x,$y,$z);
$verts=static fn(array $list) => implode('',array_map(
 static fn(array $pair):string=>$compact($pair[0]).$compact($pair[1]),$list));
$database=static function(int $version,string $kind,array $records,
    array $opt=[])use($vector,$verts,$compact):array{
 $data=$kind==='Vectors'
  ?implode('',array_map(static fn($xyz)=>$vector(...$xyz),$records))
  :$verts($records).$compact((int)($opt['shared']??0));
 $num=$opt['num']??count($records);$max=$opt['max']??count($records);
 if($version<40){
  $prefix=['legacyNum'=>$num,'legacyMax'=>$max];
 }else{
  $data=pack('V2',$num&0xffffffff,$max&0xffffffff).$data;
  $prefix=[];
 }
 return [$data,$prefix];
};
try{
 $source='L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]';
 $hdr=@file_get_contents($source.'\Engine\Inc\UnObj.h');
 $impl=@file_get_contents($source.'\Engine\Src\UnModel.cpp');
 $math=@file_get_contents($source.'\Engine\Inc\UnMath.h');
 $check('epic_uvectors_udatabase_inheritance',is_string($hdr)
   &&str_contains($hdr,'class ENGINE_API UVectors : public UDatabase')
   &&str_contains($hdr,'DECLARE_DB_CLASS(UVectors,UDatabase,FVector)'));
 $check('epic_uverts_udatabase_inheritance',is_string($hdr)
   &&str_contains($hdr,'class ENGINE_API UVerts : public UDatabase')
   &&str_contains($hdr,'DECLARE_DB_CLASS(UVerts,UDatabase,FVert)'));
 $check('epic_record_operator_compact_vertex_side',is_string($hdr)
   &&str_contains($hdr,'AR_INDEX(Vert.pVertex)')
   &&str_contains($hdr,'AR_INDEX(Vert.iSide)'));
 $check('epic_shared_side_suffix',is_string($hdr)
   &&str_contains($hdr,'Ar << AR_INDEX(NumSharedSides);'));
 $check('epic_database_serializer_implementations',is_string($impl)
   &&str_contains($impl,'IMPLEMENT_DB_CLASS(UVectors);')
   &&str_contains($impl,'IMPLEMENT_DB_CLASS(UVerts);')
   &&str_contains($impl,'DbNum = GObj.GetTempNum()'));
 $check('epic_vector_three_float_order',is_string($math)
   &&str_contains($math,'return Ar << V.X << V.Y << V.Z;'));
 foreach([35,39,40,50,61] as $ver){
  foreach(['Vectors','Verts'] as $kind){
   $records=$kind==='Vectors'?[[1.5,-2.25,3.5],[0.0,9.0,-11.0]]:
     [[0,-1],[16383,2]];
   [$wire,$prefix]=$database($ver,$kind,$records,['max'=>7,'shared'=>5]);
   [$rec,$tags]=$parse($kind,$ver,$wire,$prefix);
   $expectedStatus='native_'.strtolower($kind).'_database_complete';
   $check('native_records_'.$kind.'_v'.$ver,
     $rec['status']===$expectedStatus && $rec['count']===2
     &&$rec['capacity']===7 && $rec['extension_bytes']===0
     &&count($rec['records_preview'])===2);
   $check('precise_values_'.$kind.'_v'.$ver,
     $kind==='Vectors'
     ?($rec['records_preview'][0]['x']===1.5
       &&$rec['records_preview'][0]['y']===-2.25
       &&$rec['records_preview'][1]['z']===-11.0)
     :($rec['records_preview'][0]['side_index']===-1
       &&$rec['records_preview'][1]['vertex_index']===16383
       &&$rec['num_shared_sides']===5));
   $check('legacy_uobject_count_location_'.$kind.'_v'.$ver,
     $rec['counts_in_uobject_prefix']===($ver<40)
     &&($ver>=40||$tags['legacy_database']['count']===2));
   [$empty,$prefix]=$database($ver,$kind,[],['max'=>0,'shared'=>0]);
   [$parsed]=$parse($kind,$ver,$empty,$prefix);
   $check('empty_'.$kind.'_v'.$ver,$parsed['status']===$expectedStatus
     &&$parsed['count']===0&&count($parsed['records_preview'])===0);
   [$trailing,$prefix]=$database($ver,$kind,[],['shared'=>0]);
   [$extension]=$parse($kind,$ver,$trailing."\xfe",$prefix);
   $check('extension_not_complete_'.$kind.'_v'.$ver,
     $extension['status']==='native_'.strtolower($kind).'_database_has_extension'
     &&$extension['extension_bytes']===1);
  }
 }
 foreach(['Vectors','Verts'] as $kind){
  $payload=$kind==='Vectors'?$vector(1.0,2.0,3.0):$compact(0).$compact(-1).$compact(0);
  [$raw,$prefix]=$database(35,$kind,[[0,0,0]],['num'=>-1,'max'=>1]);
  $check('negative_legacy_count_'.$kind,$reject($kind,35,$raw,$prefix));
  [$raw,$prefix]=$database(35,$kind,[[0,0,0]],['num'=>1,'max'=>0]);
  $check('legacy_capacity_smaller_than_count_'.$kind,$reject($kind,35,$raw,$prefix));
  [$raw,$prefix]=$database(50,$kind,[[0,0,0]],['num'=>-1,'max'=>1]);
  $check('negative_native_count_'.$kind,$reject($kind,50,$raw,$prefix));
  [$raw,$prefix]=$database(50,$kind,[[0,0,0]],['num'=>1000,'max'=>1000]);
  $check('native_count_bounds_'.$kind,$reject($kind,50,$raw,$prefix));
  [$raw,$prefix]=$database(61,$kind,[[0,0,0]]);
  $check('truncated_payload_'.$kind,$reject($kind,61,substr($raw,0,-1),$prefix));
  [$pre62]=$parse($kind,62,'');
  $check('no_unsupported_ut99_class_inference_'.$kind,
    $pre62['status']==='source_c98_database_class_version_unverified');
 }
 [$raw,$prefix]=$database(61,'Verts',[[0,0,0]]);
 $check('verts_negative_shared_sides_rejected',
   $reject('Verts',61,substr($raw,0,-1).$compact(-1),$prefix));
 [$raw,$prefix]=$database(61,'Verts',array_fill(0,20,[0,0]),['shared'=>20]);
 [$large]=$parse('Verts',61,$raw,$prefix);
 $check('verts_preview_bounded',$large['status']==='native_verts_database_complete'
   &&$large['count']===20&&count($large['records_preview'])===16);
 [$raw,$prefix]=$database(61,'Vectors',array_fill(0,20,[1.0,2.0,3.0]));
 [$large]=$parse('Vectors',61,$raw,$prefix);
 $check('vectors_preview_bounded',$large['status']==='native_vectors_database_complete'
   &&$large['count']===20&&count($large['records_preview'])===16);
}finally{foreach($paths as $path){@unlink($path);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failed'=>$failed,
 'notes'=>'Epic v1.200 inherited UDatabase; v62+ explicit source unverified; source assertions and synthetic package fixtures'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
