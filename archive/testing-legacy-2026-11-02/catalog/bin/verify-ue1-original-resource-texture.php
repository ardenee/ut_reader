#!/usr/bin/env php
<?php
/** v9/v10 source-defined 1996 UTexture derived header fixture. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceTextureReader as Texture;
$tests=[];$fail=[];
$check=static function(string $n,bool $ok)use(&$tests,&$fail):void{$tests[$n]=$ok;if(!$ok)$fail[]=$n;};
$fixture=static function(int $version):array{
    $prefix="Unreal Resource\x1A\0";
    $names=pack('a32V','FAMILY',0x800);
    $nameOff=strlen($prefix);$dataOff=$nameOff+strlen($names);
    $pixel=str_repeat("\x12",128);
    $exportOff=$dataOff+strlen($pixel);
    $export=pack('a32VVV','NativeTex',1,5,248);
    $headerOff=$exportOff+strlen($export);
    $extra=str_repeat("\0",172);
    $poke=static function(int $offset,string $value)use(&$extra):void{
        $extra=substr_replace($extra,$value,$offset,strlen($value));
    };
    $poke(16,pack('v2',1,0));
    $poke(24,pack('g3',0.5,0.25,1.0));
    $poke(44,pack('V',0x55));
    $poke(84,pack('V4',16,8,128,1));
    $poke(100,"\x10\x20\x30\x40");
    $poke(104,pack('V8',0,64,0xffffffff,0xffffffff,0xffffffff,0xffffffff,0xffffffff,0xffffffff));
    $poke(136,"\x04\x03");
    $header=pack('V2a32V9',0,5,'NativeTex',0,0,1,0xffffffff,0,$dataOff,128,0,0).$extra;
    if(strlen($header)!==248)throw new RuntimeException('Incorrect original texture layout');
    $summary=pack('V9',$version,1,0,1,$nameOff,0,$exportOff,$headerOff,248)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$names.$pixel.$export.$header.'[SUMMARY]'.$summary,'header'=>$headerOff,'export'=>$exportOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1tex96');
if(!is_string($tmp))throw new RuntimeException('No temporary path');
try{
 foreach([9,10] as $v){
    $fx=$fixture($v);file_put_contents($tmp,$fx['bytes']);
    $record=Texture::read($tmp,true)['textures'][0]??[];
    $check("v{$v}_derived_texture_fields",
        ($record['status']??null)==='native_texture_header_decoded'
        && $record['serialized_header_bytes']===248
        && $record['width']===16
        && $record['height']===8
        && $record['data_size']===128
        && $record['color_bytes']===1
        && $record['bits_u']===4&&$record['bits_v']===3
        && $record['mip_offsets']===[0,64,null,null,null,null,null,null]);
    $check("v{$v}_source_name_and_pointer_status",
        ($record['name_indices']['family']??null)===1
        && $record['diffuse_coeff']===0.5
        && $record['polygon_flags']===0x55
        && $record['data_size_matches_source']===true
        && $record['actual_resource_fixups_evaluated']===false);
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $tmp,['format'=>'early_unreal_resource','version'=>$v]
    );
    $stageTex=$stage['native_metadata']['resource_texture_headers']['textures'][0]??[];
    $check("v{$v}_unverified_staging_source_values",
        ($stageTex['width']??null)===16&&$stage['parse_complete']===false);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',129),$fx['header']+76+104,4));
    try{Texture::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
    $check("v{$v}_out_of_bounds_native_mip_rejected",$rejected);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',127),$fx['header']+76+92,4));
    try{Texture::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
    $check("v{$v}_querysize_and_filedata_disagreement_rejected",$rejected);
    file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',76),$fx['export']+40,4));
    $status=Texture::read($tmp,false)['textures'][0]['status']??null;
    try{Texture::read($tmp,true);$rejected=false;}catch(RuntimeException){$rejected=true;}
    $check("v{$v}_short_derived_header_not_accepted",
        $status==='incomplete_native_texture_header'&&$rejected);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($tests)-count($fail),
    'total'=>count($tests),'failures'=>$fail],JSON_PRETTY_PRINT),PHP_EOL;
exit($fail===[]?0:1);
