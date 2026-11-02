#!/usr/bin/env php
<?php
/** Original v0.867 UTexture typed archive header; payload stays O07. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileTextureHeaderReader as Texture;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate as Gate;
$checks=[];$failed=[];
$check=static function(string $name,bool $yes)use(&$checks,&$failed):void{
 $checks[$name]=$yes;if(!$yes)$failed[]=$name;
};
$make=static function(int $v,bool $may=false):array{
 $names="Texture\0".pack('V',0)."TestTexture\0".pack('V',0);
 $nameOff=56;
 $mips=pack('V12',0,...array_fill(0,11,0xffffffff));
 $native=pack('V2',16,16)
    .($may?pack('V',1):pack('V5',1,0,0,0,0))
    .($may?pack('g4',1.0,0.5,0.95,0.25):pack('g4',1.0,0.5,0.25,0.95))
    .pack('V2',0,1)
    .pack('V2',0x1234,0x5678)
    .pack('C2',2,2)
    .pack('V3',4,4,1)
    .pack('V',0x77)
    .pack('C12',10,20,30,40,1,2,3,4,100,110,120,130)
    .$mips;
 if(strlen($native)!==($may?122:138))throw new RuntimeException('Wrong original source texture header length');
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $header=$stack.$native;
 $hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($header);
 $bytes=str_repeat("\xaa",16);
 $objOff=$dataOff+strlen($bytes);
 $objects=pack('vvVV',2,1,0,$hOff).pack('V4',0,$dataOff,strlen($header),16);
 $summary=pack('a32V6',"Unrealfile\x1A",$v,1,2,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$header.$bytes.$objects,
   'native'=>$hOff+16,'table'=>$objOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1tex97');
if(!is_string($tmp))throw new RuntimeException('Cannot write fixture');
try{
 foreach([22,23] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $rows=Texture::read($tmp,true,Gate::JULY)['texture_headers'];$r=$rows[0]??[];
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
  $check("v{$v}_source_lineage_inferred_staging",
    ($s['status']??null)==='native_texture_header_decoded'
    && ($s['source_lineage']??null)===Gate::JULY
    && ($s['source_lineage_inferred_from_native_header_bytes']??false)===true
    && ($s['mip_offsets'][0]??null)===0
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['native']+8,4));
  try{Texture::read($tmp,true,Gate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_resmap_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',16),$f['native']+90,4));
  try{Texture::read($tmp,true,Gate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_mip_offset_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',15),$f['native'],4));
  try{Texture::read($tmp,true,Gate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_database_num_not_equal_data_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',150),$f['table']+20,4));
  $short=Texture::read($tmp,false,Gate::JULY)['texture_headers'][0]??[];
  try{Texture::read($tmp,true,Gate::JULY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_short_header_never_verifies",
    ($short['status']??null)==='incomplete_original_texture_header'&&$reject);

  // Both original Epic builds write version 23. Source must select the
  // correct field count and coefficient order before anything is decoded.
  file_put_contents($tmp,$f['bytes']);
  $unknown=Texture::read($tmp,false)['texture_headers'][0]??[];
  $check("v{$v}_exact_july_native_length_selects_layout",
    ($unknown['status']??null)==='native_texture_header_decoded'
    &&($unknown['source_lineage']??null)===Gate::JULY
    &&($unknown['source_lineage_inferred_from_native_header_bytes']??false)===true
    &&($unknown['native_header_bytes']??null)===138);
  $inferred=Texture::read($tmp,true)['texture_headers'][0]??[];
  $check("v{$v}_strict_july_auto_decode",
    count($inferred['object_references']??[])===7);
  $check("v{$v}_july_diffuse_specular_reflectivity_friction_order",
    abs(($r['reflectivity_coefficient']??-1)-0.25)<0.00001
    &&abs(($r['friction_coefficient']??-1)-0.95)<0.00001
    &&!isset($r['object_references']['OtherField']));
  $may=$make($v,true);
  file_put_contents($tmp,$may['bytes']);
  $m=Texture::read($tmp,true,Gate::MAY)['texture_headers'][0]??[];
  $check("v{$v}_may_exact_122B_native",
    ($m['status']??null)==='native_texture_header_decoded'
    &&($m['native_header_bytes']??null)===122
    &&($m['active_pixel_bytes']??null)===16
    &&($m['source_lineage']??null)===Gate::MAY
    &&($m['u_size']??null)===4
    &&count($m['mip_offsets']??[])===12
    &&($m['mip_offsets'][0]??null)===0);
  $check("v{$v}_may_palette_without_july_only_refs",
    count($m['object_references']??[])===3
    &&($m['object_references']['Palette']['raw_one_based_ref']??null)===1
    &&($m['object_references']['HitSound']['raw_one_based_ref']??null)===1
    &&!isset($m['object_references']['BumpMap'])
    &&!isset($m['object_references']['AnimNext']));
  $check("v{$v}_may_source_reversed_float_fields",
    abs(($m['reflectivity_coefficient']??-1)-0.25)<0.00001
    &&abs(($m['friction_coefficient']??-1)-0.95)<0.00001
    &&($m['polygon_flags']??null)===0x1234
    &&($m['colors_rgba_source_bytes']['mip_zero']??null)===[10,20,30,40]);
  $unknown=Texture::read($tmp,false)['texture_headers'][0]??[];
  $check("v{$v}_exact_may_native_length_selects_layout",
    ($unknown['status']??null)==='native_texture_header_decoded'
    &&($unknown['source_lineage']??null)===Gate::MAY
    &&($unknown['source_lineage_inferred_from_native_header_bytes']??false)===true
    &&($unknown['native_header_bytes']??null)===122);
  $inferred=Texture::read($tmp,true)['texture_headers'][0]??[];
  $check("v{$v}_strict_may_auto_decode",
    count($inferred['object_references']??[])===3);
  // Native May mip offsets begin 16 bytes before the July offsets.
  file_put_contents($tmp,substr_replace($may['bytes'],pack('V',16),$may['native']+74,4));
  try{Texture::read($tmp,true,Gate::MAY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_may_invalid_mip_offset_rejected",$reject);
  file_put_contents($tmp,substr_replace($may['bytes'],pack('V',2),$may['native']+8,4));
  try{Texture::read($tmp,true,Gate::MAY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_may_invalid_palette_ref_rejected",$reject);
  file_put_contents($tmp,substr_replace($may['bytes'],pack('V',132),$may['table']+20,4));
  $short=Texture::read($tmp,false,Gate::MAY)['texture_headers'][0]??[];
  try{Texture::read($tmp,true,Gate::MAY);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_may_short_header_rejected",
    ($short['status']??null)==='incomplete_original_texture_header'&&$reject);
  // Header sizes which do not match an Epic-supported May/July on-disk
  // layout stay unresolved; guessing the next-largest branch misleads.
  foreach([['july',$f,156],['may',$may,140]] as [$label,$fx,$size]){
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',$size),$fx['table']+20,4));
    $unknown=Texture::read($tmp,false)['texture_headers'][0]??[];
    $check("v{$v}_{$label}_unknown_extension_unresolved",
        ($unknown['status']??null)==='source_1997_texture_lineage_required'
        &&!isset($unknown['mip_offsets']));
    try{Texture::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$v}_{$label}_unknown_extension_strict_rejected",$reject);
  }
 }
 try{Texture::read($tmp,false,'made_up_1997_branch');$bad=false;}
 catch(InvalidArgumentException){$bad=true;}
 $check('unknown_source_lineage_rejected',$bad);
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
