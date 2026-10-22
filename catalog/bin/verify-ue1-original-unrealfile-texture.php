#!/usr/bin/env php
<?php
/** Original v0.867 UTexture typed archive header; payload stays O07. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileTextureHeaderReader as Texture;
$checks=[];$failed=[];
$check=static function(string $name,bool $yes)use(&$checks,&$failed):void{
 $checks[$name]=$yes;if(!$yes)$failed[]=$name;
};
$make=static function(int $v):array{
 $names="Texture\0".pack('V',0)."TestTexture\0".pack('V',0);
 $nameOff=56;
 $mips=pack('V12',0,...array_fill(0,11,0xffffffff));
 $native=pack('V2',16,16)
    .pack('V5',1,0,0,0,0)
    .pack('g4',1.0,0.5,0.25,0.95)
    .pack('V2',0,1)
    .pack('V2',0x1234,0x5678)
    .pack('C2',2,2)
    .pack('V3',4,4,1)
    .pack('V',0x77)
    .pack('C12',10,20,30,40,1,2,3,4,100,110,120,130)
    .$mips;
 if(strlen($native)!==138)throw new RuntimeException('Wrong v0.867 texture source header length');
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $header=$stack.$native;
 $hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($header);
 $bytes=str_repeat("\xaa",16);
 $objOff=$dataOff+strlen($bytes);
 $objects=pack('vvVV',2,1,0,$hOff).pack('V4',0,$dataOff,154,16);
 $summary=pack('a32V6',"Unrealfile\x1A",$v,1,2,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$header.$bytes.$objects,
   'native'=>$hOff+16,'table'=>$objOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1tex97');
if(!is_string($tmp))throw new RuntimeException('Cannot write fixture');
try{
 foreach([22,23] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $rows=Texture::read($tmp,true)['texture_headers'];$r=$rows[0]??[];
  $check("v{$v}_native_dimensions_mip",
   count($rows)===1
   && ($r['status']??null)==='native_texture_header_decoded'
   && ($r['native_header_bytes']??null)===138
   && ($r['active_pixel_bytes']??null)===16
   && ($r['pixel_capacity_bytes']??null)===16
   && ($r['u_bits']??null)===2
   && ($r['v_bits']??null)===2
   && ($r['u_size']??null)===4
   && ($r['v_size']??null)===4
   && ($r['mip_offsets'][0]??null)===0
   && ($r['mip_offsets'][11]??null)===0xffffffff);
  $check("v{$v}_references_flags_colors",
   ($r['object_references']['BumpMap']['raw_one_based_ref']??null)===1
   && ($r['object_references']['HitSound']['raw_one_based_ref']??null)===1
   && ($r['diffuse_coefficient']??null)===1.0
   && ($r['specular_coefficient']??null)===0.5
   && ($r['polygon_flags']??null)===0x1234
   && ($r['texture_flags']??null)===0x5678
   && ($r['colors_rgba_source_bytes']['mip_zero']??null)===[10,20,30,40]
   && ($r['texture_pixels_decoded']??true)===false
   && ($r['runtime_mipmaps_fixed']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
    $tmp,['format'=>'early_unrealfile','version'=>$v]
  );
  $s=$stage['native_metadata']['unrealfile_texture_headers']['texture_headers'][0]??[];
  $check("v{$v}_source_fields_staged_unverified",
    ($s['mip_offsets'][0]??null)===0
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['native']+8,4));
  try{Texture::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_resmap_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',16),$f['native']+90,4));
  try{Texture::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_mip_offset_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',15),$f['native'],4));
  try{Texture::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_database_num_not_equal_data_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',150),$f['table']+20,4));
  $short=Texture::read($tmp,false)['texture_headers'][0]??[];
  try{Texture::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_short_header_never_verifies",
    ($short['status']??null)==='incomplete_original_texture_header'&&$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
